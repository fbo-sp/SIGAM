<?php

namespace App\Services\Exams;

use App\Models\ClassGroup;
use App\Models\DojoTeacherAssignment;
use App\Models\MartialArt;
use App\Models\ProgressionSetting;
use App\Models\QuizQuestion;
use App\Models\Rank;
use App\Models\RankExam;
use App\Models\RankExamGroup;
use App\Models\RankExamItem;
use App\Models\RankExamParticipant;
use App\Models\Student;
use App\Models\StudentMartialArt;
use App\Models\TeacherAssociationMembership;
use App\Models\TeacherProfile;
use App\Services\AuditService;
use App\Services\Progression\CurriculumService;
use App\Services\Progression\StudentProgressService;
use App\Services\Progression\TechniqueCatalogService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Exames de graduação, etapa 1 (proposta aprovada por Fabio em 30/10/2026): criar o exame, escolher participantes
 * (aptos já marcados; quem não está apto entra com motivo), montar o conteúdo por faixa de destino a partir do
 * currículo (novo e elevado marcados, herdado desmarcado), justificar item que nenhuma aula abordou e abrir.
 */
class ExamService
{
    public const THEORY_GROUP = 'Teoria';

    /** @var array<int, array<int, string>> */
    private array $paths = [];

    public function __construct(
        private readonly AuditService $audit,
        private readonly ExamCandidates $candidates,
        private readonly ExamCoverageService $coverage,
        private readonly CurriculumService $curriculum,
        private readonly StudentProgressService $progress,
        private readonly TechniqueCatalogService $catalog,
    ) {}

    /** Opções do formulário de dados do exame no alcance do contexto. */
    public function formOptions(ExamContext $context): array
    {
        $associationId = $context->association->id;
        $dojos = $context->dojo ? collect([$context->dojo]) : $context->association->dojos()->where('status', 'active')->orderBy('name')->get();
        $classes = ClassGroup::query()->where('association_id', $associationId)->where('status', 'active')->whereIn('dojo_id', $dojos->pluck('id'))
            ->with('dojo:id,name')->orderBy('name')->get();
        $today = today()->toDateString();
        $students = Student::query()->where('association_id', $associationId)->where('status', 'active')
            ->when($context->dojo, fn (Builder $query) => $query->whereHas('dojoHistory', fn (Builder $history) => $history->where('dojo_id', $context->dojo->id)
                ->whereDate('start_date', '<=', $today)->where(fn (Builder $end) => $end->whereNull('end_date')->orWhereDate('end_date', '>=', $today))))
            ->with('person:id,full_name,social_name')->get()->sortBy(fn (Student $student) => mb_strtolower($student->person->full_name))->values();

        return [
            'martialArts' => MartialArt::query()->where('association_id', $associationId)->where('status', 'active')->orderBy('name')->get(),
            'dojos' => $dojos,
            'classes' => $classes,
            'students' => $students,
            'teachers' => $this->teacherOptions($context),
            'scopes' => $context->dojo ? array_diff_key(RankExam::SCOPES, ['association' => true]) : RankExam::SCOPES,
            'defaultQuestions' => ProgressionSetting::for($associationId)->quiz_question_count,
        ];
    }

    /** @return Collection<int, TeacherProfile> */
    public function teacherOptions(ExamContext $context): Collection
    {
        $query = TeacherProfile::query()->where('status', 'active')->with('person:id,full_name,social_name');
        if ($context->dojo) {
            $query->whereIn('id', DojoTeacherAssignment::query()->select('teacher_profile_id')->where('dojo_id', $context->dojo->id)->whereNull('revoked_at')
                ->whereDate('start_date', '<=', today())->where(fn (Builder $end) => $end->whereNull('end_date')->orWhereDate('end_date', '>=', today())));
        } else {
            $query->whereIn('id', TeacherAssociationMembership::query()->select('teacher_profile_id')->where('association_id', $context->association->id)->where('status', 'active'));
        }

        return $query->get()->sortBy(fn (TeacherProfile $teacher) => mb_strtolower($teacher->person->full_name))->values();
    }

