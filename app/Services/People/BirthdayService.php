<?php

namespace App\Services\People;

use App\Models\Association;
use App\Models\ClassGroup;
use App\Models\Dojo;
use App\Models\Person;
use App\Models\Student;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Services\Access\OperationalScope;
use App\Services\AuditService;
use App\Services\Classes\TeacherWorkspaceService;
use App\Services\Reporting\ReportContext;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Aniversariantes do mês e avisos do dia (decisões de Fabio em 29/10/2026). Alcance igual ao de quem já vê os
 * alunos: associação (todos os dojos), gestor do dojo (o dojo) ou professor (as turmas em que é principal ou
 * auxiliar). Colegas veem só nome e turma, e quem escondeu o aniversário não aparece para eles; gestores e
 * professores continuam vendo. Tudo é calculado ao abrir a tela, sem cron nem notificação guardada.
 */
class BirthdayService
{
    public const PERMISSION = 'student.view';

    public const SHOW = ['todos' => 'Alunos e professores', 'alunos' => 'Só alunos', 'professores' => 'Só professores'];

    public function __construct(
        private readonly OperationalScope $scope,
        private readonly TeacherWorkspaceService $workspace,
        private readonly AuditService $audit,
    ) {}

    public function association(User $user, Association $association): ?ReportContext
    {
        if (! $user->hasPermission(self::PERMISSION, $association->id)) {
            return null;
        }
        $dojos = Dojo::query()->where('association_id', $association->id)->where('status', 'active')->orderBy('name')->get();

        return new ReportContext('association', $user, $association, null, $dojos, $this->groups(ClassGroup::query()->where('association_id', $association->id)));
    }

    public function dojo(User $user, Dojo $dojo): ?ReportContext
    {
        if (! $this->scope->canDojo($user, $dojo, self::PERMISSION)) {
            return null;
        }
        $dojo->loadMissing('association');

        return new ReportContext('dojo', $user, $dojo->association, $dojo, collect([$dojo]),
            $this->groups(ClassGroup::query()->where('association_id', $dojo->association_id)->where('dojo_id', $dojo->id)));
    }

    /** Professor: as turmas que ele consegue abrir (mesma regra da aba Alunos). */
    public function teacher(User $user): ?ReportContext
    {
        $classes = $this->workspace->classes($user, false)->filter(fn (array $item) => $item['url'] !== null)->map(fn (array $item) => $item['class'])->values();
        if ($classes->isEmpty()) {
            return null;
        }
        $dojos = $classes->pluck('dojo')->filter()->unique('id')->sortBy('name')->values();

        return new ReportContext('teacher', $user, $dojos->first()?->association ?? $user->association, null, $dojos, $classes);
    }

    /** Dia em que o aniversário cai no ano: quem nasceu em 29/02 aparece em 28/02 nos anos não bissextos. */
    public static function dayIn(CarbonInterface $birth, int $year): int
    {
        return $birth->month === 2 && $birth->day === 29 && ! Carbon::create($year, 1, 1)->isLeapYear() ? 28 : $birth->day;
    }

    public static function isOn(?CarbonInterface $birth, CarbonInterface $date): bool
    {
        return $birth !== null && $birth->month === $date->month && self::dayIn($birth, $date->year) === $date->day;
    }

    /**
     * Aniversariantes do mês no alcance, ordenados por dia e nome. Uma linha por pessoa (professor que também é aluno
     * aparece como professor).
     *
     * @param  array{dojo_id?: ?int, class_group_id?: ?int, show?: ?string}  $filters
     * @return Collection<int, array{person: Person, kind: string, day: int, date: Carbon, age: int, today: bool, context: string, class_ids: list<int>}>
     */
    public function month(ReportContext $context, int $year, int $month, array $filters = []): Collection
    {
        $show = $filters['show'] ?? 'todos';
        $dojoId = $filters['dojo_id'] ?? null;
        $classId = $filters['class_group_id'] ?? null;
        $classIds = $classId ? collect([$classId]) : $context->classes->pluck('id');
        if ($dojoId) {
            $classIds = $context->classes->where('dojo_id', $dojoId)->pluck('id')->intersect($classIds)->values();
        }
        $classes = $context->classes->keyBy('id');

        $teachers = $show === 'alunos' ? collect() : $this->teachers($context, $month, $dojoId, $classId, $classIds, $classes);
        $students = $show === 'professores' ? collect() : $this->students($context, $month, $dojoId, $classId, $classes);

        return $teachers->concat($students)->unique(fn (array $row) => $row['person']->id)
            ->map(fn (array $row) => $row + $this->occurrence($row['person']->birth_date, $year, $month))
            ->sortBy(fn (array $row) => sprintf('%02d', $row['day']).mb_strtolower($row['person']->social_name ?: $row['person']->full_name))->values();
    }

