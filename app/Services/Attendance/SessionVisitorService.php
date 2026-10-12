<?php

namespace App\Services\Attendance;

use App\Models\Association;
use App\Models\ClassSession;
use App\Models\ClassSessionVisitor;
use App\Models\Student;
use App\Models\User;
use App\Services\Access\OperationalScope;
use App\Services\AuditService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SessionVisitorService
{
    public function __construct(private readonly AuditService $audit, private readonly OperationalScope $scope) {}

    public function authorize(Association $association, ClassSession $session, User $actor, string $registrationNumber): ClassSessionVisitor
    {
        return DB::transaction(function () use ($association, $session, $actor, $registrationNumber): ClassSessionVisitor {
            $locked = ClassSession::query()->with(['classGroup.dojo', 'classGroup.martialArt'])->lockForUpdate()->findOrFail($session->id);
            $this->assertCanManage($association, $locked, $actor);
            if ($locked->status !== 'planned' || $locked->attendance_state !== 'open') {
                throw ValidationException::withMessages(['visitor' => 'Visitantes só podem ser incluídos enquanto a chamada estiver aberta e a aula estiver prevista.']);
            }
            $student = Student::query()->with('person')->where('association_id', $association->id)->where('status', 'active')
                ->where('registration_number', trim($registrationNumber))->lockForUpdate()->first();
            if ($student === null) {
                throw ValidationException::withMessages(['registration_number' => 'Aluno não localizado nesta associação. Confira a matrícula.']);
            }
            $existing = ClassSessionVisitor::query()->where('class_session_id', $locked->id)->where('student_id', $student->id)->lockForUpdate()->first();
            if ($existing !== null) {
                if (in_array($existing->status, ['authorized', 'attended', 'no_show'], true)) {
                    return $existing;
                }

                throw ValidationException::withMessages(['visitor' => 'Esta participação foi revogada e não pode ser reativada sem revisão do gestor.']);
            }
            if ($locked->classGroup->enrollments()->where('student_id', $student->id)->where('status', 'active')
                ->whereDate('start_date', '<=', $locked->session_date)
                ->where(fn ($query) => $query->whereNull('end_date')->orWhereDate('end_date', '>=', $locked->session_date))->exists()) {
                throw ValidationException::withMessages(['registration_number' => 'Este aluno já faz parte da lista regular desta aula.']);
            }
            if (! $student->martialArts()->where('martial_art_id', $locked->classGroup->martial_art_id)->where('status', 'active')
                ->whereDate('start_date', '<=', $locked->session_date)
                ->where(fn ($query) => $query->whereNull('end_date')->orWhereDate('end_date', '>=', $locked->session_date))->exists()) {
                throw ValidationException::withMessages(['registration_number' => 'O aluno não possui vínculo ativo com a modalidade desta aula.']);
            }
            $age = $student->person->birth_date?->diffInYears(Carbon::parse($locked->session_date));
            if ($age === null || ($locked->classGroup->minimum_age !== null && $age < $locked->classGroup->minimum_age)
                || ($locked->classGroup->maximum_age !== null && $age > $locked->classGroup->maximum_age)) {
                throw ValidationException::withMessages(['registration_number' => 'A idade do aluno não atende à faixa etária desta turma.']);
            }
            if ($association->requires_minor_guardian && $age < 18 && ! $student->guardians()->where('is_legal_guardian', true)
                ->whereDate('start_date', '<=', $locked->session_date)
                ->where(fn ($query) => $query->whereNull('end_date')->orWhereDate('end_date', '>=', $locked->session_date))->exists()) {
                throw ValidationException::withMessages(['registration_number' => 'O aluno menor precisa de responsável legal vigente para participar.']);
            }
            $regularCount = $locked->classGroup->enrollments()->where('status', 'active')->whereDate('start_date', '<=', $locked->session_date)
                ->where(fn ($query) => $query->whereNull('end_date')->orWhereDate('end_date', '>=', $locked->session_date))->count();
            $visitorCount = $locked->visitors()->whereIn('status', ['authorized', 'attended'])->count();
            if ($locked->classGroup->capacity !== null && $regularCount + $visitorCount >= $locked->classGroup->capacity) {
                throw ValidationException::withMessages(['visitor' => 'A aula atingiu a capacidade da turma.']);
            }
            $visit = ClassSessionVisitor::query()->create([
                'association_id' => $association->id,
                'host_dojo_id' => $locked->classGroup->dojo_id,
                'class_session_id' => $locked->id,
                'student_id' => $student->id,
                'status' => 'authorized',
                'authorized_by_user_id' => $actor->id,
                'authorized_at' => now(),
            ]);
            $this->audit->record('session_visitor.authorized', 'created', $visit, null, [
                'class_session_id' => $locked->id, 'student_id' => $student->id, 'status' => 'authorized',
            ], ['association_id' => $association->id]);

            return $visit;
        }, 3);
    }

    public function revoke(Association $association, ClassSession $session, ClassSessionVisitor $visitor, User $actor, string $reason): void
    {
        DB::transaction(function () use ($association, $session, $visitor, $actor, $reason): void {
            $lockedSession = ClassSession::query()->with('classGroup.dojo')->lockForUpdate()->findOrFail($session->id);
            $this->assertCanManage($association, $lockedSession, $actor);
            $locked = ClassSessionVisitor::query()->lockForUpdate()->findOrFail($visitor->id);
            abort_unless($locked->class_session_id === $lockedSession->id && $locked->association_id === $association->id, 404);
            if ($lockedSession->attendance_state !== 'open' || $locked->status !== 'authorized'
                || $lockedSession->records()->where('student_id', $locked->student_id)->exists()) {
                throw ValidationException::withMessages(['visitor' => 'Esta participação já possui histórico e não pode ser revogada.']);
            }
            $locked->update(['status' => 'revoked', 'revoked_by_user_id' => $actor->id, 'revoked_at' => now(), 'revoke_reason' => $reason]);
            $this->audit->record('session_visitor.revoked', 'updated', $locked, ['status' => 'authorized'], ['status' => 'revoked'], [
                'association_id' => $association->id, 'reason' => $reason,
            ]);
        }, 3);
    }

    private function assertCanManage(Association $association, ClassSession $session, User $actor): void
    {
        abort_unless($session->classGroup->association_id === $association->id
            && $session->classGroup->dojo->association_id === $association->id, 404);
        abort_unless($actor->hasPermission('visitor.manage', $association->id)
            || $this->scope->canSession($actor, $session, 'visitor.manage'), 403);
    }
}