    public function create(ExamContext $context, array $data): RankExam
    {
        abort_unless($context->canManage(), 403);
        $attributes = $this->attributes($context, $data);

        return DB::transaction(function () use ($context, $data, $attributes): RankExam {
            $exam = RankExam::query()->create($attributes + ['association_id' => $context->association->id, 'status' => 'draft', 'created_by_user_id' => $context->user->id]);
            $exam->examiners()->sync($this->examinerIds($context, $data));
            $this->audit->record('rank_exam.created', 'created', $exam, null, $exam->only(['name', 'scope_type', 'dojo_id', 'class_group_id', 'student_id', 'starts_on', 'ends_on', 'minimum_average', 'quiz_enabled']), ['association_id' => $exam->association_id]);

            return $exam;
        });
    }

    public function update(ExamContext $context, RankExam $exam, array $data): RankExam
    {
        abort_unless($context->canManage(), 403);
        $this->ensureEditable($exam);
        $attributes = $this->attributes($context, $data, $exam);

        return DB::transaction(function () use ($context, $exam, $data, $attributes): RankExam {
            $exam = RankExam::query()->lockForUpdate()->findOrFail($exam->id);
            $before = $exam->only(array_keys($attributes));
            $exam->fill($attributes)->save();
            $exam->examiners()->sync($this->examinerIds($context, $data));
            $this->syncQuiz($exam);
            $this->audit->record('rank_exam.updated', 'updated', $exam, $before, $exam->only(array_keys($attributes)), ['association_id' => $exam->association_id]);

            return $exam;
        });
    }

