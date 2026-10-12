<?php

namespace App\Services\Notifications;

use App\Models\Association;
use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Collection;

class NotificationRecipientResolver
{
    /** @return Collection<int, User> */
    public function forStudent(Student $student): Collection
    {
        $guardianIds = $student->guardians()->where('can_receive_notifications', true)->whereDate('start_date', '<=', today())->where(fn ($query) => $query->whereNull('end_date')->orWhereDate('end_date', '>=', today()))->pluck('guardian_person_id');

        return User::query()->where('association_id', $student->association_id)->where('status', 'active')->where(fn ($query) => $query->where('person_id', $student->person_id)->orWhereIn('person_id', $guardianIds))->with('person')->get();
    }

    /** @return Collection<int, User> */
    public function withPermission(Association $association, string $permission, ?int $dojoId = null): Collection
    {
        return User::query()->where('association_id', $association->id)->where('status', 'active')->with('person')->get()->filter(function (User $user) use ($association, $permission, $dojoId): bool {
            if (! $user->hasPermission($permission, $association->id)) {
                return false;
            }

            return $dojoId === null || $user->dojo_id === null || $user->dojo_id === $dojoId;
        })->values();
    }
}
