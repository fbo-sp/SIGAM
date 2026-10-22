<?php

namespace App\Services\Notifications;

use App\Models\Association;
use App\Models\AttendanceRecord;
use App\Models\Championship;
use App\Models\Charge;
use App\Models\ClassGroup;
use App\Models\ClassSession;
use App\Models\Document;
use App\Models\FederationMembership;
use App\Models\GratuityRequest;
use App\Models\ProgressionSetting;
use App\Models\Student;
use App\Models\TechniqueAssessment;
use App\Models\User;
use App\Services\Access\OperationalScope;

class NotificationGenerator
{
    public function __construct(private readonly NotificationService $notifications, private readonly NotificationRecipientResolver $recipients, private readonly OperationalScope $scope) {}

    public function generate(?int $associationId = null): int
    {
        $count = 0;
        Association::query()->where('status', 'active')->when($associationId, fn ($query) => $query->whereKey($associationId))->each(function (Association $association) use (&$count): void {
            $count += $this->forAssociation($association);
        });

        return $count;
    }

    private function forAssociation(Association $association): int
    {
        $count = 0;
        Charge::query()->where('association_id', $association->id)->whereIn('status', ['open', 'partially_paid'])->whereBetween('due_date', [today(), today()->addDays(5)])->with('student.person')->each(function (Charge $charge) use ($association, &$count): void {
            if ($charge->student) {
                $this->publishStudent($association, $charge->student, 'charge.due_soon', 'charge-due-'.$charge->id.'-'.$charge->due_date->format('Ymd'), $charge->description, $charge->due_date->format('d/m/Y'));
                $count++;
            }
        });
        Charge::query()->where('association_id', $association->id)->pastDue()->with('student.person')->each(function (Charge $charge) use ($association, &$count): void {
            if ($charge->student) {
                $this->publishStudent($association, $charge->student, 'charge.overdue', 'charge-overdue-'.$charge->id, $charge->description, $charge->due_date->format('d/m/Y'));
                $count++;
            }
        });
        Document::query()->where('association_id', $association->id)->where('status', 'active')->whereNotNull('student_id')->whereBetween('expiration_date', [today(), today()->addDays(30)])->with(['student.person', 'type'])->each(function (Document $document) use ($association, &$count): void {
            $this->publishStudent($association, $document->student, 'document.expiring', 'document-expiring-'.$document->id.'-'.$document->expiration_date->format('Ymd'), $document->type->name, $document->expiration_date->format('d/m/Y'));
            $count++;
        });
        FederationMembership::query()->where('association_id', $association->id)->where('status', 'active')->whereBetween('expiration_date', [today(), today()->addDays(30)])->with(['student.person', 'federation'])->each(function (FederationMembership $membership) use ($association, &$count): void {
            $this->publishStudent($association, $membership->student, 'membership.expiring', 'membership-expiring-'.$membership->id.'-'.$membership->expiration_date->format('Ymd'), $membership->federation->name, $membership->expiration_date->format('d/m/Y'));
            $count++;
        });

        $portalUsers = User::query()->where('association_id', $association->id)->where('status', 'active')->with('person')->get()->filter(fn (User $user) => $user->hasPermission('portal.access', $association->id))->values();
        Championship::query()->where('association_id', $association->id)->where('status', 'open')->where('registration_open_at', '<=', now())->where('registration_close_at', '>=', now())->each(function (Championship $championship) use ($association, $portalUsers, &$count): void {
            $this->notifications->publish($association, 'championship.opened', 'championship-open-'.$championship->id, ['item' => $championship->name, 'date' => $championship->registration_close_at->format('d/m/Y'), 'student_name' => ''], $portalUsers);
            $count++;
            if ($championship->registration_close_at->between(now(), now()->addDays(3))) {
                $this->notifications->publish($association, 'championship.deadline', 'championship-deadline-'.$championship->id.'-'.$championship->registration_close_at->format('Ymd'), ['item' => $championship->name, 'date' => $championship->registration_close_at->format('d/m/Y'), 'student_name' => ''], $portalUsers);
                $count++;
            }
        });
        GratuityRequest::query()->where('association_id', $association->id)->whereIn('status', ['awaiting_dojo', 'awaiting_association'])->with('student.person')->each(function (GratuityRequest $gratuity) use ($association, &$count): void {
            $permission = $gratuity->status === 'awaiting_dojo' ? 'gratuity.approve_dojo' : 'gratuity.approve_association';
            $users = $this->recipients->withPermission($association, $permission, $gratuity->status === 'awaiting_dojo' ? $gratuity->dojo_id : null);
            $this->notifications->publish($association, 'gratuity.awaiting_approval', 'gratuity-'.$gratuity->status.'-'.$gratuity->id, ['permission' => $permission, 'dojo_id' => $gratuity->status === 'awaiting_dojo' ? $gratuity->dojo_id : null, 'item' => 'Gratuidade', 'date' => '', 'student_name' => $gratuity->student->person->full_name], $users);
            $count++;
        });
        ClassSession::query()->whereHas('classGroup', fn ($query) => $query->where('association_id', $association->id))->where('status', 'cancelled')->whereDate('session_date', '>=', today()->subDays(7))->with(['classGroup.enrollments.student.person'])->each(function (ClassSession $session) use ($association, &$count): void {
            $users = $session->classGroup->enrollments->filter(fn ($enrollment) => $enrollment->start_date->lte($session->session_date) && (! $enrollment->end_date || $enrollment->end_date->gte($session->session_date)))->flatMap(fn ($enrollment) => $this->recipients->forStudent($enrollment->student));
            $this->notifications->publish($association, 'class.cancelled', 'class-cancelled-'.$session->id, ['item' => $session->classGroup->name, 'date' => $session->session_date->format('d/m/Y'), 'student_name' => ''], $users);
            $count++;
        });
        Student::query()->where('association_id', $association->id)->where('status', 'active')->with('person')->each(function (Student $student) use ($association, &$count): void {
            $latest = AttendanceRecord::query()->where('student_id', $student->id)->latest('recorded_at')->limit(3)->get();
            if ($latest->count() === 3 && $latest->every(fn (AttendanceRecord $record) => $record->status === 'absent')) {
                $lastId = $latest->first()->id;
                $this->notifications->publish($association, 'attendance.consecutive_absences', 'absence-three-'.$student->id.'-'.$lastId, ['student_id' => $student->id, 'item' => 'Frequência', 'date' => '', 'student_name' => $student->person->full_name], $this->recipients->forStudent($student));
                $count++;
            }
        });

        $count += $this->assessmentsDue($association);

        return $count;
    }

