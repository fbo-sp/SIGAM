<?php

namespace App\Services\Notifications;

use App\Models\Notification;
use App\Models\Student;
use App\Models\User;

class NotificationContentService
{
    /** Historical delivery is not proof of current access to personal details. */
    public function canViewDetails(Notification $notification, User $user): bool
    {
        if ($notification->user_id !== $user->id || $notification->association_id !== $user->association_id || ! $user->isActive()) {
            return false;
        }
        if (in_array($notification->event_type, ['championship.opened', 'championship.deadline', 'class.cancelled', 'announcement.sent'], true)) {
            return true;
        }
        $context = $notification->event?->context ?? [];
        if ($notification->event_type === 'gratuity.awaiting_approval') {
            $permission = $context['permission'] ?? '';

            return in_array($permission, ['gratuity.approve_dojo', 'gratuity.approve_association'], true)
                && $user->hasPermission($permission, $user->association_id)
                && (empty($context['dojo_id']) || $user->dojo_id === null || $user->dojo_id === (int) $context['dojo_id']);
        }
        // Old events without resource identity are deliberately redacted.
        if (empty($context['student_id']) || ! $user->person_id) {
            return false;
        }
        $student = Student::query()->where('association_id', $user->association_id)->find($context['student_id']);

        return $student && ($student->person_id === $user->person_id || $student->guardians()
            ->where('guardian_person_id', $user->person_id)->where('can_receive_notifications', true)
            ->whereDate('start_date', '<=', today())
            ->where(fn ($query) => $query->whereNull('end_date')->orWhereDate('end_date', '>=', today()))->exists());
    }
}
