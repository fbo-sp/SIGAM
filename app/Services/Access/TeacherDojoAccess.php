<?php

namespace App\Services\Access;

use App\Models\DojoRoleAssignment;
use App\Models\DojoTeacherAssignment;
use App\Models\Role;
use App\Models\TeacherAssociationMembership;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * O vínculo do professor com o dojo libera o acesso de "Professor do dojo" no mesmo período.
 * Concessões já existentes (manuais) não são alteradas; encerrar o vínculo revoga as concessões
 * de professor ainda ativas naquele dojo, porque sem vínculo elas não dão acesso a nenhuma aula.
 */
class TeacherDojoAccess
{
    public const ROLE_CODE = 'dojo_teacher';

    public function __construct(private readonly AuditService $audit) {}

    public function grantForLink(DojoTeacherAssignment $link, int $actorId): ?DojoRoleAssignment
    {
        if ($link->revoked_at !== null || ($link->end_date !== null && $link->end_date->lt(today()))) {
            return null;
        }
        $teacher = TeacherProfile::query()->where('status', 'active')->find($link->teacher_profile_id);
        $role = Role::query()->whereNull('association_id')->where('code', self::ROLE_CODE)->where('scope_type', 'teacher')->where('status', 'active')->first();
        $user = $teacher ? User::query()->where('person_id', $teacher->person_id)->where('association_id', $link->association_id)
            ->where('status', '!=', 'inactive')->first() : null;
        $member = $teacher && TeacherAssociationMembership::query()->where('association_id', $link->association_id)
            ->where('teacher_profile_id', $teacher->id)->where('status', 'active')->exists();
        if ($role === null || $user === null || ! $member) {
            return null;
        }

        return DB::transaction(function () use ($link, $user, $role, $actorId): ?DojoRoleAssignment {
            $start = $link->start_date->toDateString();
            $end = $link->end_date?->toDateString();
            $covered = $this->teacherGrants($user, $link->dojo_id)->lockForUpdate()
                ->whereDate('start_date', '<=', $end ?? '9999-12-31')
                ->where(fn (Builder $query) => $query->whereNull('end_date')->orWhereDate('end_date', '>=', $start))->exists();
            if ($covered || DojoRoleAssignment::query()->where(['user_id' => $user->id, 'role_id' => $role->id, 'dojo_id' => $link->dojo_id])->whereDate('start_date', $start)->exists()) {
                return null;
            }
            $grant = DojoRoleAssignment::query()->create([
                'association_id' => $link->association_id, 'dojo_id' => $link->dojo_id, 'user_id' => $user->id, 'role_id' => $role->id,
                'start_date' => $start, 'end_date' => $end, 'created_by_user_id' => $actorId,
            ]);
            $this->audit->record('dojo_access.granted', 'created', $grant, null, [
                ...$grant->only(['user_id', 'role_id', 'dojo_id', 'start_date', 'end_date']), 'origin' => 'dojo_teacher_link', 'dojo_teacher_assignment_id' => $link->id,
            ], ['association_id' => $link->association_id]);

            return $grant;
        });
    }

    /** Concede o acesso para todos os vínculos vigentes ou futuros do professor na associação. */
    public function grantForTeacher(int $associationId, int $teacherProfileId, int $actorId): int
    {
        return DojoTeacherAssignment::query()->where('association_id', $associationId)->where('teacher_profile_id', $teacherProfileId)
            ->whereNull('revoked_at')->where(fn (Builder $query) => $query->whereNull('end_date')->orWhereDate('end_date', '>=', today()))
            ->get()->filter(fn (DojoTeacherAssignment $link) => $this->grantForLink($link, $actorId) !== null)->count();
    }

    public function revokeForLink(DojoTeacherAssignment $link): int
    {
        $teacher = TeacherProfile::query()->find($link->teacher_profile_id);
        $user = $teacher ? User::query()->where('person_id', $teacher->person_id)->where('association_id', $link->association_id)->first() : null;
        if ($user === null) {
            return 0;
        }
        $grants = $this->teacherGrants($user, $link->dojo_id)->lockForUpdate()
            ->where(fn (Builder $query) => $query->whereNull('end_date')->orWhereDate('end_date', '>=', today()))->get();
        foreach ($grants as $grant) {
            $grant->update(['revoked_at' => now()]);
            $this->audit->record('dojo_access.revoked', 'updated', $grant, null, [
                'revoked_at' => $grant->revoked_at, 'origin' => 'dojo_teacher_unlinked', 'dojo_teacher_assignment_id' => $link->id,
            ], ['association_id' => $link->association_id]);
        }

        return $grants->count();
    }

    private function teacherGrants(User $user, int $dojoId): Builder
    {
        return DojoRoleAssignment::query()->where('user_id', $user->id)->where('dojo_id', $dojoId)->whereNull('revoked_at')
            ->whereHas('role', fn (Builder $query) => $query->where('scope_type', 'teacher'));
    }
}
