<?php

namespace App\Services\Progression;

use App\Models\Association;
use App\Models\Dojo;
use App\Models\MartialArt;
use App\Models\ProgressionSetting;
use App\Models\QuizAttempt;
use App\Models\QuizAttemptAnswer;
use App\Models\QuizQuestion;
use App\Models\QuizRelease;
use App\Models\Rank;
use App\Models\Student;
use App\Models\TechniqueAssessment;
use App\Models\TechniqueItem;
use App\Models\User;
use App\Services\AuditService;
use App\Support\Progression\AssessmentLevel;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Quiz teórico (requisito D6, D10 e premissas 1 e 2). Treino tem tentativas livres e não conta no
 * acompanhamento. Oficial vale uma tentativa por liberação do professor; atingir a nota mínima registra
 * os itens teóricos das perguntas como "Adequado" quando o aluno estava abaixo disso.
 */
class QuizService
{
    public function __construct(private readonly AuditService $audit, private readonly AssessmentService $assessments, private readonly StudentProgressService $progress) {}

    public function saveQuestion(Association $association, MartialArt $martialArt, array $data, User $actor, ?QuizQuestion $question = null): QuizQuestion
    {
        $rank = Rank::query()->where('martial_art_id', $martialArt->id)->find($data['rank_id']);
        if ($rank === null) {
            throw ValidationException::withMessages(['rank_id' => 'Escolha uma graduação desta modalidade.']);
        }
        $itemId = $data['technique_item_id'] ?? null;
        if ($itemId && ! TechniqueItem::query()->where('association_id', $association->id)->where('martial_art_id', $martialArt->id)->where('knowledge_type', 'theoretical')->whereKey($itemId)->exists()) {
            throw ValidationException::withMessages(['technique_item_id' => 'Escolha um item teórico do catálogo desta modalidade.']);
        }
        $options = collect($data['options'])->map(fn ($text) => trim((string) $text))->filter()->values();
        $correct = (int) $data['correct'];
        if ($options->count() < 2 || ! isset($data['options'][$correct]) || trim((string) $data['options'][$correct]) === '') {
            throw ValidationException::withMessages(['options' => 'Informe ao menos duas alternativas e marque a correta.']);
        }
        $correctText = trim((string) $data['options'][$correct]);

        return DB::transaction(function () use ($association, $martialArt, $data, $actor, $question, $rank, $itemId, $options, $correctText): QuizQuestion {
            $before = $question?->only(['rank_id', 'technique_item_id', 'statement', 'status']);
            $question ??= new QuizQuestion(['association_id' => $association->id, 'martial_art_id' => $martialArt->id, 'status' => 'active', 'created_by_user_id' => $actor->id]);
            $answered = $question->exists && QuizAttemptAnswer::query()->where('quiz_question_id', $question->id)->exists();
            $currentOptions = $question->exists ? $question->options()->get() : collect();
            $sameOptions = $currentOptions->pluck('text')->values()->all() === $options->all()
                && $currentOptions->firstWhere('is_correct', true)?->text === $correctText;
            if ($answered && ! $sameOptions) {
                throw ValidationException::withMessages(['options' => 'Esta pergunta já foi respondida. Para mudar as alternativas, inative-a e crie outra.']);
            }
            $question->fill(['rank_id' => $rank->id, 'technique_item_id' => $itemId ?: null, 'statement' => trim($data['statement']), 'explanation' => $data['explanation'] ?? null])->save();
            if (! $sameOptions) {
                $question->options()->delete();
                foreach ($options as $index => $text) {
                    $question->options()->create(['text' => $text, 'is_correct' => $text === $correctText, 'sort_order' => $index + 1]);
                }
            }
            $this->audit->record('quiz_question.saved', $before === null ? 'created' : 'updated', $question, $before, $question->only(['rank_id', 'technique_item_id', 'statement', 'status']), ['association_id' => $association->id]);

            return $question;
        });
    }

