<?php

namespace App\Services\Attendance;

use App\Models\Association;
use App\Models\ClassSession;
use App\Models\Student;
use App\Models\User;
use App\Services\Access\OperationalScope;
use App\Services\AuditService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AttendanceService
{
    /** A chamada grava só SIM (presente) ou NÃO (ausente); os demais status vêm de registros antigos e da importação. */
    public const RECORDABLE_STATUSES = ['present', 'absent'];

    /** Status antigos que equivalem a SIM ou NÃO e são mantidos quando o professor confirma a mesma resposta. */
    private const EQUIVALENT_STATUSES = ['present' => ['makeup', 'trial'], 'absent' => ['excused_absence']];

    public function __construct(private readonly AuditService $audit, private readonly FutureSessionMaintainer $futureSessions) {}

    public function save(Association $association, ClassSession $session, User $user, array $records, bool $close): void
    {
        DB::transaction(function () use ($association, $session, $user, $records, $close): void {
            $locked = ClassSession::query()->lockForUpdate()->with('classGroup')->findOrFail($session->id);
            $this->assertSession($association, $locked);
            abort_unless($user->hasPermission('attendance.manage', $association->id) || app(OperationalScope::class)->canSession($user, $locked, 'attendance.manage'), 403);
            if ($locked->attendance_state !== 'open') {
                throw ValidationException::withMessages(['attendance' => 'A chamada está fechada. Reabra antes de alterar.']);
            }
            if ($locked->isBeforeAttendanceDay()) {
                throw ValidationException::withMessages(['attendance' => 'Esta aula ainda não aconteceu. A chamada abre no dia '.$locked->session_date->format('d/m/Y').'.']);
            }
            $roster = $this->roster($locked)->keyBy(fn (array $row) => $row['student']->id);
            $existing = $locked->records()->pluck('status', 'student_id');
            foreach ($records as $record) {
                $row = $roster->get((int) $record['student_id']);
                if ($row === null) {
                    throw ValidationException::withMessages(['attendance' => 'A lista contém um aluno sem matrícula ou visita autorizada nesta aula.']);
                }
                // Aluno em branco (nem SIM nem NÃO) não é gravado; a chamada só fecha com todos marcados.
                if (blank($record['status'] ?? null)) {
                    continue;
                }
                $status = in_array($existing->get($row['student']->id), self::EQUIVALENT_STATUSES[$record['status']] ?? [], true) ? $existing->get($row['student']->id) : $record['status'];
                $locked->records()->updateOrCreate(['student_id' => $record['student_id']], ['participation_type' => $row['type'], 'status' => $status, 'notes' => $record['notes'] ?? null, 'recorded_at' => now(), 'recorded_by_user_id' => $user->id]);
            }
            if ($close) {
                $expectedIds = $roster->keys()->map(fn ($id) => (int) $id)->all();
                $recorded = $locked->records()->whereIn('student_id', $expectedIds)->pluck('student_id')->map(fn ($id) => (int) $id)->sort()->values()->all();
                $expected = collect($expectedIds)->sort()->values()->all();
                if ($recorded !== $expected) {
                    throw ValidationException::withMessages(['attendance' => 'Marque SIM ou NÃO para todos os alunos antes de finalizar a chamada.']);
                }
                $locked->visitors()->whereIn('status', ['authorized', 'attended', 'no_show'])->get()->each(function ($visitor) use ($locked): void {
                    $status = $locked->records()->where('student_id', $visitor->student_id)->value('status');
                    $visitor->update(['status' => in_array($status, ['present', 'makeup', 'trial'], true) ? 'attended' : 'no_show']);
                });
                $locked->update(['attendance_state' => 'closed', 'status' => 'held', 'closed_by_user_id' => $user->id, 'closed_at' => now()]);
                $this->audit->record('attendance.closed', 'updated', $locked, ['attendance_state' => 'open'], ['attendance_state' => 'closed']);
                $this->futureSessions->maintain($locked->class_group_id);
            } else {
                $this->audit->record('attendance.saved', 'updated', $locked, null, ['record_count' => count($records)]);
            }
        });
    }

    public function reopen(Association $association, ClassSession $session, User $user, string $reason): void
    {
        DB::transaction(function () use ($association, $session, $user, $reason): void {
            $locked = ClassSession::query()->lockForUpdate()->with('classGroup')->findOrFail($session->id);
            $this->assertSession($association, $locked);
            abort_unless($user->hasPermission('attendance.reopen', $association->id) || app(OperationalScope::class)->canSession($user, $locked, 'attendance.reopen'), 403);
            if ($locked->attendance_state !== 'closed') {
                throw ValidationException::withMessages(['attendance' => 'A chamada já está aberta.']);
            }
            $locked->update(['attendance_state' => 'open', 'reopened_by_user_id' => $user->id, 'reopened_at' => now(), 'reopen_reason' => $reason]);
            $this->audit->record('attendance.reopened', 'updated', $locked, ['attendance_state' => 'closed'], ['attendance_state' => 'open'], ['reason' => $reason]);
        });
    }

    public function reschedule(Association $association, ClassSession $session, array $data): ClassSession
    {
        return DB::transaction(function () use ($association, $session, $data): ClassSession {
            $locked = ClassSession::query()->lockForUpdate()->with('classGroup')->findOrFail($session->id);
            $this->assertSession($association, $locked);
            if ($locked->status !== 'planned') {
                throw ValidationException::withMessages(['session' => 'Somente uma aula prevista pode ser reagendada.']);
            }
            $new = ClassSession::query()->create(['class_group_id' => $locked->class_group_id, 'rescheduled_from_id' => $locked->id, 'session_date' => $data['session_date'], 'start_time' => $data['start_time'], 'end_time' => $data['end_time'], 'planned_teacher_id' => $locked->planned_teacher_id, 'status' => 'planned', 'attendance_state' => 'open', 'notes' => $data['reason']]);
            $locked->update(['status' => 'rescheduled', 'notes' => $data['reason']]);
            $this->audit->record('class_session.rescheduled', 'updated', $locked, ['status' => 'planned'], ['status' => 'rescheduled', 'new_session_id' => $new->id]);
            $this->futureSessions->maintain($locked->class_group_id);

            return $new;
        });
    }

    public function cancel(Association $association, ClassSession $session, string $reason): void
    {
        DB::transaction(function () use ($association, $session, $reason): void {
            $locked = ClassSession::query()->lockForUpdate()->with('classGroup')->findOrFail($session->id);
            $this->assertSession($association, $locked);
            if ($locked->status !== 'planned') {
                throw ValidationException::withMessages(['session' => 'Somente uma aula prevista pode ser cancelada.']);
            }
            $locked->update(['status' => 'cancelled', 'cancellation_reason' => $reason]);
            $this->audit->record('class_session.cancelled', 'updated', $locked, ['status' => 'planned'], ['status' => 'cancelled'], ['reason' => $reason]);
            $this->futureSessions->maintain($locked->class_group_id);
        });
    }

    /** @return Collection<int, array{student: Student, type: string}> */
    public function roster(ClassSession $session): Collection
    {
        $regular = $session->classGroup->enrollments()->coveringDate($session->session_date)
            ->whereHas('student', fn ($query) => $query->where('association_id', $session->classGroup->association_id))
            ->with('student.person')->get()->unique('student_id')->map(fn ($enrollment) => ['student' => $enrollment->student, 'type' => 'regular']);
        $regularIds = $regular->pluck('student.id');
        $visitors = $session->visitors()->whereIn('status', ['authorized', 'attended', 'no_show'])->whereNotIn('student_id', $regularIds)
            ->where('association_id', $session->classGroup->association_id)->with('student.person')->get()
            ->map(fn ($visitor) => ['student' => $visitor->student, 'type' => 'visitor', 'visitor' => $visitor]);

        return $regular->concat($visitors)->values();
    }

    private function assertSession(Association $association, ClassSession $session): void
    {
        abort_unless($session->classGroup->association_id === $association->id, 404);
        if (in_array($session->status, ['cancelled', 'rescheduled'], true)) {
            throw ValidationException::withMessages(['session' => 'Esta aula não aceita chamada.']);
        }
    }
}
