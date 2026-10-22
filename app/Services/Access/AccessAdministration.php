<?php

namespace App\Services\Access;

use App\Models\Association;
use App\Models\Dojo;
use App\Models\DojoRoleAssignment;
use App\Models\DojoTeacherAssignment;
use App\Models\Role;
use App\Models\TeacherAssociationMembership;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AccessAdministration
{
    public function __construct(private readonly OperationalScope $scope, private readonly AuditService $audit, private readonly TeacherDojoAccess $teacherAccess) {}

    public function authorize(User $actor, Association $association): void
    {
        $active = Association::query()->whereKey($association->id)->where('status', 'active')->exists();
        abort_unless($active && ($actor->association_id === null || $actor->association_id === $association->id)
            && $actor->hasPermission('role.manage', $association->id), 403);
    }

    public function grant(User $actor, Association $association, array $data): DojoRoleAssignment
    {
        $this->authorize($actor, $association);

        return DB::transaction(function () use ($actor, $association, $data) {
            $user = User::query()->where('association_id', $association->id)->where('status', 'active')->lockForUpdate()->findOrFail($data['user_id']);
            $dojo = Dojo::query()->where('association_id', $association->id)->where('status', 'active')->findOrFail($data['dojo_id']);
            $role = Role::query()->whereIn('scope_type', ['dojo', 'teacher'])->where('status', 'active')
                ->where(fn ($q) => $q->whereNull('association_id')->orWhere('association_id', $association->id))->findOrFail($data['role_id']);
            $this->period($data);
            if ($role->scope_type === 'teacher') {
                $teacherId = TeacherProfile::query()->where('person_id', $user->person_id)->where('status', 'active')->value('id');
                $eligible = $teacherId && TeacherAssociationMembership::query()->where('association_id', $association->id)
                    ->where('teacher_profile_id', $teacherId)->where('status', 'active')->exists();
                if (! $eligible) {
                    throw ValidationException::withMessages(['user_id' => 'A conta escolhida não pertence a um professor ativo desta associação.']);
                }
            }
            $existing = DojoRoleAssignment::query()->where('user_id', $user->id)->where('role_id', $role->id)->where('dojo_id', $dojo->id)
                ->whereDate('start_date', $data['start_date'])->first();
            if ($existing && ! $existing->revoked_at && $existing->end_date?->toDateString() === ($data['end_date'] ?? null)) {
                return $existing;
            }
            $overlap = DojoRoleAssignment::query()->where('user_id', $user->id)->where('role_id', $role->id)->where('dojo_id', $dojo->id)
                ->whereNull('revoked_at')->whereDate('start_date', '<=', $data['end_date'] ?? '9999-12-31')
                ->where(fn ($q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', $data['start_date']))->exists();
            if ($overlap) {
                throw ValidationException::withMessages(['start_date' => 'Já existe uma concessão deste papel que alcança o período informado.']);
            }
            $grant = DojoRoleAssignment::query()->firstOrCreate(
                ['user_id' => $user->id, 'role_id' => $role->id, 'dojo_id' => $dojo->id, 'start_date' => $data['start_date']],
                ['association_id' => $association->id, 'end_date' => $data['end_date'] ?? null, 'created_by_user_id' => $actor->id],
            );
            if (! $grant->wasRecentlyCreated && ($grant->revoked_at || $grant->end_date?->toDateString() !== ($data['end_date'] ?? null))) {
                throw ValidationException::withMessages(['start_date' => 'Este período já existe ou foi revogado. Não é possível sobrescrever seu histórico.']);
            }
            if ($grant->wasRecentlyCreated) {
                $this->audit->record('dojo_access.granted', 'created', $grant, null, $grant->only(['user_id', 'role_id', 'dojo_id', 'start_date', 'end_date']));
            }

            return $grant;
        });
    }

    public function revoke(User $actor, Association $association, DojoRoleAssignment $grant): void
    {
        $this->authorize($actor, $association);
        DB::transaction(function () use ($association, $grant) {
            $locked = DojoRoleAssignment::query()->where('association_id', $association->id)->lockForUpdate()->findOrFail($grant->id);
            if (! $locked->revoked_at) {
                $locked->update(['revoked_at' => now()]);
                $this->audit->record('dojo_access.revoked', 'updated', $locked, null, ['revoked_at' => $locked->revoked_at]);
            }
        });
    }

    public function linkTeacher(User $actor, Dojo $dojo, array $data): DojoTeacherAssignment
    {
        abort_unless($this->scope->canDojo($actor, $dojo, 'teacher.assign'), 403);

        return DB::transaction(function () use ($actor, $dojo, $data) {
            $member = TeacherAssociationMembership::query()->where('association_id', $dojo->association_id)
                ->where('teacher_profile_id', $data['teacher_profile_id'])->where('status', 'active')->lockForUpdate()->firstOrFail();
            TeacherProfile::query()->where('status', 'active')->findOrFail($member->teacher_profile_id);
            $this->period($data);
            $existing = DojoTeacherAssignment::query()->where('dojo_id', $dojo->id)->where('teacher_profile_id', $member->teacher_profile_id)
                ->whereDate('start_date', $data['start_date'])->first();
            if ($existing && ! $existing->revoked_at && $existing->end_date?->toDateString() === ($data['end_date'] ?? null)) {
                return $existing;
            }
            $overlap = DojoTeacherAssignment::query()->where('dojo_id', $dojo->id)->where('teacher_profile_id', $member->teacher_profile_id)
                ->whereNull('revoked_at')->whereDate('start_date', '<=', $data['end_date'] ?? '9999-12-31')
                ->where(fn ($q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', $data['start_date']))->exists();
            if ($overlap) {
                throw ValidationException::withMessages(['start_date' => 'O professor já possui vínculo com este dojo no período informado.']);
            }
            $link = DojoTeacherAssignment::query()->firstOrCreate(
                ['dojo_id' => $dojo->id, 'teacher_profile_id' => $member->teacher_profile_id, 'start_date' => $data['start_date']],
                ['association_id' => $dojo->association_id, 'end_date' => $data['end_date'] ?? null, 'created_by_user_id' => $actor->id],
            );
            if (! $link->wasRecentlyCreated && ($link->revoked_at || $link->end_date?->toDateString() !== ($data['end_date'] ?? null))) {
                throw ValidationException::withMessages(['start_date' => 'Este vínculo já existe ou foi encerrado. Use outro período.']);
            }
            if ($link->wasRecentlyCreated) {
                $this->audit->record('dojo_teacher.linked', 'created', $link, null, $link->only(['dojo_id', 'teacher_profile_id', 'start_date', 'end_date']));
            }
            // O vínculo já libera o acesso de professor ao dojo no mesmo período (decisão de 22/10/2026).
            $this->teacherAccess->grantForLink($link, $actor->id);

            return $link;
        });
    }

    public function unlinkTeacher(User $actor, Dojo $dojo, DojoTeacherAssignment $link): void
    {
        abort_unless($this->scope->canDojo($actor, $dojo, 'teacher.assign'), 403);
        DB::transaction(function () use ($dojo, $link) {
            $locked = DojoTeacherAssignment::query()->where('association_id', $dojo->association_id)->where('dojo_id', $dojo->id)->lockForUpdate()->findOrFail($link->id);
            if (! $locked->revoked_at) {
                $locked->update(['revoked_at' => now()]);
                $this->audit->record('dojo_teacher.revoked', 'updated', $locked, null, ['revoked_at' => $locked->revoked_at]);
                $this->teacherAccess->revokeForLink($locked);
            }
        });
    }

    private function period(array $data): void
    {
        validator($data, ['start_date' => ['required', 'date_format:Y-m-d'], 'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date']])->validate();
    }
}
