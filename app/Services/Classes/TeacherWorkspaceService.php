<?php

namespace App\Services\Classes;

use App\Models\AttendanceRecord;
use App\Models\ClassEnrollment;
use App\Models\ClassGroup;
use App\Models\ClassSession;
use App\Models\Dojo;
use App\Models\DojoRoleAssignment;
use App\Models\Student;
use App\Models\TeacherProfile;
use App\Models\TechniqueAssessment;
use App\Models\User;
use App\Services\Access\OperationalScope;
use App\Services\Progression\CurriculumCoverageService;
use App\Services\Progression\ProgressionPanelService;
use App\Services\Progression\StudentProgressService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Área de trabalho do professor (Hoje, Turmas e Alunos): só as aulas em que ele é o professor previsto,
 * quem deu a aula, o principal da turma ou auxiliar vigente, nos dojos em que as concessões vigentes
 * permitem abrir a aula (ADR-006). Vale também para o gestor do dojo que dá aula.
 */
class TeacherWorkspaceService
{
    /** @var array<string, mixed> */
    private array $memo = [];

    public function __construct(
        private readonly OperationalScope $scope,
        private readonly ProgressionPanelService $panels,
        private readonly CurriculumCoverageService $coverage,
        private readonly StudentProgressService $progress,
    ) {}

    public function teacherId(User $user): ?int
    {
        if (! $user->person_id || ! $user->association_id) {
            return null;
        }

        return $this->memo['teacher'][$user->id] ??= TeacherProfile::query()->where('person_id', $user->person_id)->where('status', 'active')->value('id');
    }

    /** Professor (painel do professor) ou gestor do dojo com turma ativa como principal ou auxiliar. */
    public function usesNavigation(User $user): bool
    {
        $association = $user->association_id;
        if (! $association || $user->hasPermission('dashboard.association', $association)) {
            return false;
        }
        if ($user->hasPermission('dashboard.teacher', $association) && ! $user->hasPermission('dashboard.dojo', $association)) {
            return true;
        }

        return $user->hasPermission('dashboard.dojo', $association) && $this->teacherId($user) !== null && $this->groups($user)->exists();
    }

    /** Turmas ativas da associação em que a pessoa é principal ou auxiliar vigente. */
    public function groups(User $user): Builder
    {
        $teacherId = $this->teacherId($user) ?? 0;

        return ClassGroup::query()->where('association_id', $user->association_id)->where('status', 'active')
            ->where(fn (Builder $query) => $query->where('main_teacher_id', $teacherId)
                ->orWhereHas('teacherAssignments', fn (Builder $assignment) => $assignment->where('teacher_profile_id', $teacherId)->where('role_type', 'assistant')->current()));
    }

    /** @return Collection<int, Dojo> dojos ativos com concessão vigente da conta */
    public function dojos(User $user): Collection
    {
        return $this->memo['dojos'][$user->id] ??= Dojo::query()->where('association_id', $user->association_id)->where('status', 'active')
            ->whereIn('id', DojoRoleAssignment::query()->select('dojo_id')->where('user_id', $user->id)->where('association_id', $user->association_id)
                ->whereNull('revoked_at')->whereDate('start_date', '<=', today())
                ->where(fn (Builder $query) => $query->whereNull('end_date')->orWhereDate('end_date', '>=', today())))
            ->orderBy('name')->get()->filter(fn (Dojo $dojo) => $this->scope->canEnter($user, $dojo))->values();
    }

