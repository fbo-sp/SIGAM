<?php

namespace App\Services\Classes;

use App\Models\Association;
use App\Models\AttendanceRecord;
use App\Models\ClassEnrollment;
use App\Models\ClassGroup;
use App\Models\ClassGroupTeacher;
use App\Models\ClassSchedule;
use App\Models\Dojo;
use App\Models\DojoTeacherAssignment;
use App\Models\MartialArt;
use App\Models\Student;
use App\Models\TeacherProfile;
use App\Services\Attendance\FutureSessionMaintainer;
use App\Services\AuditService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ClassGroupService
{
    private const EDITABLE = ['name', 'description', 'main_teacher_id', 'age_group', 'minimum_age', 'maximum_age', 'capacity', 'future_session_target', 'end_date', 'status'];

    public function __construct(private readonly AuditService $audit, private readonly FutureSessionMaintainer $futureSessions) {}

    public function create(Association $association, array $data): ClassGroup
    {
        return DB::transaction(function () use ($association, $data): ClassGroup {
            if (! Association::query()->whereKey($association->id)->where('status', 'active')->exists()) {
                throw ValidationException::withMessages(['association' => 'Ative a associação antes de criar turmas.']);
            }
            $dojo = Dojo::query()->where('association_id', $association->id)->where('status', 'active')->findOrFail($data['dojo_id']);
            $martialArt = MartialArt::query()->where(fn ($query) => $query->whereNull('association_id')->orWhere('association_id', $association->id))->where('status', 'active')->findOrFail($data['martial_art_id']);
            $teacher = $this->teacher($association, $martialArt, $data['main_teacher_id'] ?? null);
            $schedules = $this->validatedSchedules($association, $martialArt, $data['schedules'], $data['start_date'], $data['end_date'] ?? null, $teacher?->id);
            $group = ClassGroup::query()->create([
                'association_id' => $association->id, 'dojo_id' => $dojo->id, 'martial_art_id' => $martialArt->id,
                'name' => $data['name'], 'description' => $data['description'] ?? null, 'main_teacher_id' => $teacher?->id,
                'age_group' => $data['age_group'] ?? null, 'minimum_age' => $data['minimum_age'] ?? null, 'maximum_age' => $data['maximum_age'] ?? null,
                'capacity' => $data['capacity'] ?? null, 'future_session_target' => $data['future_session_target'],
                'start_date' => $data['start_date'], 'end_date' => $data['end_date'] ?? null, 'status' => 'active',
            ]);
            foreach ($schedules as $schedule) {
                $group->schedules()->create([...$schedule, 'valid_from' => $data['start_date'], 'status' => 'active']);
            }
            $this->audit->record('class_group.created', 'created', $group, null, ['name' => $group->name, 'status' => 'active', 'schedule_count' => count($schedules)]);
            $this->futureSessions->maintain($group->id);

            return $group;
        });
    }

    /** @return array{created: int, cancelled: int, preserved: int} */
    public function replaceScheduleSet(Association $association, ClassGroup $group, array $data): array
    {
        return DB::transaction(function () use ($association, $group, $data): array {
            $lockedGroup = ClassGroup::query()->with('martialArt')->lockForUpdate()->findOrFail($group->id);
            abort_unless($lockedGroup->association_id === $association->id, 404);
            $validFrom = Carbon::parse($data['valid_from'])->startOfDay();
            $active = $lockedGroup->schedules()->where('status', 'active')->lockForUpdate()->get();
            if ($active->isEmpty() || $active->contains(fn (ClassSchedule $schedule) => $validFrom->lte($schedule->valid_from))) {
                throw ValidationException::withMessages(['valid_from' => 'A nova vigência deve começar depois da agenda atual.']);
            }
            $schedules = $this->validatedSchedules(
                $association,
                $lockedGroup->martialArt,
                $data['schedules'],
                $validFrom->toDateString(),
                $lockedGroup->end_date?->toDateString(),
                $lockedGroup->main_teacher_id,
                $lockedGroup->id,
            );
            $oldIds = $active->pluck('id');
            $active->each->update(['valid_until' => $validFrom->copy()->subDay()->toDateString(), 'status' => 'replaced']);
            $newSchedules = collect($schedules)->map(fn (array $schedule) => $lockedGroup->schedules()->create([
                ...$schedule, 'valid_from' => $validFrom->toDateString(), 'status' => 'active',
            ]));

            $future = $lockedGroup->sessions()->whereIn('class_schedule_id', $oldIds)->whereDate('session_date', '>=', $validFrom)
                ->withCount('records')->lockForUpdate()->get();
            $cancelled = 0;
            $preserved = 0;
            foreach ($future as $session) {
                if ($session->status !== 'planned' || $session->attendance_state !== 'open' || $session->records_count > 0) {
                    $preserved++;

                    continue;
                }
                $replacement = $newSchedules->first(fn (ClassSchedule $schedule) => $schedule->weekday === $session->session_date->dayOfWeekIso
                    && substr($schedule->start_time, 0, 5) === substr($session->start_time, 0, 5));
                if ($replacement) {
                    $session->update([
                        'class_schedule_id' => $replacement->id,
                        'end_time' => $replacement->end_time,
                        'planned_teacher_id' => $replacement->teacher_id ?: $lockedGroup->main_teacher_id,
                    ]);
                    $preserved++;
                } else {
                    $session->update(['status' => 'cancelled', 'cancellation_reason' => 'Agenda substituída a partir desta data.']);
                    $cancelled++;
                }
            }
            $created = $this->futureSessions->maintain($lockedGroup->id)['created'];
            $this->audit->record('class_schedule.set_replaced', 'updated', $lockedGroup, ['schedule_ids' => $oldIds->all()], [
                'schedule_ids' => $newSchedules->pluck('id')->all(), 'valid_from' => $validFrom->toDateString(),
                'sessions_created' => $created, 'sessions_cancelled' => $cancelled, 'sessions_preserved' => $preserved,
            ], ['association_id' => $association->id]);

            return compact('created', 'cancelled', 'preserved');
        }, 3);
    }

    public function replaceSchedule(Association $association, ClassGroup $group, ClassSchedule $schedule, array $data): ClassSchedule
    {
        return DB::transaction(function () use ($association, $group, $schedule, $data): ClassSchedule {
            $validFrom = Carbon::parse($data['valid_from'])->startOfDay();
            if ($validFrom->lte($schedule->valid_from)) {
                throw ValidationException::withMessages(['valid_from' => 'A nova vigência deve começar depois da vigência atual.']);
            }
            $teacher = $this->teacher($association, $group->martialArt, $data['teacher_id'] ?? $group->main_teacher_id);
            $schedule->update(['valid_until' => $validFrom->copy()->subDay()->toDateString(), 'status' => 'replaced']);
            $new = $group->schedules()->create([...$data, 'teacher_id' => $teacher?->id, 'status' => 'active']);
            $this->audit->record('class_schedule.replaced', 'updated', $group, ['schedule_id' => $schedule->id], ['schedule_id' => $new->id], ['association_id' => $association->id]);
            $this->futureSessions->maintain($group->id);

            return $new;
        });
    }

    public function enroll(Association $association, ClassGroup $group, array $data): ClassEnrollment
    {
        return DB::transaction(function () use ($association, $group, $data): ClassEnrollment {
            $lockedGroup = ClassGroup::query()->lockForUpdate()->findOrFail($group->id);
            $student = Student::query()->with('person')->where('association_id', $association->id)->where('status', 'active')->findOrFail($data['student_id']);
            $start = Carbon::parse($data['start_date'])->startOfDay();
            if ($lockedGroup->status !== 'active' || $start->lt($lockedGroup->start_date) || ($lockedGroup->end_date && $start->gt($lockedGroup->end_date))) {
                throw ValidationException::withMessages(['start_date' => 'A turma não está ativa nessa data.']);
            }
            if (! $student->dojoHistory()->where('dojo_id', $lockedGroup->dojo_id)->where('start_date', '<=', $start)->where(fn ($query) => $query->whereNull('end_date')->orWhere('end_date', '>=', $start))->exists()) {
                throw ValidationException::withMessages(['student_id' => 'O aluno não possui vínculo vigente com o dojo da turma.']);
            }
            if (! $student->martialArts()->where('martial_art_id', $lockedGroup->martial_art_id)->where('status', 'active')->where('start_date', '<=', $start)->where(fn ($query) => $query->whereNull('end_date')->orWhere('end_date', '>=', $start))->exists()) {
                throw ValidationException::withMessages(['student_id' => 'O aluno não pratica a modalidade desta turma.']);
            }
            if ($lockedGroup->minimum_age !== null || $lockedGroup->maximum_age !== null) {
                if ($student->person->birth_date === null) {
                    throw ValidationException::withMessages(['student_id' => 'Informe a data de nascimento do aluno: esta turma tem faixa etária.']);
                }
                $age = (int) $student->person->birth_date->diffInYears($start);
                if (($lockedGroup->minimum_age !== null && $age < $lockedGroup->minimum_age) || ($lockedGroup->maximum_age !== null && $age > $lockedGroup->maximum_age)) {
                    throw ValidationException::withMessages(['student_id' => 'A idade do aluno não atende à faixa etária da turma.']);
                }
            }
            if ($lockedGroup->enrollments()->where('student_id', $student->id)->where('status', 'active')->where(fn ($query) => $query->whereNull('end_date')->orWhere('end_date', '>=', $start))->exists()) {
                throw ValidationException::withMessages(['student_id' => 'Este aluno já possui matrícula ativa na turma.']);
            }
            if ($lockedGroup->capacity !== null && $lockedGroup->enrollments()->where('status', 'active')->where(fn ($query) => $query->whereNull('end_date')->orWhere('end_date', '>=', $start))->count() >= $lockedGroup->capacity) {
                throw ValidationException::withMessages(['student_id' => 'A turma atingiu sua capacidade.']);
            }
            $enrollment = $lockedGroup->enrollments()->create(['student_id' => $student->id, 'start_date' => $start->toDateString(), 'status' => 'active']);
            $this->audit->record('class_enrollment.created', 'created', $enrollment, null, ['student_id' => $student->id], ['association_id' => $association->id]);

            return $enrollment;
        });
    }

    /** Edita os dados da turma. Trocar o professor principal atualiza horários e aulas futuras ainda sem chamada. */
    public function update(Association $association, ClassGroup $group, array $data): ClassGroup
    {
        return DB::transaction(function () use ($association, $group, $data): ClassGroup {
            $locked = ClassGroup::query()->with('martialArt')->lockForUpdate()->findOrFail($group->id);
            abort_unless($locked->association_id === $association->id, 404);
            $before = $locked->only(self::EDITABLE);
            $teacher = $this->qualifiedTeacher($association, $locked->martialArt, $data['main_teacher_id'] ?? null, 'main_teacher_id');
            $status = $data['status'];
            $endDate = isset($data['end_date']) ? Carbon::parse($data['end_date'])->startOfDay() : null;

            $activeEnrollments = $locked->enrollments()->where('status', 'active')->count();
            if (($data['capacity'] ?? null) !== null && (int) $data['capacity'] < $activeEnrollments) {
                throw ValidationException::withMessages(['capacity' => "A turma já tem {$activeEnrollments} aluno(s) matriculado(s). Encerre matrículas antes de reduzir a capacidade."]);
            }
            if ($endDate !== null && $endDate->lt($locked->start_date)) {
                throw ValidationException::withMessages(['end_date' => 'O término não pode ser anterior ao início da turma.']);
            }
            $closingFrom = $status === 'active' ? $endDate?->copy()->addDay() : today()->addDay()->max($locked->start_date);
            if ($closingFrom !== null && $locked->sessions()->whereDate('session_date', '>=', $closingFrom)->whereHas('records')->exists()) {
                throw ValidationException::withMessages([$status === 'active' ? 'end_date' : 'status' => 'Já existe chamada registrada depois dessa data.']);
            }

            $oldTeacherId = $locked->main_teacher_id;
            $newTeacherId = $teacher?->id;
            $teacherChanged = $oldTeacherId !== $newTeacherId;
            $schedules = $locked->schedules()->where('status', 'active')->lockForUpdate()->get();
            if ($teacherChanged && $newTeacherId !== null) {
                foreach ($schedules->filter(fn (ClassSchedule $schedule) => $schedule->teacher_id === null || $schedule->teacher_id === $oldTeacherId) as $schedule) {
                    $from = today()->max($schedule->valid_from)->toDateString();
                    $until = $schedule->valid_until?->toDateString() ?? $endDate?->toDateString();
                    if ($this->teacherHasConflict($association, $newTeacherId, $schedule->weekday, $schedule->start_time, $schedule->end_time, $from, $until, $locked->id)) {
                        throw ValidationException::withMessages(['main_teacher_id' => 'O professor escolhido já tem outra turma em um dos horários desta turma.']);
                    }
                }
            }

            $locked->update([
                'name' => $data['name'], 'description' => $data['description'] ?? null, 'main_teacher_id' => $newTeacherId,
                'age_group' => $data['age_group'] ?? null, 'minimum_age' => $data['minimum_age'] ?? null, 'maximum_age' => $data['maximum_age'] ?? null,
                'capacity' => $data['capacity'] ?? null, 'future_session_target' => $data['future_session_target'],
                'end_date' => $endDate?->toDateString(), 'status' => $status,
            ]);

            $reassigned = 0;
            if ($teacherChanged) {
                if ($oldTeacherId !== null) {
                    $schedules->where('teacher_id', $oldTeacherId)->each->update(['teacher_id' => $newTeacherId]);
                }
                $reassigned = $this->openFutureSessions($locked, today())
                    ->where(fn ($query) => $query->whereNull('planned_teacher_id')->when($oldTeacherId, fn ($q) => $q->orWhere('planned_teacher_id', $oldTeacherId)))
                    ->update(['planned_teacher_id' => $newTeacherId]);
            }
            // Auxiliar promovido a principal: a designação de auxiliar termina e vale a de principal.
            // Até ontem ela continua cobrindo as aulas anteriores, então a chamada pendente segue acessível.
            $promoted = 0;
            if ($teacherChanged && $newTeacherId !== null) {
                $assistant = $locked->teacherAssignments()->where('teacher_profile_id', $newTeacherId)
                    ->where(fn ($query) => $query->whereNull('end_date')->orWhereDate('end_date', '>=', today()))->lockForUpdate()->get();
                foreach ($assistant as $assignment) {
                    $end = $assignment->start_date->copy()->max(today()->subDay())->toDateString();
                    $before = ['end_date' => $assignment->end_date?->toDateString()];
                    $assignment->update(['end_date' => $end]);
                    $this->audit->record('class_group_teacher.ended', 'updated', $assignment, $before, ['end_date' => $end, 'reason' => 'promoted_to_main'], ['association_id' => $association->id]);
                    $promoted++;
                }
            }
            $cancelled = $closingFrom === null ? 0 : $this->openFutureSessions($locked, $closingFrom)->update([
                'status' => 'cancelled',
                'cancellation_reason' => $status === 'active' ? 'Turma encerrada antes desta data.' : 'Turma inativada.',
            ]);

            $this->audit->record('class_group.updated', 'updated', $locked, $before, [
                ...$locked->only(self::EDITABLE), 'sessions_reassigned' => $reassigned, 'sessions_cancelled' => $cancelled, 'assistant_assignments_ended' => $promoted,
            ], ['association_id' => $association->id]);
            if ($status === 'active') {
                $this->futureSessions->maintain($locked->id);
            }

            return $locked->fresh();
        }, 3);
    }

    /** Encerra a matrícula sem apagar o aluno das chamadas anteriores ao término. */
    public function endEnrollment(Association $association, ClassEnrollment $enrollment, array $data): ClassEnrollment
    {
        return DB::transaction(function () use ($association, $enrollment, $data): ClassEnrollment {
            $locked = ClassEnrollment::query()->with('classGroup')->lockForUpdate()->findOrFail($enrollment->id);
            abort_unless($locked->classGroup->association_id === $association->id, 404);
            if ($locked->status !== 'active') {
                throw ValidationException::withMessages(['end_date' => 'Esta matrícula já foi encerrada.']);
            }
            $end = Carbon::parse($data['end_date'])->startOfDay();
            if ($end->lt($locked->start_date) || $end->gt(today())) {
                throw ValidationException::withMessages(['end_date' => 'Use uma data entre o início da matrícula e hoje.']);
            }
            $laterRecords = AttendanceRecord::query()->where('student_id', $locked->student_id)
                ->whereHas('session', fn ($query) => $query->where('class_group_id', $locked->class_group_id)->whereDate('session_date', '>', $end))->exists();
            if ($laterRecords) {
                throw ValidationException::withMessages(['end_date' => 'O aluno tem presença registrada depois dessa data.']);
            }
            $locked->update(['end_date' => $end->toDateString(), 'status' => 'ended', 'exit_reason' => $data['exit_reason'] ?? null]);
            $this->audit->record('class_enrollment.ended', 'updated', $locked, ['status' => 'active'], [
                'status' => 'ended', 'end_date' => $end->toDateString(),
            ], ['association_id' => $association->id]);

            return $locked;
        });
    }

    /** Designa professor auxiliar. Ele passa a ver e fazer a chamada das aulas da turma no período. */
    public function assignTeacher(Association $association, ClassGroup $group, array $data): ClassGroupTeacher
    {
        return DB::transaction(function () use ($association, $group, $data): ClassGroupTeacher {
            $locked = ClassGroup::query()->with('martialArt')->lockForUpdate()->findOrFail($group->id);
            abort_unless($locked->association_id === $association->id, 404);
            if ($locked->status !== 'active') {
                throw ValidationException::withMessages(['teacher_profile_id' => 'A turma não está ativa.']);
            }
            $teacher = $this->qualifiedTeacher($association, $locked->martialArt, (int) $data['teacher_profile_id'], 'teacher_profile_id');
            $start = Carbon::parse($data['start_date'])->startOfDay();
            $end = isset($data['end_date']) ? Carbon::parse($data['end_date'])->startOfDay() : null;
            if ($teacher->id === $locked->main_teacher_id) {
                throw ValidationException::withMessages(['teacher_profile_id' => 'Este professor já é o principal da turma.']);
            }
            if ($start->lt($locked->start_date) || ($locked->end_date && $start->gt($locked->end_date))) {
                throw ValidationException::withMessages(['start_date' => 'O início precisa estar dentro do período da turma.']);
            }
            $linked = DojoTeacherAssignment::query()->where('association_id', $association->id)->where('dojo_id', $locked->dojo_id)
                ->where('teacher_profile_id', $teacher->id)->whereNull('revoked_at')->whereDate('start_date', '<=', $start)
                ->where(fn ($query) => $query->whereNull('end_date')->orWhereDate('end_date', '>=', $start))->exists();
            if (! $linked) {
                throw ValidationException::withMessages(['teacher_profile_id' => 'Vincule o professor a este dojo antes de designá-lo para a turma.']);
            }
            $overlap = $locked->teacherAssignments()->where('teacher_profile_id', $teacher->id)
                ->where(fn ($query) => $query->whereNull('end_date')->orWhereDate('end_date', '>=', $start))
                ->when($end, fn ($query) => $query->whereDate('start_date', '<=', $end))->lockForUpdate()->exists();
            if ($overlap) {
                throw ValidationException::withMessages(['teacher_profile_id' => 'Este professor já está designado para a turma nesse período.']);
            }
            $assignment = $locked->teacherAssignments()->create([
                'teacher_profile_id' => $teacher->id, 'role_type' => 'assistant',
                'start_date' => $start->toDateString(), 'end_date' => $end?->toDateString(),
            ]);
            $this->audit->record('class_group_teacher.assigned', 'created', $assignment, null, [
                'teacher_profile_id' => $teacher->id, 'role_type' => 'assistant', 'start_date' => $start->toDateString(),
            ], ['association_id' => $association->id]);

            return $assignment;
        });
    }

    public function endTeacherAssignment(Association $association, ClassGroupTeacher $assignment, array $data): ClassGroupTeacher
    {
        return DB::transaction(function () use ($association, $assignment, $data): ClassGroupTeacher {
            $locked = ClassGroupTeacher::query()->with('classGroup')->lockForUpdate()->findOrFail($assignment->id);
            abort_unless($locked->classGroup->association_id === $association->id, 404);
            $end = Carbon::parse($data['end_date'])->startOfDay();
            if ($locked->end_date !== null && $locked->end_date->lt(today())) {
                throw ValidationException::withMessages(['end_date' => 'Esta designação já terminou.']);
            }
            if ($end->lt($locked->start_date)) {
                throw ValidationException::withMessages(['end_date' => 'Use uma data a partir do início da designação.']);
            }
            $before = ['end_date' => $locked->end_date?->toDateString()];
            $locked->update(['end_date' => $end->toDateString()]);
            $this->audit->record('class_group_teacher.ended', 'updated', $locked, $before, ['end_date' => $end->toDateString()], ['association_id' => $association->id]);

            return $locked;
        });
    }

    private function teacher(Association $association, MartialArt $martialArt, ?int $teacherId): ?TeacherProfile
    {
        if ($teacherId === null) {
            return null;
        }

        return TeacherProfile::query()->whereKey($teacherId)->where('status', 'active')->whereHas('person.associations', fn ($query) => $query->whereKey($association->id))->whereHas('martialArts', fn ($query) => $query->where('martial_art_id', $martialArt->id)->where('status', 'active'))->firstOrFail();
    }

    /** Como teacher(), mas devolve erro de validação no campo em vez de 404. */
    private function qualifiedTeacher(Association $association, MartialArt $martialArt, ?int $teacherId, string $field): ?TeacherProfile
    {
        try {
            return $this->teacher($association, $martialArt, $teacherId);
        } catch (ModelNotFoundException) {
            throw ValidationException::withMessages([$field => 'O professor precisa estar ativo na associação e habilitado na modalidade da turma.']);
        }
    }

    /** Aulas planejadas, abertas e sem nenhuma presença, a partir da data. */
    private function openFutureSessions(ClassGroup $group, Carbon $from): HasMany
    {
        return $group->sessions()->where('status', 'planned')->where('attendance_state', 'open')
            ->whereDate('session_date', '>=', $from)->whereDoesntHave('records');
    }

    private function teacherHasConflict(Association $association, int $teacherId, int $weekday, string $startTime, string $endTime, string $validFrom, ?string $validUntil, ?int $excludeGroupId): bool
    {
        return ClassSchedule::query()->where('weekday', $weekday)
            ->where('start_time', '<', $endTime)->where('end_time', '>', $startTime)
            ->where('valid_from', '<=', $validUntil ?: '9999-12-31')
            ->where(fn ($query) => $query->whereNull('valid_until')->orWhere('valid_until', '>=', $validFrom))
            ->when($excludeGroupId, fn ($query) => $query->where('class_group_id', '!=', $excludeGroupId))
            ->whereHas('classGroup', fn ($query) => $query->where('association_id', $association->id)->where('status', 'active'))
            ->where(fn ($query) => $query->where('teacher_id', $teacherId)
                ->orWhere(fn ($fallback) => $fallback->whereNull('teacher_id')->whereHas('classGroup', fn ($group) => $group->where('main_teacher_id', $teacherId))))
            ->exists();
    }

    private function validatedSchedules(Association $association, MartialArt $martialArt, array $rows, string $validFrom, ?string $validUntil, ?int $defaultTeacherId, ?int $excludeGroupId = null): array
    {
        $result = [];
        foreach ($rows as $index => $row) {
            if (($row['end_time'] ?? '') <= ($row['start_time'] ?? '')) {
                throw ValidationException::withMessages(["schedules.$index.end_time" => 'O término deve ser posterior ao início.']);
            }
            $teacher = $this->teacher($association, $martialArt, $row['teacher_id'] ?? $defaultTeacherId);
            if ($teacher !== null && $this->teacherHasConflict($association, $teacher->id, (int) $row['weekday'], $row['start_time'], $row['end_time'], $validFrom, $validUntil, $excludeGroupId)) {
                throw ValidationException::withMessages(["schedules.$index.start_time" => 'O professor já possui outra turma neste dia e horário.']);
            }
            $result[] = [
                'weekday' => (int) $row['weekday'],
                'start_time' => $row['start_time'],
                'end_time' => $row['end_time'],
                'teacher_id' => $teacher?->id,
                'location_name' => $row['location_name'] ?? null,
            ];
        }

        return $result;
    }
}