    public function setQuestionStatus(Association $association, QuizQuestion $question, string $status): void
    {
        $before = $question->status;
        $question->update(['status' => $status]);
        $this->audit->record('quiz_question.status_changed', 'updated', $question, ['status' => $before], ['status' => $status], ['association_id' => $association->id]);
    }

    public function saveSettings(Association $association, array $data, User $actor): ProgressionSetting
    {
        $setting = ProgressionSetting::query()->firstOrNew(['association_id' => $association->id]);
        $before = $setting->exists ? $setting->only(array_keys(ProgressionSetting::DEFAULTS)) : null;
        $setting->fill([...$data, 'updated_by_user_id' => $actor->id])->save();
        $this->audit->record('progression_settings.saved', $before === null ? 'created' : 'updated', $setting, $before, $setting->only(array_keys(ProgressionSetting::DEFAULTS)), ['association_id' => $association->id]);

        return $setting;
    }

    /** Liberação oficial pelo professor que avalia o aluno; repetir não cria outra enquanto houver uma aberta. */
    public function release(User $user, Dojo $dojo, Student $student, MartialArt $martialArt): QuizRelease
    {
        $teacherId = $this->assessments->assessorTeacherId($user, $dojo, $student, $martialArt);
        abort_if($teacherId === null, 403);
        $rank = $this->progress->snapshot($dojo->association, $student, $martialArt, details: false)['next_rank'];
        if ($rank === null) {
            throw ValidationException::withMessages(['quiz' => 'O aluno não tem próxima graduação nesta modalidade.']);
        }
        if ($this->pool($student->association_id, $rank)->isEmpty()) {
            throw ValidationException::withMessages(['quiz' => 'Ainda não há perguntas de quiz para '.$rank->display_name.'.']);
        }

        return DB::transaction(function () use ($user, $dojo, $student, $rank, $teacherId): QuizRelease {
            Student::query()->whereKey($student->id)->lockForUpdate()->first();
            $open = QuizRelease::query()->where('student_id', $student->id)->where('rank_id', $rank->id)->where('status', 'open')->first();
            if ($open) {
                return $open;
            }
            $release = QuizRelease::query()->create([
                'association_id' => $student->association_id, 'student_id' => $student->id, 'rank_id' => $rank->id, 'teacher_profile_id' => $teacherId,
                'dojo_id' => $dojo->id, 'released_by_user_id' => $user->id, 'status' => 'open',
            ]);
            $this->audit->record('quiz_release.created', 'created', $release, null, ['student_id' => $student->id, 'rank_id' => $rank->id], ['association_id' => $student->association_id]);

            return $release;
        });
    }

    public function cancelRelease(User $user, Dojo $dojo, Student $student, QuizRelease $release): void
    {
        abort_unless($release->student_id === $student->id, 404);
        $martialArt = $release->rank->martialArt;
        abort_if($this->assessments->assessorTeacherId($user, $dojo, $student, $martialArt) === null, 403);
        DB::transaction(function () use ($release, $student): void {
            $locked = QuizRelease::query()->lockForUpdate()->findOrFail($release->id);
            if ($locked->status !== 'open' || QuizAttempt::query()->where('quiz_release_id', $locked->id)->exists()) {
                throw ValidationException::withMessages(['quiz' => 'Esta avaliação oficial já foi iniciada pelo aluno.']);
            }
            $locked->update(['status' => 'cancelled']);
            $this->audit->record('quiz_release.cancelled', 'updated', $locked, ['status' => 'open'], ['status' => 'cancelled'], ['association_id' => $student->association_id]);
        });
    }

    /** @return Collection<int, QuizRelease> liberações abertas do aluno, por modalidade */
    public function openReleases(Student $student): Collection
    {
        return QuizRelease::query()->where('student_id', $student->id)->where('status', 'open')->with('rank.martialArt')->get();
    }

