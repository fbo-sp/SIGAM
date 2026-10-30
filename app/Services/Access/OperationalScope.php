<?php

namespace App\Services\Access;

use App\Models\ClassGroup;
use App\Models\ClassSession;
use App\Models\Dojo;
use App\Models\DojoRoleAssignment;
use App\Models\DojoTeacherAssignment;
use App\Models\Student;
use App\Models\TeacherAssociationMembership;
use App\Models\TeacherProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class OperationalScope
{
    public const DOJO_PERMISSIONS = ['dojo.access', 'student.view', 'student.manage', 'student.document.manage', 'class.view', 'class.manage', 'enrollment.manage', 'session.view', 'attendance.manage', 'attendance.reopen', 'visitor.manage', 'teacher.view', 'teacher.assign', 'import.manage', 'charge.view', 'student_plan.manage', 'payment.manage', 'gratuity.request', 'gratuity.approve_dojo', 'championship.view', 'championship.register', 'assessment.view', 'progression.report', 'announcement.send', 'report.export', 'exam.manage', 'exam.grade'];

    public const TEACHER_PERMISSIONS = ['dojo.access', 'class.view', 'session.view', 'attendance.manage', 'attendance.reopen', 'visitor.manage', 'assessment.view', 'assessment.manage', 'progression.report', 'announcement.send', 'report.export', 'exam.grade'];

    public function grants(User $user, Dojo $dojo, string $permission, string $scope = 'dojo'): Builder
    {
        return DojoRoleAssignment::query()
            ->where('user_id', $user->id)->where('association_id', $dojo->association_id)->where('dojo_id', $dojo->id)
            ->whereNull('revoked_at')->whereDate('start_date', '<=', today())
            ->where(fn ($q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', today()))
            ->whereHas('role', fn ($q) => $q->where('scope_type', $scope)->where('status', 'active')
                ->where(fn ($r) => $r->whereNull('association_id')->orWhere('association_id', $dojo->association_id))
                ->whereHas('permissions', fn ($p) => $p->where('code', $permission)));
    }

    public function activeContext(User $user, Dojo $dojo): bool
    {
        $active = Dojo::query()->whereKey($dojo->id)->where('association_id', $dojo->association_id)->where('status', 'active')
            ->whereHas('association', fn ($query) => $query->where('status', 'active'))->exists();

        return $user->isActive() && $active
            && ($user->association_id === null || $user->association_id === $dojo->association_id);
    }

    public function canDojo(User $user, Dojo $dojo, string $permission): bool
    {
        if (! $this->activeContext($user, $dojo) || ! in_array($permission, self::DOJO_PERMISSIONS, true)) {
            return false;
        }

        return $user->hasPermission($permission === 'dojo.access' ? 'dojo.view' : $permission, $dojo->association_id)
            || ($user->association_id === $dojo->association_id && $this->grants($user, $dojo, $permission)->exists());
    }

    public function canEnter(User $user, Dojo $dojo): bool
    {
        return $this->canDojo($user, $dojo, 'dojo.access')
            || ($this->activeContext($user, $dojo) && $user->association_id === $dojo->association_id
                && $this->grants($user, $dojo, 'dojo.access', 'teacher')->exists());
    }

    public function canTeacher(User $user, Dojo $dojo, string $permission): bool
    {
        if (! $this->activeContext($user, $dojo) || $user->association_id !== $dojo->association_id
            || ! in_array($permission, self::TEACHER_PERMISSIONS, true) || $user->person_id === null
            || ! $this->grants($user, $dojo, $permission, 'teacher')->exists()) {
            return false;
        }
        $teacherId = TeacherProfile::query()->where('person_id', $user->person_id)->where('status', 'active')->value('id');

        return $teacherId
            && TeacherAssociationMembership::query()->where('association_id', $dojo->association_id)
                ->where('teacher_profile_id', $teacherId)->where('status', 'active')->exists()
            && DojoTeacherAssignment::query()->where('association_id', $dojo->association_id)->where('dojo_id', $dojo->id)
                ->where('teacher_profile_id', $teacherId)->whereNull('revoked_at')->whereDate('start_date', '<=', today())
                ->where(fn ($query) => $query->whereNull('end_date')->orWhereDate('end_date', '>=', today()))->exists();
    }

    /** Restrict BEFORE pagination, counts or loading a roster. */
    public function sessions(User $user, Dojo $dojo, string $permission = 'session.view'): Builder
    {
        $query = ClassSession::query()->whereHas('classGroup', fn ($q) => $q->where('association_id', $dojo->association_id)->where('dojo_id', $dojo->id));
        if ($this->canDojo($user, $dojo, $permission)) {
            return $query;
        }
        if (! $this->canTeacher($user, $dojo, $permission)) {
            return $query->whereRaw('1 = 0');
        }
        $teacherId = TeacherProfile::query()->where('person_id', $user->person_id)->where('status', 'active')->value('id');
        if (! $teacherId) {
            return $query->whereRaw('1 = 0');
        }

        $grants = $this->grants($user, $dojo, $permission, 'teacher')->select('dojo_role_assignments.id')
            ->whereColumn('start_date', '<=', 'class_sessions.session_date')
            ->where(fn ($q) => $q->whereNull('end_date')->orWhereColumn('end_date', '>=', 'class_sessions.session_date'));

        return $query->whereExists($grants->toBase())
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('dojo_teacher_assignments')
                ->where('association_id', $dojo->association_id)->where('dojo_id', $dojo->id)->where('teacher_profile_id', $teacherId)
                ->whereNull('revoked_at')->whereDate('start_date', '<=', today())
                ->where(fn ($d) => $d->whereNull('end_date')->orWhereDate('end_date', '>=', today()))
                ->whereColumn('start_date', '<=', 'class_sessions.session_date')
                ->where(fn ($d) => $d->whereNull('end_date')->orWhereColumn('end_date', '>=', 'class_sessions.session_date')))
            ->where(function ($q) use ($teacherId) {
                $q->where('planned_teacher_id', $teacherId)->orWhere('actual_teacher_id', $teacherId)
                    // A designated replacement takes precedence over the group's default teacher.
                    ->orWhere(fn ($g) => $g->whereNull('planned_teacher_id')->whereNull('actual_teacher_id')
                        ->whereHas('classGroup', fn ($c) => $c->where('main_teacher_id', $teacherId)))
                    ->orWhereExists(fn ($a) => $a->selectRaw('1')->from('class_group_teachers')
                        ->whereColumn('class_group_id', 'class_sessions.class_group_id')->where('teacher_profile_id', $teacherId)
                        ->whereIn('role_type', ['assistant', 'substitute'])
                        ->whereColumn('start_date', '<=', 'class_sessions.session_date')
                        ->where(fn ($d) => $d->whereNull('end_date')->orWhereColumn('end_date', '>=', 'class_sessions.session_date')));
            });
    }

    public function canSession(User $user, ClassSession $session, string $permission): bool
    {
        $dojo = $session->classGroup?->dojo;

        return $dojo !== null && $this->sessions($user, $dojo, $permission)->whereKey($session->id)->exists();
    }

    public function classes(User $user, Dojo $dojo): Builder
    {
        $query = ClassGroup::query()->where('association_id', $dojo->association_id)->where('dojo_id', $dojo->id);

        return $this->canDojo($user, $dojo, 'class.view') ? $query
            : $query->whereIn('id', $this->sessions($user, $dojo, 'class.view')->select('class_group_id'));
    }

    /** Regular dojo membership only. Visiting a session never grants directory access. */
    public function students(User $user, Dojo $dojo): Builder
    {
        abort_unless($this->canDojo($user, $dojo, 'student.view'), 403);

        return Student::query()->where('association_id', $dojo->association_id)
            ->whereHas('dojoHistory', fn ($q) => $q->where('dojo_id', $dojo->id)->whereDate('start_date', '<=', today())
                ->where(fn ($d) => $d->whereNull('end_date')->orWhereDate('end_date', '>=', today())));
    }
}
