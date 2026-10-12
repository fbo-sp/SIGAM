<?php

namespace App\Services\Classes;

use App\Models\Association;
use App\Models\ClassEnrollment;
use App\Models\ClassGroup;
use App\Models\ClassSchedule;
use App\Models\Dojo;
use App\Models\MartialArt;
use App\Models\Student;
use App\Models\TeacherProfile;
use App\Services\Attendance\FutureSessionMaintainer;
use App\Services\AuditService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ClassGroupService
{
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
            $age = $student->person->birth_date === null ? null : (int) $student->person->birth_date->diffInYears($start);
            if ($age === null || ($lockedGroup->minimum_age !== null && $age < $lockedGroup->minimum_age) || ($lockedGroup->maximum_age !== null && $age > $lockedGroup->maximum_age)) {
                throw ValidationException::withMessages(['student_id' => 'A idade do aluno não atende à faixa etária da turma.']);
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

    private function teacher(Association $association, MartialArt $martialArt, ?int $teacherId): ?TeacherProfile
    {
        if ($teacherId === null) {
            return null;
        }

        return TeacherProfile::query()->whereKey($teacherId)->where('status', 'active')->whereHas('person.associations', fn ($query) => $query->whereKey($association->id))->whereHas('martialArts', fn ($query) => $query->where('martial_art_id', $martialArt->id)->where('status', 'active'))->firstOrFail();
    }

    private function validatedSchedules(Association $association, MartialArt $martialArt, array $rows, string $validFrom, ?string $validUntil, ?int $defaultTeacherId, ?int $excludeGroupId = null): array
    {
        $result = [];
        foreach ($rows as $index => $row) {
            if (($row['end_time'] ?? '') <= ($row['start_time'] ?? '')) {
                throw ValidationException::withMessages(["schedules.$index.end_time" => 'O término deve ser posterior ao início.']);
            }
            $teacher = $this->teacher($association, $martialArt, $row['teacher_id'] ?? $defaultTeacherId);
            if ($teacher !== null) {
                $conflict = ClassSchedule::query()->where('weekday', $row['weekday'])
                    ->where('start_time', '<', $row['end_time'])->where('end_time', '>', $row['start_time'])
                    ->where('valid_from', '<=', $validUntil ?: '9999-12-31')
                    ->where(fn ($query) => $query->whereNull('valid_until')->orWhere('valid_until', '>=', $validFrom))
                    ->when($excludeGroupId, fn ($query) => $query->where('class_group_id', '!=', $excludeGroupId))
                    ->whereHas('classGroup', fn ($query) => $query->where('association_id', $association->id)->where('status', 'active'))
                    ->where(fn ($query) => $query->where('teacher_id', $teacher->id)
                        ->orWhere(fn ($fallback) => $fallback->whereNull('teacher_id')->whereHas('classGroup', fn ($group) => $group->where('main_teacher_id', $teacher->id))))
                    ->exists();
                if ($conflict) {
                    throw ValidationException::withMessages(["schedules.$index.start_time" => 'O professor já possui outra turma neste dia e horário.']);
                }
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