    /**
     * Alunos do nível do exame e os já inscritos, com a situação de aptidão e a inscrição atual.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function participantRows(RankExam $exam): Collection
    {
        $current = $exam->participants()->get()->keyBy('student_id');
        $rows = $this->candidates->forExam($exam);
        $missing = $current->keys()->diff($rows->pluck('student.id'));
        if ($missing->isNotEmpty()) {
            $students = Student::query()->whereKey($missing)->with(['person:id,full_name,social_name,birth_date,photo_path', 'martialArts' => fn ($link) => $link->where('martial_art_id', $exam->martial_art_id)])->get();
            $rows = $rows->concat($this->candidates->evaluate($exam, $students)->map(fn (array $row) => $row + ['outside' => true]));
        }
        $others = RankExamParticipant::query()->whereIn('student_id', $rows->pluck('student.id'))->where('status', '!=', 'removed')->where('rank_exam_id', '!=', $exam->id)
            ->whereHas('exam', fn (Builder $other) => $other->where('martial_art_id', $exam->martial_art_id)->whereIn('status', ['draft', 'open']))
            ->with('exam:id,name')->get()->keyBy('student_id');

        return $rows->map(fn (array $row) => $row + [
            'participant' => $current->get($row['student']->id),
            'included' => ($participant = $current->get($row['student']->id)) ? $participant->status !== 'removed' : false,
            'other_exam' => $others->get($row['student']->id)?->exam,
            'outside' => $row['outside'] ?? false,
        ]);
    }

    /**
     * Grava as inscrições: marcado entra (motivo obrigatório para quem não está apto); desmarcado sai sem apagar.
     *
     * @param  array<int|string, array{include?: mixed, reason?: ?string}>  $input
     */
    public function saveParticipants(ExamContext $context, RankExam $exam, array $input): array
    {
        abort_unless($context->canManage(), 403);
        $this->ensureEditable($exam);
        $rows = $this->participantRows($exam)->keyBy(fn (array $row) => $row['student']->id);
        $errors = [];
        foreach ($rows as $studentId => $row) {
            $include = filter_var($input[$studentId]['include'] ?? false, FILTER_VALIDATE_BOOL);
            $reason = trim((string) ($input[$studentId]['reason'] ?? ''));
            if (! $include) {
                continue;
            }
            if (! $row['eligible'] && mb_strlen($reason) < 5) {
                $errors["students.{$studentId}.reason"] = 'Escreva o motivo para incluir '.$this->name($row['student']).' sem estar apto.';
            }
            if ($row['other_exam'] && ! $row['included']) {
                $errors["students.{$studentId}.include"] = $this->name($row['student'])." já está no exame \"{$row['other_exam']->name}\".";
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return DB::transaction(function () use ($context, $exam, $input, $rows): array {
            $exam = RankExam::query()->lockForUpdate()->findOrFail($exam->id);
            $added = 0;
            $removed = 0;
            foreach ($rows as $studentId => $row) {
                $include = filter_var($input[$studentId]['include'] ?? false, FILTER_VALIDATE_BOOL);
                $participant = $row['participant'];
                if ($include) {
                    $reason = $row['eligible'] ? null : trim((string) ($input[$studentId]['reason'] ?? ''));
                    $values = [
                        'student_martial_art_id' => $row['link']->id, 'current_rank_id' => $row['current_rank']?->id, 'target_rank_id' => $row['target_rank']->id,
                        'eligible' => $row['eligible'], 'unmet' => $row['unmet'] ?: null, 'inclusion_reason' => $reason, 'status' => 'enrolled', 'removed_at' => null, 'removed_by_user_id' => null,
                    ];
                    if (! $participant) {
                        RankExamParticipant::query()->create($values + ['rank_exam_id' => $exam->id, 'student_id' => $studentId, 'added_by_user_id' => $context->user->id]);
                        $added++;
                    } elseif ($participant->status === 'removed') {
                        $participant->fill($values + ['added_by_user_id' => $context->user->id])->save();
                        $added++;
                    } else {
                        $participant->fill(['inclusion_reason' => $reason, 'eligible' => $row['eligible'], 'unmet' => $row['unmet'] ?: null])->save();
                    }
                } elseif ($participant && $participant->status !== 'removed') {
                    $participant->fill(['status' => 'removed', 'removed_at' => now(), 'removed_by_user_id' => $context->user->id])->save();
                    $removed++;
                }
            }
            $this->syncContent($exam);
            $total = $exam->enrolled()->count();
            $this->audit->record('rank_exam.participants_saved', 'updated', $exam, null, ['added' => $added, 'removed' => $removed, 'enrolled' => $total], ['association_id' => $exam->association_id]);

            return ['added' => $added, 'removed' => $removed, 'enrolled' => $total];
        });
    }

    /** Faixas de destino com inscritos, na ordem das graduações, com a quantidade de alunos. */
    public function ranks(RankExam $exam): Collection
    {
        $counts = $exam->enrolled()->selectRaw('target_rank_id, COUNT(*) as total')->groupBy('target_rank_id')->pluck('total', 'target_rank_id');

        return Rank::query()->whereKey($counts->keys())->orderBy('sort_order')->get()->map(fn (Rank $rank) => ['rank' => $rank, 'students' => (int) $counts[$rank->id]]);
    }

    /**
     * Conteúdo de uma faixa: grupos de nota com os itens, a cobertura de cada um e o banco do quiz.
     *
     * @return array{rank: Rank, students: int, sessions: int, groups: Collection, quiz: ?array, pending: int}
     */
    public function content(RankExam $exam, Rank $rank): array
    {
        $participants = $exam->enrolled()->where('target_rank_id', $rank->id)->get();
        $since = $this->candidates->since(StudentMartialArt::query()->whereIn('id', $participants->pluck('student_martial_art_id'))->get());
        $coverage = $this->coverage->forRank($exam, ExamCoverageService::sinceByStudent($participants, $since));
        $paths = $this->paths($exam);
        $groups = RankExamGroup::query()->where('rank_exam_id', $exam->id)->where('target_rank_id', $rank->id)->orderBy('sort_order')->orderBy('id')
            ->with(['items' => fn ($items) => $items->with('technique')])->get()
            ->map(fn (RankExamGroup $group) => ['group' => $group, 'items' => $group->items->map(function (RankExamItem $item) use ($coverage, $paths): array {
                $covered = $item->technique_item_id ? ($coverage['items'][$item->technique_item_id] ?? 0) : null;

                return [
                    'item' => $item, 'covered' => $covered, 'path' => $item->technique_item_id ? ($paths[$item->technique_item_id] ?? '') : '',
                    'tone' => $covered === null ? null : ExamCoverageService::tone($covered, $coverage['total']),
                    'needs_justification' => $item->kind === 'technique' && $item->selected && $covered === 0,
                ];
            })]);
        $pending = $groups->sum(fn (array $group) => $group['items']->filter(fn (array $row) => $row['needs_justification'] && blank($row['item']->justification))->count());

        return [
            'rank' => $rank, 'students' => $participants->count(), 'sessions' => $coverage['total'], 'groups' => $groups, 'pending' => $pending,
            'quiz' => $exam->quiz_enabled ? $this->quizBank($exam, $rank) : null,
        ];
    }

    /**
     * Grava a faixa: itens marcados, justificativas e (gestor) o grupo de cada item.
     *
     * @param  array<int|string, array{selected?: mixed, justification?: ?string, group?: mixed}>  $input
     */
    public function saveContent(ExamContext $context, RankExam $exam, Rank $rank, array $input): int
    {
        $this->ensureEditable($exam);
        $this->ensureRank($exam, $rank);
        $content = $this->content($exam, $rank);
        $rows = $content['groups']->flatMap(fn (array $group) => $group['items'])->keyBy(fn (array $row) => $row['item']->id);
        $groups = $content['groups']->pluck('group')->keyBy('id');
        $errors = [];
        $changes = [];
        foreach ($rows as $id => $row) {
            $item = $row['item'];
            $selected = $item->kind === 'quiz' ? $item->selected : filter_var($input[$id]['selected'] ?? false, FILTER_VALIDATE_BOOL);
            $justification = trim((string) ($input[$id]['justification'] ?? $item->justification ?? '')) ?: null;
            $groupId = $context->canManage() && isset($input[$id]['group']) ? (int) $input[$id]['group'] : $item->rank_exam_group_id;
            if (! $groups->has($groupId)) {
                $groupId = $item->rank_exam_group_id;
            }
            if ($selected && $item->kind === 'technique' && $row['covered'] === 0 && mb_strlen((string) $justification) < 5) {
                $errors["items.{$id}.justification"] = "Nenhuma aula abordou {$item->technique->name}. Justifique para manter ou desmarque.";
            }
            if ($selected !== $item->selected || $justification !== $item->justification || $groupId !== $item->rank_exam_group_id) {
                $changes[$id] = ['selected' => $selected, 'justification' => $justification, 'rank_exam_group_id' => $groupId];
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        DB::transaction(function () use ($context, $exam, $rank, $changes): void {
            RankExam::query()->lockForUpdate()->findOrFail($exam->id);
            foreach ($changes as $id => $values) {
                RankExamItem::query()->whereKey($id)->update($values + ['updated_by_user_id' => $context->user->id, 'updated_at' => now()]);
            }
            if ($changes !== []) {
                $this->audit->record('rank_exam.content_saved', 'updated', $exam, null, ['rank_id' => $rank->id, 'items' => $changes, 'by' => $context->level], ['association_id' => $exam->association_id]);
            }
        });

        return count($changes);
    }

    public function addGroup(ExamContext $context, RankExam $exam, Rank $rank, string $name): RankExamGroup
    {
        abort_unless($context->canManage(), 403);
        $this->ensureEditable($exam);
        $this->ensureRank($exam, $rank);
        $name = trim(preg_replace('/\s+/u', ' ', $name));
        if (RankExamGroup::query()->where('rank_exam_id', $exam->id)->where('target_rank_id', $rank->id)->where('name', $name)->exists()) {
            throw ValidationException::withMessages(['name' => 'Já existe um grupo com este nome nesta faixa.']);
        }
        $group = RankExamGroup::query()->create(['rank_exam_id' => $exam->id, 'target_rank_id' => $rank->id, 'name' => $name,
            'sort_order' => (int) RankExamGroup::query()->where('rank_exam_id', $exam->id)->where('target_rank_id', $rank->id)->max('sort_order') + 1]);
        $this->audit->record('rank_exam.group_created', 'created', $group, null, ['rank_id' => $rank->id, 'name' => $name], ['association_id' => $exam->association_id]);

        return $group;
    }

    /** O que impede abrir o exame; lista vazia libera. @return list<string> */
    public function openProblems(RankExam $exam): array
    {
        $problems = [];
        $ranks = $this->ranks($exam);
        if ($ranks->isEmpty()) {
            $problems[] = 'Inclua pelo menos um aluno.';
        }
        foreach ($ranks as $row) {
            $content = $this->content($exam, $row['rank']);
            $selected = $content['groups']->flatMap(fn (array $group) => $group['items'])->filter(fn (array $item) => $item['item']->selected);
            $label = $row['rank']->name;
            if ($selected->where('item.kind', 'technique')->isEmpty()) {
                $problems[] = "Faixa {$label}: marque pelo menos um item do conteúdo.";
            }
            if ($content['pending'] > 0) {
                $problems[] = "Faixa {$label}: {$content['pending']} item(ns) sem aula precisam de justificativa.";
            }
            if ($content['quiz'] && $content['quiz']['available'] === 0) {
                $problems[] = "Faixa {$label}: o banco do quiz não tem perguntas até esta faixa.";
            }
        }
        if ($exam->ends_on->lt(today())) {
            $problems[] = 'O período do exame já terminou. Ajuste as datas.';
        }

        return $problems;
    }

    public function open(ExamContext $context, RankExam $exam): RankExam
    {
        abort_unless($context->canManage(), 403);

        return DB::transaction(function () use ($context, $exam): RankExam {
            $exam = RankExam::query()->lockForUpdate()->findOrFail($exam->id);
            if ($exam->status !== 'draft') {
                throw ValidationException::withMessages(['exam' => 'Só um rascunho pode ser aberto.']);
            }
            if ($problems = $this->openProblems($exam)) {
                throw ValidationException::withMessages(['exam' => $problems]);
            }
            $exam->fill(['status' => 'open', 'opened_at' => now(), 'opened_by_user_id' => $context->user->id])->save();
            $this->audit->record('rank_exam.opened', 'updated', $exam, ['status' => 'draft'], ['status' => 'open', 'enrolled' => $exam->enrolled()->count()], ['association_id' => $exam->association_id]);

            return $exam;
        });
    }

    public function cancel(ExamContext $context, RankExam $exam, string $reason): RankExam
    {
        abort_unless($context->canManage(), 403);

        return DB::transaction(function () use ($context, $exam, $reason): RankExam {
            $exam = RankExam::query()->lockForUpdate()->findOrFail($exam->id);
            $this->ensureEditable($exam);
            $before = $exam->status;
            $exam->fill(['status' => 'cancelled', 'cancelled_at' => now(), 'cancelled_by_user_id' => $context->user->id, 'cancel_reason' => $reason])->save();
            $this->audit->record('rank_exam.cancelled', 'updated', $exam, ['status' => $before], ['status' => 'cancelled', 'reason' => $reason], ['association_id' => $exam->association_id]);

            return $exam;
        });
    }

    /** Cria grupos e itens das faixas de destino que ainda não têm conteúdo e acerta o item do quiz. */
    public function syncContent(RankExam $exam): void
    {
        $built = RankExamGroup::query()->where('rank_exam_id', $exam->id)->distinct()->pluck('target_rank_id')->flip();
        foreach ($this->ranks($exam) as $row) {
            if (! $built->has($row['rank']->id)) {
                $this->build($exam, $row['rank']);
            }
        }
        $this->syncQuiz($exam);
    }

    /** Monta o conteúdo da faixa pelo currículo efetivo: práticos agrupados pelo grupo do catálogo, teóricos em "Teoria". */
    private function build(RankExam $exam, Rank $rank): void
    {
        $exam->loadMissing('association', 'martialArt');
        $axes = $this->progress->axes($exam->association, $exam->martialArt);
        $rows = collect($this->curriculum->effective($exam->association, $rank)['rows']);
        $ordered = $rows->sortBy(fn (array $row) => $row['item']->knowledge_type === 'practical' ? 0 : 1, SORT_REGULAR)->values();
        $groups = [];
        $order = 0;
        foreach ($ordered as $index => $row) {
            $name = $row['item']->knowledge_type === 'practical' ? mb_substr($axes['axis'][$row['item']->id] ?? $row['item']->name, 0, 80) : self::THEORY_GROUP;
            $groups[$name] ??= RankExamGroup::query()->firstOrCreate(['rank_exam_id' => $exam->id, 'target_rank_id' => $rank->id, 'name' => $name], ['sort_order' => ++$order]);
            RankExamItem::query()->firstOrCreate(
                ['rank_exam_id' => $exam->id, 'target_rank_id' => $rank->id, 'kind' => 'technique', 'technique_item_id' => $row['item']->id],
                ['rank_exam_group_id' => $groups[$name]->id, 'required_level' => $row['level'], 'origin' => $row['origin'], 'selected' => $row['origin'] !== 'inherited', 'sort_order' => $index + 1],
            );
        }
    }

    /** O quiz entra como item de cada faixa (grupo Teoria por padrão) e fica marcado só quando o exame tem quiz. */
    private function syncQuiz(RankExam $exam): void
    {
        foreach (RankExamGroup::query()->where('rank_exam_id', $exam->id)->distinct()->pluck('target_rank_id') as $rankId) {
            $item = RankExamItem::query()->where('rank_exam_id', $exam->id)->where('target_rank_id', $rankId)->where('kind', 'quiz')->first();
            if (! $item && ! $exam->quiz_enabled) {
                continue;
            }
            if (! $item) {
                $group = RankExamGroup::query()->firstOrCreate(['rank_exam_id' => $exam->id, 'target_rank_id' => $rankId, 'name' => self::THEORY_GROUP],
                    ['sort_order' => (int) RankExamGroup::query()->where('rank_exam_id', $exam->id)->where('target_rank_id', $rankId)->max('sort_order') + 1]);
                RankExamItem::query()->create(['rank_exam_id' => $exam->id, 'target_rank_id' => $rankId, 'rank_exam_group_id' => $group->id, 'kind' => 'quiz', 'selected' => true, 'sort_order' => 999]);
            } elseif ($item->selected !== $exam->quiz_enabled) {
                $item->update(['selected' => $exam->quiz_enabled]);
            }
        }
    }

    /** Perguntas ativas (com resposta certa) da própria faixa e das anteriores, como no sorteio do quiz oficial. */
    private function quizBank(RankExam $exam, Rank $rank): array
    {
        $ranks = $this->candidates->ranks($exam->martial_art_id)->where('sort_order', '<=', $rank->sort_order);
        $counts = QuizQuestion::query()->where('association_id', $exam->association_id)->whereIn('rank_id', $ranks->pluck('id'))->where('status', 'active')
            ->whereHas('options', fn (Builder $option) => $option->where('is_correct', true))
            ->selectRaw('rank_id, COUNT(*) as total')->groupBy('rank_id')->pluck('total', 'rank_id');
        $own = (int) ($counts[$rank->id] ?? 0);
        $available = (int) $counts->sum();

        return ['own' => $own, 'earlier' => $available - $own, 'available' => $available, 'wanted' => (int) $exam->quiz_question_count];
    }

    /** @return array<string, mixed> atributos validados do exame no alcance do contexto */
    private function attributes(ExamContext $context, array $data, ?RankExam $exam = null): array
    {
        $associationId = $context->association->id;
        $locked = $exam && $exam->participants()->exists();
        $scope = $locked ? $exam->scope_type : $data['scope_type'];
        $martialArtId = $locked ? $exam->martial_art_id : (int) $data['martial_art_id'];
        abort_unless(MartialArt::query()->where('association_id', $associationId)->whereKey($martialArtId)->exists(), 422);
        $values = ['dojo_id' => null, 'class_group_id' => null, 'student_id' => null];
        if ($locked) {
            $values = $exam->only(['dojo_id', 'class_group_id', 'student_id']);
        } elseif ($context->dojo && $scope === 'association') {
            throw ValidationException::withMessages(['scope_type' => 'O dojo cria exames do dojo, de uma turma ou de um aluno.']);
        } elseif ($scope === 'dojo') {
            $dojoId = $context->dojo?->id ?? (int) ($data['dojo_id'] ?? 0);
            if (! $context->association->dojos()->whereKey($dojoId)->exists()) {
                throw ValidationException::withMessages(['dojo_id' => 'Escolha o dojo.']);
            }
            $values['dojo_id'] = $dojoId;
        } elseif ($scope === 'class') {
            $class = ClassGroup::query()->where('association_id', $associationId)->whereKey((int) ($data['class_group_id'] ?? 0))
                ->when($context->dojo, fn (Builder $query) => $query->where('dojo_id', $context->dojo->id))->first();
            if (! $class) {
                throw ValidationException::withMessages(['class_group_id' => 'Escolha a turma.']);
            }
            if ($class->martial_art_id !== $martialArtId) {
                throw ValidationException::withMessages(['class_group_id' => 'A turma é de outra modalidade.']);
            }
            $values['class_group_id'] = $class->id;
            $values['dojo_id'] = $class->dojo_id;
        } elseif ($scope === 'student') {
            $student = collect($this->formOptions($context)['students'])->firstWhere('id', (int) ($data['student_id'] ?? 0));
            if (! $student) {
                throw ValidationException::withMessages(['student_id' => 'Escolha o aluno.']);
            }
            $values['student_id'] = $student->id;
            $values['dojo_id'] = $context->dojo?->id ?? $student->dojoHistory()->where('is_primary', true)->whereNull('end_date')->value('dojo_id')
                ?? $student->primary_dojo_id;
        }
        $quiz = (bool) ($data['quiz_enabled'] ?? false);

        return $values + [
            'martial_art_id' => $martialArtId, 'scope_type' => $scope, 'name' => trim($data['name']),
            'justification' => $scope === 'student' ? trim((string) ($data['justification'] ?? '')) : null,
            'starts_on' => $data['starts_on'], 'ends_on' => $data['ends_on'], 'minimum_average' => $data['minimum_average'],
            'class_teachers_examine' => (bool) ($data['class_teachers_examine'] ?? false), 'quiz_enabled' => $quiz,
            'quiz_question_count' => $quiz ? (int) $data['quiz_question_count'] : null,
            'quiz_time_limit_minutes' => $quiz && ! empty($data['quiz_time_limit_minutes']) ? (int) $data['quiz_time_limit_minutes'] : null,
        ];
    }

    /** @return list<int> */
    private function examinerIds(ExamContext $context, array $data): array
    {
        $allowed = $this->teacherOptions($context)->pluck('id')->flip();

        return collect($data['examiner_ids'] ?? [])->map(fn ($id) => (int) $id)->filter(fn (int $id) => $allowed->has($id))->unique()->values()->all();
    }

    /** @return array<int, string> caminho no catálogo, por item */
    private function paths(RankExam $exam): array
    {
        $exam->loadMissing('association', 'martialArt');

        return $this->paths[$exam->id] ??= $this->catalog->paths($this->catalog->items($exam->association, $exam->martialArt));
    }

    private function ensureEditable(RankExam $exam): void
    {
        if (! $exam->isEditable()) {
            throw ValidationException::withMessages(['exam' => 'Este exame não pode mais ser alterado.']);
        }
    }

    private function ensureRank(RankExam $exam, Rank $rank): void
    {
        abort_unless($this->ranks($exam)->contains(fn (array $row) => $row['rank']->id === $rank->id), 404);
    }

    private function name(Student $student): string
    {
        return $student->person->social_name ?: $student->person->full_name;
    }
}