    /**
     * Avisos do dia no portal: colegas com matrícula vigente nas mesmas turmas e professores dessas turmas, sem quem
     * escondeu o aniversário. Só nome e turma, sem idade.
     *
     * @return Collection<int, array{person: Person, kind: string, context: string}>
     */
    public function todayForClassmates(Student $student): Collection
    {
        $today = today();
        $classes = ClassGroup::query()->where('association_id', $student->association_id)->where('status', 'active')
            ->whereIn('id', $student->enrollments()->where('status', 'active')->coveringDate($today->toDateString())->select('class_group_id'))
            ->with(['mainTeacher.person', 'currentAssistants.teacher.person'])->orderBy('name')->get();
        if ($classes->isEmpty()) {
            return collect();
        }
        $visible = fn (?Person $person) => $person && ! $person->hide_birthday && $person->id !== $student->person_id && self::isOn($person->birth_date, $today);

        $teachers = $classes->flatMap(fn (ClassGroup $group) => collect([$group->mainTeacher])->concat($group->currentAssistants->pluck('teacher'))
            ->filter(fn (?TeacherProfile $teacher) => $teacher && $teacher->status === 'active' && $visible($teacher->person))
            ->map(fn (TeacherProfile $teacher) => ['person' => $teacher->person, 'kind' => 'teacher', 'class' => $group->name]));

        $students = Student::query()->where('association_id', $student->association_id)->where('status', 'active')->whereKeyNot($student->id)
            ->whereHas('person', fn (Builder $person) => $this->inMonth($person, $today->month)->where('hide_birthday', false))
            ->whereHas('enrollments', fn (Builder $enrollment) => $this->current($enrollment)->whereIn('class_group_id', $classes->pluck('id')))
            ->with(['person', 'enrollments' => fn ($enrollment) => $this->current($enrollment)->whereIn('class_group_id', $classes->pluck('id'))])
            ->limit(200)->get()
            ->filter(fn (Student $classmate) => $visible($classmate->person))
            ->map(fn (Student $classmate) => ['person' => $classmate->person, 'kind' => 'student',
                'class' => $classes->whereIn('id', $classmate->enrollments->pluck('class_group_id'))->pluck('name')->implode(', ')]);

        return $teachers->groupBy(fn (array $row) => $row['person']->id)
            ->map(fn (Collection $set) => ['person' => $set->first()['person'], 'kind' => 'teacher', 'context' => 'Professor da turma '.$set->pluck('class')->unique()->implode(', ')])
            ->concat($students->map(fn (array $row) => ['person' => $row['person'], 'kind' => 'student', 'context' => 'Colega da turma '.$row['class']]))
            ->unique(fn (array $row) => $row['person']->id)->values();
    }

    /** Aluno ou responsável escolhe se o aniversário aparece para os colegas. Gestores e professores continuam vendo. */
    public function setHidden(User $user, Student $student, bool $hidden): void
    {
        $person = $student->person;
        if ($person->hide_birthday === $hidden) {
            return;
        }
        $before = ['hide_birthday' => $person->hide_birthday];
        $person->forceFill(['hide_birthday' => $hidden])->save();
        $this->audit->record('person.birthday_privacy_changed', 'updated', $person, $before, ['hide_birthday' => $hidden],
            ['association_id' => $student->association_id, 'student_id' => $student->id]);
    }

    /** @return Collection<int, ClassGroup> */
    private function groups(Builder $query): Collection
    {
        return $query->where('status', 'active')->with('dojo:id,name,association_id')->orderBy('name')->get();
    }

    /** @return array{day: int, date: Carbon, age: int, today: bool} */
    private function occurrence(CarbonInterface $birth, int $year, int $month): array
    {
        $day = self::dayIn($birth, $year);
        $date = Carbon::create($year, $month, $day)->startOfDay();

        return ['day' => $day, 'date' => $date, 'age' => $year - $birth->year, 'today' => $date->isSameDay(today())];
    }

    private function inMonth($person, int $month)
    {
        return $person->whereNotNull('birth_date')->whereMonth('birth_date', $month);
    }

    private function current($enrollment)
    {
        return $enrollment->where('status', 'active')->coveringDate(today()->toDateString());
    }

    private function currentDojo($history, int $dojoId)
    {
        return $history->where('dojo_id', $dojoId)->whereDate('start_date', '<=', today())
            ->where(fn (Builder $end) => $end->whereNull('end_date')->orWhereDate('end_date', '>=', today()));
    }