    /**
     * Lembrete ao professor (requisito D11): alunos da turma sem avaliação técnica na modalidade há mais de N
     * semanas, contadas da última avaliação ou do início da matrícula. Um aviso por turma, professor e período.
     */
    private function assessmentsDue(Association $association): int
    {
        $weeks = ProgressionSetting::for($association->id)->assessment_reminder_weeks;
        $limit = today()->subWeeks($weeks);
        $period = intdiv((int) today()->diffInDays('2026-01-01', true), $weeks * 7);
        $count = 0;
        ClassGroup::query()->where('association_id', $association->id)->where('status', 'active')->whereNotNull('main_teacher_id')
            ->with(['dojo', 'mainTeacher', 'teacherAssignments' => fn ($query) => $query->where('role_type', 'assistant')->current()->with('teacher'),
                'enrollments' => fn ($query) => $query->where('status', 'active')->whereDate('start_date', '<=', $limit)
                    ->where(fn ($q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', today()))])
            ->each(function (ClassGroup $group) use ($association, $weeks, $limit, $period, &$count): void {
                $studentIds = Student::query()->whereKey($group->enrollments->pluck('student_id'))->where('status', 'active')->pluck('id');
                $recent = TechniqueAssessment::query()->whereIn('student_id', $studentIds)->where('martial_art_id', $group->martial_art_id)
                    ->whereDate('assessed_on', '>', $limit)->distinct()->pluck('student_id');
                $due = $studentIds->diff($recent)->count();
                if ($due === 0) {
                    return;
                }
                $personIds = collect([$group->mainTeacher?->person_id])->merge($group->teacherAssignments->map(fn ($assignment) => $assignment->teacher?->person_id))->filter()->unique();
                $users = User::query()->where('association_id', $association->id)->whereIn('person_id', $personIds)->get()
                    ->filter(fn (User $user) => $this->scope->canTeacher($user, $group->dojo, 'assessment.manage'));
                foreach ($users as $user) {
                    $this->notifications->publish($association, 'assessment.due', 'assessment-due-'.$group->id.'-'.$user->id.'-'.$period,
                        ['item' => $group->name, 'count' => $due, 'weeks' => $weeks, 'date' => '', 'student_name' => ''], [$user]);
                    $count++;
                }
            });

        return $count;
    }

    private function publishStudent(Association $association, Student $student, string $type, string $key, string $item, string $date): void
    {
        $this->notifications->publish($association, $type, $key, ['student_id' => $student->id, 'item' => $item, 'date' => $date, 'student_name' => $student->person->full_name], $this->recipients->forStudent($student));
    }
}