    /**
     * Treino: retoma a tentativa em andamento ou sorteia uma nova. Oficial: uma tentativa por liberação.
     */
    public function start(User $user, Student $student, MartialArt $martialArt, string $mode, ?QuizRelease $release = null): QuizAttempt
    {
        if ($mode === 'official') {
            abort_unless($release && $release->student_id === $student->id && $release->rank->martial_art_id === $martialArt->id, 404);
            $rank = $release->rank;
        } else {
            $rank = $this->progress->snapshot($student->association, $student, $martialArt, details: false)['next_rank'];
            if ($rank === null) {
                throw ValidationException::withMessages(['quiz' => 'Não há próxima graduação nesta modalidade.']);
            }
        }

        return DB::transaction(function () use ($user, $student, $martialArt, $mode, $release, $rank): QuizAttempt {
            Student::query()->whereKey($student->id)->lockForUpdate()->first();
            if ($mode === 'official') {
                $locked = QuizRelease::query()->lockForUpdate()->findOrFail($release->id);
                $existing = QuizAttempt::query()->where('quiz_release_id', $locked->id)->first();
                if ($existing) {
                    return $existing;
                }
                if ($locked->status !== 'open') {
                    throw ValidationException::withMessages(['quiz' => 'Esta avaliação oficial não está mais disponível.']);
                }
            } else {
                $existing = QuizAttempt::query()->where('student_id', $student->id)->where('martial_art_id', $martialArt->id)->where('rank_id', $rank->id)
                    ->where('mode', 'training')->where('status', 'in_progress')->first();
                if ($existing) {
                    return $existing;
                }
            }
            $settings = ProgressionSetting::for($student->association_id);
            $questions = $this->draw($student->association_id, $rank, $settings->quiz_question_count);
            if ($questions->isEmpty()) {
                throw ValidationException::withMessages(['quiz' => 'Ainda não há perguntas de quiz para '.$rank->display_name.'.']);
            }
            $attempt = QuizAttempt::query()->create([
                'association_id' => $student->association_id, 'student_id' => $student->id, 'martial_art_id' => $martialArt->id, 'rank_id' => $rank->id,
                'mode' => $mode, 'quiz_release_id' => $mode === 'official' ? $release->id : null, 'user_id' => $user->id, 'status' => 'in_progress',
                'question_count' => $questions->count(), 'minimum_score' => $settings->quiz_minimum_score, 'started_at' => now(),
            ]);
            foreach ($questions->values() as $index => $question) {
                $attempt->answers()->create(['quiz_question_id' => $question->id, 'option_ids' => $question->options->pluck('id')->shuffle()->values()->all(), 'sort_order' => $index + 1]);
            }
            if ($mode === 'official') {
                $this->audit->record('quiz_attempt.started', 'created', $attempt, null, ['mode' => $mode, 'student_id' => $student->id, 'rank_id' => $rank->id], ['association_id' => $student->association_id]);
            }

            return $attempt;
        });
    }

    /**
     * Corrige no servidor. Reenviar uma tentativa já finalizada não muda o resultado.
     *
     * @param  array<int|string, int|string|null>  $choices  question_id => option_id
     */
    public function submit(User $user, QuizAttempt $attempt, array $choices): QuizAttempt
    {
        return DB::transaction(function () use ($user, $attempt, $choices): QuizAttempt {
            $attempt = QuizAttempt::query()->lockForUpdate()->findOrFail($attempt->id);
            if ($attempt->status === 'finished') {
                return $attempt;
            }
            $answers = $attempt->answers()->with('question.options')->get();
            $correct = 0;
            foreach ($answers as $answer) {
                $chosen = (int) ($choices[$answer->quiz_question_id] ?? 0);
                $chosen = in_array($chosen, $answer->option_ids, true) ? $chosen : null;
                $isCorrect = $chosen !== null && (bool) $answer->question->options->firstWhere('id', $chosen)?->is_correct;
                $answer->update(['chosen_option_id' => $chosen, 'is_correct' => $isCorrect]);
                $correct += $isCorrect ? 1 : 0;
            }
            $score = (int) round($correct * 100 / max($attempt->question_count, 1));
            $passed = $score >= $attempt->minimum_score;
            $attempt->update(['status' => 'finished', 'correct_count' => $correct, 'score' => $score, 'passed' => $passed, 'finished_at' => now()]);
            if ($attempt->mode === 'official') {
                $release = QuizRelease::query()->lockForUpdate()->findOrFail($attempt->quiz_release_id);
                $release->update(['status' => 'used']);
                $marked = $passed ? $this->markTheory($user, $attempt, $release, $answers->pluck('question.technique_item_id')->filter()->unique()) : 0;
                $this->audit->record('quiz_attempt.finished', 'updated', $attempt, null, ['score' => $score, 'passed' => $passed, 'items_marked' => $marked], ['association_id' => $attempt->association_id]);
            }

            return $attempt->refresh();
        });
    }

