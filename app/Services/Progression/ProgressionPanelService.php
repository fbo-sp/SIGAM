<?php

namespace App\Services\Progression;

use App\Models\Association;
use App\Models\ClassEnrollment;
use App\Models\ClassGroup;
use App\Models\ClassSession;
use App\Models\ClassSessionTechnique;
use App\Models\Dojo;
use App\Models\MartialArt;
use App\Models\Student;
use App\Models\TeacherProfile;
use App\Models\TechniqueAssessment;
use App\Models\User;
use App\Services\Access\OperationalScope;
use App\Support\Progression\AssessmentLevel;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Painéis do acompanhamento técnico (requisito D12 e premissa 4): turma e dojo para professor e gestor,
 * comparação entre dojos e professores para a associação. Sempre separados em teórico e prático.
 */
class ProgressionPanelService
{
    public function __construct(private readonly OperationalScope $scope, private readonly StudentProgressService $progress) {}

    /** O gestor vê todas as turmas do dojo; o professor, só as que dá como principal ou auxiliar vigente. */
    public function dojoClasses(User $user, Dojo $dojo): Builder
    {
        $query = ClassGroup::query()->where('association_id', $dojo->association_id)->where('dojo_id', $dojo->id)->where('status', 'active');
        if ($this->scope->canDojo($user, $dojo, 'progression.report')) {
            return $query;
        }
        $teacherId = $this->scope->canTeacher($user, $dojo, 'progression.report')
            ? TeacherProfile::query()->where('person_id', $user->person_id)->where('status', 'active')->value('id') : null;
        if (! $teacherId) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(fn ($q) => $q->where('main_teacher_id', $teacherId)
            ->orWhereHas('teacherAssignments', fn ($a) => $a->where('teacher_profile_id', $teacherId)->where('role_type', 'assistant')->current()));
    }

    /**
     * Painel de um conjunto de turmas da mesma modalidade.
     *
     * @param  Collection<int, ClassGroup>  $groups
     */
    public function panel(Association $association, MartialArt $martialArt, Collection $groups, CarbonInterface $from, CarbonInterface $until): array
    {
        $students = $this->students($groups);
        $snapshots = $this->snapshots($association, $martialArt, $students);
        $axes = $this->progress->axes($association, $martialArt);
        $sessions = ClassSession::query()->whereIn('class_group_id', $groups->pluck('id'))->whereNotIn('status', ['cancelled', 'rescheduled'])
            ->where('session_date', '>=', $from->toDateString())->where('session_date', '<', $until->copy()->addDay()->toDateString())
            ->where('session_date', '<', today()->addDay()->toDateString())->pluck('id');
        $workedSessions = [];
        ClassSessionTechnique::query()->whereIn('class_session_id', $sessions)->whereNull('removed_at')->get(['class_session_id', 'technique_item_id'])
            ->each(function (ClassSessionTechnique $row) use (&$workedSessions, $axes): void {
                if (isset($axes['axis'][$row->technique_item_id])) {
                    $workedSessions[$axes['type'][$row->technique_item_id]][$axes['axis'][$row->technique_item_id]][$row->class_session_id] = true;
                }
            });

        $rowsByType = [];
        $ranking = [];
        foreach ($snapshots as $studentId => $snapshot) {
            foreach ($snapshot['rows'] as $type => $rows) {
                foreach ($rows as $row) {
                    $rowsByType[$type][$row['axis']][] = $row + ['student_id' => $studentId];
                }
            }
            $ranking[] = [
                'student' => $students[$studentId],
                'next_rank' => $snapshot['next_rank'],
                'theoretical' => $snapshot['missing']->get('theoretical', collect())->count(),
                'practical' => $snapshot['missing']->get('practical', collect())->count(),
            ];
        }
        $byType = collect(['theoretical', 'practical'])->filter(fn ($type) => isset($rowsByType[$type]))->mapWithKeys(fn ($type) => [$type => collect($rowsByType[$type])
            ->map(function (array $rows, string $axis) use ($type, $workedSessions): array {
                $rows = collect($rows);

                return [
                    'label' => $axis,
                    'current' => round($rows->avg(fn (array $row) => $row['current'] ?? AssessmentLevel::NOT_STARTED), 2),
                    'required' => round($rows->avg('level'), 2),
                    'items' => $rows->count(),
                    'students_below' => $rows->where('missing', true)->pluck('student_id')->unique()->count(),
                    'students' => $rows->pluck('student_id')->unique()->count(),
                    'sessions_worked' => count($workedSessions[$type][$axis] ?? []),
                ];
            })->sortByDesc('students_below')->values()->all()]);

        return [
            'students' => $students->count(),
            'sessions' => $sessions->count(),
            'sessions_with_techniques' => ClassSessionTechnique::query()->whereIn('class_session_id', $sessions)->whereNull('removed_at')->distinct()->count('class_session_id'),
            'axes' => $byType,
            'ranking' => collect($ranking)->sortByDesc(fn (array $row) => $row['theoretical'] + $row['practical'])->values(),
        ];
    }