    /**
     * Aulas do próprio professor que a conta pode abrir, com o link da chamada no dojo.
     *
     * @param  callable(Builder): Builder  $filter
     * @return Collection<int, array{session: ClassSession, dojo: Dojo, url: string, role: string}>
     */
    public function sessions(User $user, callable $filter, int $limit = 50): Collection
    {
        $teacherId = $this->teacherId($user);
        if (! $teacherId) {
            return collect();
        }

        $load = fn (Builder $query) => $filter($query)->whereNotIn('status', ['cancelled', 'rescheduled'])
            ->with(['classGroup' => fn ($group) => $group->with('dojo')->withCount(['enrollments' => fn ($e) => $e->where('status', 'active')])])
            ->orderBy('session_date')->orderBy('start_time')->limit($limit)->get();
        $item = fn (ClassSession $session, string $url) => [
            'session' => $session,
            'dojo' => $session->classGroup->dojo,
            'url' => $url,
            'role' => $session->classGroup->main_teacher_id === $teacherId ? 'Principal' : 'Auxiliar',
        ];
        $items = $this->dojos($user)->flatMap(fn (Dojo $dojo) => $load($this->own($this->scope->sessions($user, $dojo), $teacherId))
            ->map(fn (ClassSession $session) => $item($session, route('operation.session', [$dojo, $session]))));
        // Sem concessão no dojo, quem tem `session.view` associativo abre a chamada pela associação.
        if ($user->hasPermission('session.view', $user->association_id)) {
            $known = $items->pluck('session.id');
            $items = $items->concat($load($this->own(ClassSession::query()->whereIn('class_group_id', $this->groups($user)->select('id'))->whereNotIn('id', $known), $teacherId))
                ->map(fn (ClassSession $session) => $item($session, route('associations.attendance.show', [$user->association_id, $session]))));
        }

        return $items->sortBy(fn (array $item) => $item['session']->session_date->format('Y-m-d').' '.$item['session']->start_time)->values();
    }

    /** @return array{today: Collection, pending: Collection, upcoming: Collection} */
    public function agenda(User $user): array
    {
        return [
            'today' => $this->sessions($user, fn (Builder $query) => $query->whereDate('session_date', today())),
            'pending' => $this->sessions($user, fn (Builder $query) => $query->whereDate('session_date', '<', today())->where('attendance_state', 'open'), 200),
            'upcoming' => $this->sessions($user, fn (Builder $query) => $query->whereDate('session_date', '>', today())->whereDate('session_date', '<=', today()->addDays(7)), 8)->take(8),
        ];
    }

    /**
     * Turmas do professor com link da turma no dojo e cobertura dos últimos 90 dias, quando as concessões permitem.
     *
     * @return Collection<int, array{class: ClassGroup, dojo: ?Dojo, role: string, url: ?string, coverage: ?array, coverage_url: ?string}>
     */
    public function classes(User $user, bool $withCoverage = true): Collection
    {
        $teacherId = $this->teacherId($user);
        if (! $teacherId) {
            return collect();
        }
        $visible = [];

        return $this->groups($user)->with(['dojo', 'martialArt:id,name', 'schedules' => fn ($query) => $query->where('status', 'active')->orderBy('weekday')])
            ->withCount(['enrollments' => fn ($query) => $query->where('status', 'active')])->orderBy('name')->get()
            ->map(function (ClassGroup $group) use ($user, $teacherId, $withCoverage, &$visible): array {
                $dojo = $group->dojo;
                $canOpen = $dojo && $dojo->status === 'active' && $this->scope->canEnter($user, $dojo)
                    && ($visible[$dojo->id] ??= $this->scope->classes($user, $dojo)->pluck('id')->flip())->has($group->id);
                $canCover = $withCoverage && $canOpen && $this->panels->dojoClasses($user, $dojo)->whereKey($group->id)->exists();

                return [
                    'class' => $group,
                    'dojo' => $dojo,
                    'role' => $group->main_teacher_id === $teacherId ? 'Principal' : 'Auxiliar',
                    'url' => $canOpen ? route('operation.classes.show', [$dojo, $group]) : null,
                    'coverage' => $canCover ? $this->coverage->forClass($dojo->association, $group, today()->subDays(90), today())['overall'] : null,
                    'coverage_url' => $canCover ? route('operation.classes.coverage', [$dojo, $group]) : null,
                ];
            });
    }