    /** @return Collection<int, QuizAttempt> */
    public function attempts(Student $student, MartialArt $martialArt, ?string $mode = null, int $limit = 20): Collection
    {
        return QuizAttempt::query()->where('student_id', $student->id)->where('martial_art_id', $martialArt->id)->where('status', 'finished')
            ->when($mode, fn ($query) => $query->where('mode', $mode))->with('rank')->latest('finished_at')->limit($limit)->get();
    }

    /** Liberação aberta, provas oficiais e quantidade de treinos do aluno na modalidade. */
    public function summary(Student $student, MartialArt $martialArt): array
    {
        return [
            'quizRelease' => QuizRelease::query()->where('student_id', $student->id)->where('status', 'open')
                ->whereHas('rank', fn ($rank) => $rank->where('martial_art_id', $martialArt->id))->with(['rank', 'attempt'])->first(),
            'quizAttempts' => $this->attempts($student, $martialArt, 'official', 10),
            'trainingCount' => QuizAttempt::query()->where('student_id', $student->id)->where('martial_art_id', $martialArt->id)->where('mode', 'training')->where('status', 'finished')->count(),
        ];
    }

    /** Perguntas ativas da graduação; se faltarem, completa com as das graduações anteriores. */
    private function draw(int $associationId, Rank $rank, int $count): Collection
    {
        $pool = $this->pool($associationId, $rank);
        $own = $pool->where('rank_id', $rank->id)->shuffle();
        $earlier = $pool->where('rank_id', '!=', $rank->id)->shuffle();

        return $own->concat($earlier)->take($count)->shuffle()->values();
    }

    /** @return Collection<int, QuizQuestion> */
    private function pool(int $associationId, Rank $rank): Collection
    {
        $rankIds = Rank::query()->where('martial_art_id', $rank->martial_art_id)->where('sort_order', '<=', $rank->sort_order)->pluck('id');

        return QuizQuestion::query()->where('association_id', $associationId)->whereIn('rank_id', $rankIds)->where('status', 'active')
            ->whereHas('options', fn ($query) => $query->where('is_correct', true))->with('options')->get();
    }

    private function markTheory(User $user, QuizAttempt $attempt, QuizRelease $release, Collection $itemIds): int
    {
        $student = Student::query()->findOrFail($attempt->student_id);
        $current = $this->assessments->current($student, $attempt->martialArt);
        $active = TechniqueItem::query()->whereKey($itemIds->all())->where('status', 'active')->pluck('id');
        $count = 0;
        foreach ($active as $itemId) {
            if (($current->get($itemId)?->level ?? AssessmentLevel::NOT_STARTED) >= AssessmentLevel::ADEQUATE) {
                continue;
            }
            TechniqueAssessment::query()->create([
                'association_id' => $attempt->association_id, 'student_id' => $attempt->student_id, 'martial_art_id' => $attempt->martial_art_id,
                'technique_item_id' => $itemId, 'level' => AssessmentLevel::ADEQUATE, 'teacher_profile_id' => $release->teacher_profile_id,
                'dojo_id' => $release->dojo_id, 'assessed_by_user_id' => $user->id, 'assessed_on' => today()->toDateString(),
                'notes' => "Quiz oficial: nota {$attempt->score}%",
            ]);
            $count++;
        }

        return $count;
    }
}