    /**
     * Comparação na associação: uma linha por dojo e uma por professor principal.
     *
     * @return array{dojos: Collection, teachers: Collection}
     */
    public function comparison(Association $association, MartialArt $martialArt, CarbonInterface $from, CarbonInterface $until): array
    {
        $groups = ClassGroup::query()->where('association_id', $association->id)->where('martial_art_id', $martialArt->id)->where('status', 'active')
            ->with(['dojo:id,name', 'mainTeacher.person:id,full_name,social_name'])->get();
        $summary = fn (Collection $set) => $this->summary($association, $martialArt, $set, $from, $until);

        return [
            'dojos' => $groups->groupBy('dojo_id')->map(fn (Collection $set) => ['label' => $set->first()->dojo->name] + $summary($set))->sortBy('label')->values(),
            'teachers' => $groups->whereNotNull('main_teacher_id')->groupBy('main_teacher_id')->map(fn (Collection $set) => [
                'label' => $set->first()->mainTeacher?->person?->social_name ?: $set->first()->mainTeacher?->person?->full_name ?: 'Professor',
            ] + $summary($set) + [
                'assessments' => TechniqueAssessment::query()->where('association_id', $association->id)->where('martial_art_id', $martialArt->id)
                    ->where('teacher_profile_id', $set->first()->main_teacher_id)->whereBetween('assessed_on', [$from->toDateString(), $until->toDateString()])->count(),
            ])->sortBy('label')->values(),
        ];
    }

    private function summary(Association $association, MartialArt $martialArt, Collection $groups, CarbonInterface $from, CarbonInterface $until): array
    {
        $panel = $this->panel($association, $martialArt, $groups, $from, $until);
        $count = max($panel['ranking']->count(), 1);

        return [
            'classes' => $groups->count(),
            'students' => $panel['students'],
            'missing_theoretical' => round($panel['ranking']->sum('theoretical') / $count, 1),
            'missing_practical' => round($panel['ranking']->sum('practical') / $count, 1),
            'ready' => $panel['ranking']->filter(fn (array $row) => $row['next_rank'] && $row['theoretical'] + $row['practical'] === 0)->count(),
            'sessions' => $panel['sessions'],
            'sessions_with_techniques' => $panel['sessions_with_techniques'],
        ];
    }

    /** @return Collection<int, Student> alunos com matrícula vigente nas turmas, sem repetição */
    private function students(Collection $groups): Collection
    {
        $ids = ClassEnrollment::query()->whereIn('class_group_id', $groups->pluck('id'))->where('status', 'active')
            ->whereDate('start_date', '<=', today())->where(fn ($q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', today()))
            ->distinct()->pluck('student_id');

        return Student::query()->whereKey($ids)->where('status', 'active')->with(['person:id,full_name,social_name', 'martialArts'])->get()->keyBy('id');
    }

    /** Níveis carregados de uma vez para todos os alunos; o currículo e os eixos ficam em memória. */
    private function snapshots(Association $association, MartialArt $martialArt, Collection $students): array
    {
        $levels = TechniqueAssessment::query()->whereIn('student_id', $students->keys())->where('martial_art_id', $martialArt->id)
            ->orderByDesc('id')->get(['id', 'student_id', 'technique_item_id', 'level', 'assessed_on'])->groupBy('student_id')
            ->map(fn (Collection $rows) => $rows->unique('technique_item_id')->keyBy('technique_item_id'));
        $snapshots = [];
        foreach ($students as $student) {
            $snapshots[$student->id] = $this->progress->snapshot($association, $student, $martialArt, $levels->get($student->id, collect()), details: false);
        }

        return $snapshots;
    }
}