    /**
     * Alunos com matrícula ativa nas turmas que o professor pode abrir, um por aluno e modalidade.
     * Faltando: as três últimas chamadas como ausente. Pronto: nada abaixo do exigido pela próxima faixa.
     *
     * @return Collection<int, array{student: Student, class_names: string, dojo: Dojo, martial_art_id: int, rank: ?string, next_rank: ?string, absent: bool, ready: bool}>
     */
    public function students(User $user, ?string $search = null): Collection
    {
        $classes = $this->classes($user, false)->filter(fn (array $item) => $item['url'] !== null);
        if ($classes->isEmpty()) {
            return collect();
        }
        $byId = $classes->keyBy(fn (array $item) => $item['class']->id);
        $enrollments = ClassEnrollment::query()->whereIn('class_group_id', $byId->keys())->where('status', 'active')
            ->whereHas('student', fn (Builder $query) => $query->where('status', 'active')
                ->when($search, fn (Builder $q) => $q->where(fn (Builder $s) => $s->where('registration_number', 'like', '%'.$search.'%')
                    ->orWhereHas('person', fn (Builder $p) => $p->where('full_name', 'like', '%'.$search.'%')->orWhere('social_name', 'like', '%'.$search.'%')))))
            ->with(['student' => fn ($query) => $query->with([
                'person:id,full_name,social_name,birth_date,photo_path,phone,mobile,emergency_contact_name,emergency_contact_phone',
                'martialArts',
                'guardians' => fn ($guardians) => $guardians->where(fn ($g) => $g->where('is_legal_guardian', true)->orWhere('is_primary', true))
                    ->where(fn ($g) => $g->whereNull('end_date')->orWhereDate('end_date', '>=', today()))->with('person:id,full_name,social_name,phone,mobile'),
            ])])->limit(500)->get();

        $recent = AttendanceRecord::query()->whereIn('student_id', $enrollments->pluck('student_id')->unique())->where('recorded_at', '>=', now()->subMonths(6))
            ->orderByDesc('recorded_at')->get(['student_id', 'status'])->groupBy('student_id')
            ->map(fn (Collection $rows) => $rows->take(3)->pluck('status'));

        return $enrollments->groupBy(fn (ClassEnrollment $enrollment) => $enrollment->student_id.'-'.$byId[$enrollment->class_group_id]['class']->martial_art_id)
            ->map(function (Collection $set) use ($byId, $recent): array {
                $student = $set->first()->student;
                $class = $byId[$set->first()->class_group_id];
                $group = $class['class'];
                $levels = TechniqueAssessment::query()->where('student_id', $student->id)->where('martial_art_id', $group->martial_art_id)
                    ->orderByDesc('id')->get(['id', 'technique_item_id', 'level', 'assessed_on'])->unique('technique_item_id')->keyBy('technique_item_id');
                $snapshot = $this->progress->snapshot($group->dojo->association, $student, $group->martialArt, $levels, details: false);
                $statuses = $recent->get($student->id, collect());

                return [
                    'student' => $student,
                    'class_names' => $set->map(fn (ClassEnrollment $enrollment) => $byId[$enrollment->class_group_id]['class']->name)->unique()->implode(', '),
                    'dojo' => $class['dojo'],
                    'martial_art_id' => $group->martial_art_id,
                    'rank' => $snapshot['current_rank']?->display_name,
                    'next_rank' => $snapshot['next_rank']?->display_name,
                    'absent' => $statuses->count() === 3 && $statuses->every(fn (string $status) => $status === 'absent'),
                    'ready' => $snapshot['next_rank'] !== null && $snapshot['rows']->flatten(1)->isNotEmpty() && $snapshot['missing']->flatten(1)->isEmpty(),
                ];
            })->sortBy(fn (array $row) => mb_strtolower($row['student']->person->social_name ?: $row['student']->person->full_name))->values();
    }

    /** Restringe às aulas do próprio professor (mesma regra do contexto de professor em OperationalScope). */
    private function own(Builder $sessions, int $teacherId): Builder
    {
        return $sessions->where(fn ($q) => $q->where('planned_teacher_id', $teacherId)->orWhere('actual_teacher_id', $teacherId)
            ->orWhere(fn ($g) => $g->whereNull('planned_teacher_id')->whereNull('actual_teacher_id')
                ->whereHas('classGroup', fn ($c) => $c->where('main_teacher_id', $teacherId)))
            ->orWhereExists(fn ($a) => $a->selectRaw('1')->from('class_group_teachers')
                ->whereColumn('class_group_id', 'class_sessions.class_group_id')->where('teacher_profile_id', $teacherId)
                ->whereIn('role_type', ['assistant', 'substitute'])
                ->whereColumn('start_date', '<=', 'class_sessions.session_date')
                ->where(fn ($d) => $d->whereNull('end_date')->orWhereColumn('end_date', '>=', 'class_sessions.session_date'))));
    }
}
