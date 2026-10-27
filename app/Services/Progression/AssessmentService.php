<?php

namespace App\Services\Progression;

use App\Models\ClassEnrollment;
use App\Models\ClassGroup;
use App\Models\Dojo;
use App\Models\MartialArt;
use App\Models\Student;
use App\Models\TeacherProfile;
use App\Models\TechniqueAssessment;
use App\Models\TechniqueItem;
use App\Models\User;
use App\Services\Access\OperationalScope;
use App\Services\AuditService;
use App\Support\Progression\AssessmentLevel;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Avaliação técnica pelo professor (requisito D7, D10 e D11). Avaliam o principal e os auxiliares
 * vigentes das turmas da modalidade em que o aluno está matriculado; o gestor do dojo só consulta.
 */
class AssessmentService
{
    public function __construct(private readonly OperationalScope $scope, private readonly AuditService $audit) {}

    /** Perfil de professor que pode avaliar o aluno nesta modalidade e neste dojo, ou null. */
    public function assessorTeacherId(User $user, Dojo $dojo, Student $student, MartialArt $martialArt): ?int
    {
        if ($student->association_id !== $dojo->association_id || ! $this->scope->canTeacher($user, $dojo, 'assessment.manage')) {
            return null;
        }
        $teacherId = TeacherProfile::query()->where('person_id', $user->person_id)->where('status', 'active')->value('id');
        if (! $teacherId) {
            return null;
        }

        return ClassEnrollment::query()->where('student_id', $student->id)->where('status', 'active')
            ->whereDate('start_date', '<=', today())->where(fn ($q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', today()))
            ->whereHas('classGroup', fn (Builder $group) => $this->teacherGroups($group, $dojo, $martialArt, $teacherId))
            ->exists() ? $teacherId : null;
    }

    /** O professor avalia os alunos desta turma? */
    public function teachesClass(User $user, Dojo $dojo, ClassGroup $classGroup): bool
    {
        if (! $this->scope->canTeacher($user, $dojo, 'assessment.manage')) {
            return false;
        }
        $teacherId = TeacherProfile::query()->where('person_id', $user->person_id)->where('status', 'active')->value('id');

        return $teacherId && $this->teacherGroups(ClassGroup::query()->whereKey($classGroup->id), $dojo, $classGroup->martialArt, $teacherId)->exists();
    }

    /** Gestor do dojo (aluno regular do dojo) ou professor que avalia o aluno. */
    public function canViewInDojo(User $user, Dojo $dojo, Student $student, MartialArt $martialArt): bool
    {
        if ($this->scope->canDojo($user, $dojo, 'assessment.view') && $this->scope->canDojo($user, $dojo, 'student.view')
            && $this->scope->students($user, $dojo)->whereKey($student->id)->exists()) {
            return true;
        }

        return $this->assessorTeacherId($user, $dojo, $student, $martialArt) !== null;
    }

    /**
     * Nível atual por item: o último registro gravado (a data informada é só da observação).
     *
     * @return Collection<int, TechniqueAssessment>
     */
    public function current(Student $student, MartialArt $martialArt): Collection
    {
        return TechniqueAssessment::query()->where('student_id', $student->id)->where('martial_art_id', $martialArt->id)
            ->orderByDesc('id')->get()->unique('technique_item_id')->keyBy('technique_item_id');
    }

    /** @return Collection<int, TechniqueAssessment> */
    public function history(Student $student, MartialArt $martialArt, int $limit = 60): Collection
    {
        return TechniqueAssessment::query()->where('student_id', $student->id)->where('martial_art_id', $martialArt->id)
            ->with(['item:id,name', 'teacher.person:id,full_name,social_name'])
            ->orderByDesc('assessed_on')->orderByDesc('id')->limit($limit)->get();
    }

    /**
     * Grava uma avaliação para cada item marcado, inclusive quando o nível se mantém: cada registro é um ponto
     * na linha do tempo. Reenviar o mesmo formulário não duplica: item, data, nível e dica iguais são ignorados.
     *
     * @param  array<int|string, int|string|null>  $levels  item_id => nível (vazio = não avaliado agora)
     * @param  array<int|string, string|null>  $tips  item_id => dica para o aluno (opcional, exige nível)
     */
    public function record(User $user, Dojo $dojo, Student $student, MartialArt $martialArt, array $levels, CarbonInterface $assessedOn, ?string $notes, array $tips = []): int
    {
        $teacherId = $this->assessorTeacherId($user, $dojo, $student, $martialArt);
        abort_if($teacherId === null, 403);
        if ($assessedOn->isAfter(today())) {
            throw ValidationException::withMessages(['assessed_on' => 'A avaliação não pode ter data futura.']);
        }
        $levels = collect($levels)->filter(fn ($level) => $level !== null && $level !== '')->map(fn ($level) => (int) $level);
        if ($levels->contains(fn (int $level) => ! array_key_exists($level, AssessmentLevel::labels()))) {
            throw ValidationException::withMessages(['levels' => 'Escolha um nível válido para cada item.']);
        }
        $tips = collect($tips)->map(fn ($tip) => trim((string) $tip) ?: null)->filter();
        if ($tips->keys()->diff($levels->keys())->isNotEmpty()) {
            throw ValidationException::withMessages(['tips' => 'Para deixar uma dica, marque também o nível do item.']);
        }

        return DB::transaction(function () use ($user, $dojo, $student, $martialArt, $levels, $tips, $assessedOn, $notes, $teacherId): int {
            Student::query()->whereKey($student->id)->lockForUpdate()->first();
            $items = TechniqueItem::query()->where('association_id', $student->association_id)->where('martial_art_id', $martialArt->id)
                ->whereKey($levels->keys()->all())->where('status', 'active')->pluck('id')->all();
            if (count($items) !== $levels->count()) {
                throw ValidationException::withMessages(['levels' => 'Um dos itens não está ativo no catálogo desta modalidade.']);
            }
            $sameDay = TechniqueAssessment::query()->where('student_id', $student->id)->where('martial_art_id', $martialArt->id)
                ->whereDate('assessed_on', $assessedOn->toDateString())->whereIn('technique_item_id', $levels->keys()->all())
                ->get(['technique_item_id', 'level', 'student_tip'])->groupBy('technique_item_id');
            $saved = 0;
            foreach ($levels as $itemId => $level) {
                $tip = $tips->get($itemId);
                if ($sameDay->get((int) $itemId, collect())->contains(fn (TechniqueAssessment $row) => $row->level === $level && $row->student_tip === $tip)) {
                    continue;
                }
                TechniqueAssessment::query()->create([
                    'association_id' => $student->association_id, 'student_id' => $student->id, 'martial_art_id' => $martialArt->id,
                    'technique_item_id' => (int) $itemId, 'level' => $level, 'teacher_profile_id' => $teacherId, 'dojo_id' => $dojo->id,
                    'assessed_by_user_id' => $user->id, 'assessed_on' => $assessedOn->toDateString(), 'notes' => $notes, 'student_tip' => $tip,
                ]);
                $saved++;
            }
            if ($saved > 0) {
                $this->audit->record('technique_assessment.recorded', 'created', $student, null, [
                    'martial_art_id' => $martialArt->id, 'items' => $saved, 'assessed_on' => $assessedOn->toDateString(), 'teacher_profile_id' => $teacherId,
                ], ['association_id' => $student->association_id]);
            }

            return $saved;
        });
    }

    /**
     * Todas as avaliações dos itens, em ordem de data, para a linha do tempo.
     *
     * @param  list<int>  $itemIds
     * @return Collection<int, TechniqueAssessment>
     */
    public function timeline(Student $student, MartialArt $martialArt, array $itemIds): Collection
    {
        return TechniqueAssessment::query()->where('student_id', $student->id)->where('martial_art_id', $martialArt->id)
            ->whereIn('technique_item_id', $itemIds)->with('teacher.person:id,full_name,social_name')
            ->orderBy('assessed_on')->orderBy('id')->get();
    }

    /** Turmas da modalidade no dojo em que o professor é principal ou auxiliar vigente. */
    private function teacherGroups(Builder $group, Dojo $dojo, MartialArt $martialArt, int $teacherId): Builder
    {
        return $group->where('association_id', $dojo->association_id)->where('dojo_id', $dojo->id)->where('martial_art_id', $martialArt->id)
            ->where('status', 'active')
            ->where(fn ($q) => $q->where('main_teacher_id', $teacherId)
                ->orWhereHas('teacherAssignments', fn ($a) => $a->where('teacher_profile_id', $teacherId)->where('role_type', 'assistant')->current()));
    }
}