    private function students(ReportContext $context, int $month, ?int $dojoId, ?int $classId, Collection $classes): Collection
    {
        $query = Student::query()->where('association_id', $context->association->id)->where('status', 'active')
            ->whereHas('person', fn (Builder $person) => $this->inMonth($person, $month));
        if ($context->isTeacher()) {
            $query->whereHas('enrollments', fn (Builder $enrollment) => $this->current($enrollment)->whereIn('class_group_id', $classes->keys()));
        } elseif ($context->dojo) {
            $query->whereHas('dojoHistory', fn (Builder $history) => $this->currentDojo($history, $context->dojo->id));
        }
        if ($dojoId) {
            $query->whereHas('dojoHistory', fn (Builder $history) => $this->currentDojo($history, $dojoId));
        }
        if ($classId) {
            $query->whereHas('enrollments', fn (Builder $enrollment) => $this->current($enrollment)->where('class_group_id', $classId));
        }

        return $query->with([
            'person',
            'dojoHistory' => fn ($history) => $history->whereDate('start_date', '<=', today())
                ->where(fn ($end) => $end->whereNull('end_date')->orWhereDate('end_date', '>=', today()))->with('dojo:id,name'),
            'enrollments' => fn ($enrollment) => $this->current($enrollment)->whereIn('class_group_id', $classes->keys()),
        ])->limit(2000)->get()->map(function (Student $student) use ($context, $classes): array {
            $classIds = $student->enrollments->pluck('class_group_id')->unique()->values();
            $names = $classIds->map(fn (int $id) => $classes[$id]->name)->sort()->implode(', ');
            $parts = $context->level === 'association' ? [$student->dojoHistory->pluck('dojo.name')->filter()->unique()->implode(', '), $names] : [$names ?: 'Sem turma'];
            if ($context->isTeacher() && $context->dojos->count() > 1) {
                $parts = [$classIds->map(fn (int $id) => $classes[$id]->dojo?->name)->filter()->unique()->implode(', '), $names];
            }

            return ['person' => $student->person, 'kind' => 'student', 'context' => implode(' · ', array_filter($parts)) ?: 'Aluno', 'class_ids' => $classIds->all()];
        });
    }

    private function teachers(ReportContext $context, int $month, ?int $dojoId, ?int $classId, Collection $classIds, Collection $classes): Collection
    {
        // Turmas de cada professor (principal ou auxiliar vigente) dentro do alcance.
        $roles = [];
        ClassGroup::query()->whereIn('id', $classes->keys())->with('currentAssistants')->get(['id', 'main_teacher_id'])
            ->each(function (ClassGroup $group) use (&$roles): void {
                if ($group->main_teacher_id) {
                    $roles[$group->main_teacher_id][$group->id] = 'Principal';
                }
                foreach ($group->currentAssistants as $assistant) {
                    $roles[$assistant->teacher_profile_id][$group->id] ??= 'Auxiliar';
                }
            });

        $query = TeacherProfile::query()->where('status', 'active')->whereHas('person', fn (Builder $person) => $this->inMonth($person, $month));
        if ($context->isTeacher() || $classId) {
            $wanted = $classId ? [$classId] : $classIds->all();
            $query->whereKey(collect($roles)->filter(fn (array $groups) => array_intersect(array_keys($groups), $wanted) !== [])->keys());
        } else {
            $query->whereExists(fn ($membership) => $membership->selectRaw('1')->from('teacher_association_memberships')
                ->whereColumn('teacher_profile_id', 'teacher_profiles.id')->where('association_id', $context->association->id)->where('status', 'active'));
            $dojo = $dojoId ?? $context->dojo?->id;
            if ($dojo) {
                $query->whereExists(fn ($assignment) => $assignment->selectRaw('1')->from('dojo_teacher_assignments')
                    ->whereColumn('teacher_profile_id', 'teacher_profiles.id')->where('association_id', $context->association->id)->where('dojo_id', $dojo)
                    ->whereNull('revoked_at')->whereDate('start_date', '<=', today())
                    ->where(fn ($end) => $end->whereNull('end_date')->orWhereDate('end_date', '>=', today())));
            }
        }

        return $query->with('person')->limit(500)->get()->map(function (TeacherProfile $teacher) use ($roles, $classes, $classIds): array {
            $groups = collect($roles[$teacher->id] ?? [])->filter(fn (string $role, int $id) => $classIds->contains($id));
            $names = $groups->keys()->map(fn (int $id) => $classes[$id]->name)->sort()->implode(', ');
            $label = $groups->isNotEmpty() && $groups->every(fn (string $role) => $role === 'Auxiliar') ? 'Professor auxiliar' : 'Professor';

            return ['person' => $teacher->person, 'kind' => 'teacher', 'context' => $label.($names !== '' ? ' · '.$names : ''), 'class_ids' => $groups->keys()->all()];
        });
    }
}
