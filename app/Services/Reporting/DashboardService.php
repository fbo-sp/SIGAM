<?php

namespace App\Services\Reporting;

use App\Models\AttendanceRecord;
use App\Models\Championship;
use App\Models\ChampionshipRegistration;
use App\Models\Charge;
use App\Models\Discount;
use App\Models\FederationMembership;
use App\Models\GratuityRequest;
use App\Models\Payment;
use App\Models\Student;
use App\Models\User;
use App\Services\Classes\TeacherWorkspaceService;
use App\Services\People\BirthdayService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class DashboardService
{
    public function __construct(private readonly TeacherWorkspaceService $workspace, private readonly BirthdayService $birthdays) {}

    public function for(User $user): array
    {
        if (! $user->association_id) {
            return ['kind' => 'personal', 'metrics' => [], 'lists' => []];
        }
        if ($user->hasPermission('dashboard.association', $user->association_id)) {
            return $this->organization($user, null, 'association');
        }
        if ($user->hasPermission('dashboard.dojo', $user->association_id) && $user->dojo_id) {
            abort_unless($user->dojo()->where('association_id', $user->association_id)->where('status', 'active')->exists(), 403, 'Vínculo de dojo inválido. Procure a administração.');

            return $this->organization($user, $user->dojo_id, 'dojo');
        }
        if ($user->hasPermission('dashboard.teacher', $user->association_id)) {
            return $this->teacher($user);
        }

        return ['kind' => 'personal', 'metrics' => [], 'lists' => []];
    }

    private function organization(User $user, ?int $dojoId, string $kind): array
    {
        $associationId = $user->association_id;
        $students = Student::query()->where('association_id', $associationId)->when($dojoId, fn (Builder $query) => $query->where('primary_dojo_id', $dojoId));
        $charges = Charge::query()->where('association_id', $associationId)->when($dojoId, fn (Builder $query) => $query->where('dojo_id', $dojoId));
        $payments = Payment::query()->where('association_id', $associationId)->where('status', 'valid')->when($dojoId, fn (Builder $query) => $query->whereHas('charge', fn (Builder $charge) => $charge->where('dojo_id', $dojoId)));
        $attendance = AttendanceRecord::query()->whereHas('session.classGroup', fn (Builder $query) => $query->where('association_id', $associationId)->when($dojoId, fn (Builder $scope) => $scope->where('dojo_id', $dojoId)))->whereHas('session', fn (Builder $query) => $query->whereBetween('session_date', [today()->startOfMonth(), today()->endOfMonth()]));
        $attendanceTotal = (clone $attendance)->count();
        $attendancePresent = (clone $attendance)->where('status', 'present')->count();

        $metrics = [
            'active_students' => (clone $students)->where('status', 'active')->count(),
            'new_students' => (clone $students)->whereBetween('entry_date', [today()->startOfMonth(), today()->endOfMonth()])->count(),
            'departures' => (clone $students)->whereBetween('exit_date', [today()->startOfMonth(), today()->endOfMonth()])->count(),
            'attendance_rate' => $attendanceTotal > 0 ? (int) round(($attendancePresent * 100) / $attendanceTotal) : 0,
            'overdue_count' => (clone $charges)->pastDue()->count(),
            'overdue_balance' => (string) ((clone $charges)->pastDue()->sum('balance_amount') ?: '0.00'),
            'expected_revenue' => (string) ((clone $charges)->whereBetween('competence', [today()->startOfMonth(), today()->endOfMonth()])->where('status', '!=', 'cancelled')->sum('final_amount') ?: '0.00'),
            'received_revenue' => (string) ((clone $payments)->whereBetween('payment_date', [today()->startOfMonth(), today()->endOfMonth()])->sum('amount') ?: '0.00'),
            'discounts' => Discount::query()->whereHas('student', fn (Builder $student) => $student->where('association_id', $associationId)->when($dojoId, fn (Builder $scope) => $scope->where('primary_dojo_id', $dojoId)))->where('status', 'active')->count(),
            'gratuities' => GratuityRequest::query()->where('association_id', $associationId)->when($dojoId, fn (Builder $query) => $query->where('dojo_id', $dojoId))->where('status', 'approved')->count(),
            'memberships' => FederationMembership::query()->where('association_id', $associationId)->where('status', 'active')->when($dojoId, fn (Builder $query) => $query->whereHas('student', fn (Builder $student) => $student->where('primary_dojo_id', $dojoId)))->count(),
            'championship_registrations' => ChampionshipRegistration::query()->where('association_id', $associationId)->when($dojoId, fn (Builder $query) => $query->where('dojo_id', $dojoId))->count(),
        ];
        $byDojo = $kind === 'association' ? Student::query()->where('students.association_id', $associationId)->where('students.status', 'active')->join('dojos', 'dojos.id', '=', 'students.primary_dojo_id')->selectRaw('dojos.name, COUNT(students.id) AS total')->groupBy('dojos.id', 'dojos.name')->orderByDesc('total')->limit(8)->get() : collect();
        $byModality = Student::query()->where('students.association_id', $associationId)->where('students.status', 'active')->when($dojoId, fn (Builder $query) => $query->where('students.primary_dojo_id', $dojoId))->join('student_martial_arts', 'student_martial_arts.student_id', '=', 'students.id')->join('martial_arts', 'martial_arts.id', '=', 'student_martial_arts.martial_art_id')->where('student_martial_arts.status', 'active')->selectRaw('martial_arts.name, COUNT(DISTINCT students.id) AS total')->groupBy('martial_arts.id', 'martial_arts.name')->orderByDesc('total')->limit(8)->get();

        return ['kind' => $kind, 'metrics' => $metrics, 'lists' => ['by_dojo' => $byDojo, 'by_modality' => $byModality], 'dojo' => $dojoId ? $user->dojo : null,
            'agenda' => $kind === 'dojo' && $this->workspace->usesNavigation($user) ? $this->workspace->agenda($user) : null,
            'championships' => $kind === 'dojo' ? $this->championships($user) : collect(),
            'birthdays' => $this->managerBirthdays($user, $kind)];
    }

    private function teacher(User $user): array
    {
        $agenda = $this->workspace->agenda($user);

        return ['kind' => 'teacher', 'agenda' => $agenda, 'metrics' => [
            'today_classes' => $agenda['today']->count(),
            'pending_attendance' => $agenda['pending']->count() + $agenda['today']->where('session.attendance_state', 'open')->count(),
            'classes' => $this->workspace->groups($user)->count(),
        ], 'championships' => $this->championships($user), 'birthdays' => $this->teacherBirthdays($user)];
    }

    /** Cartão do painel do gestor: aniversariantes de hoje e total do mês, no alcance de quem vê os alunos. */
    private function managerBirthdays(User $user, string $kind): ?array
    {
        $context = $kind === 'association' ? $this->birthdays->association($user, $user->association) : ($user->dojo ? $this->birthdays->dojo($user, $user->dojo) : null);
        if (! $context) {
            return null;
        }
        $month = $this->birthdays->month($context, today()->year, today()->month);

        return ['today' => $month->where('today', true)->values(), 'month_count' => $month->count(),
            'url' => $kind === 'association' ? route('associations.birthdays', $user->association_id) : route('operation.birthdays', $user->dojo_id)];
    }

    /** Tela Hoje do professor: aviso de quem faz aniversário hoje e a lista do mês das turmas dele. */
    private function teacherBirthdays(User $user): ?array
    {
        $context = $this->birthdays->teacher($user);
        if (! $context) {
            return null;
        }
        $month = $this->birthdays->month($context, today()->year, today()->month);

        return ['today' => $month->where('today', true)->reject(fn (array $row) => $row['person']->id === $user->person_id)->values(), 'month' => $month];
    }

    private function championships(User $user): Collection
    {
        return Championship::query()->where('association_id', $user->association_id)->where('status', 'open')->where('registration_close_at', '>=', now())->orderBy('start_date')->limit(5)->get();
    }
}
