<?php

namespace App\Services\Access;

use App\Models\Association;
use App\Models\Permission;
use App\Models\Role;
use App\Models\TeacherAssociationMembership;
use App\Models\TeacherInvitation;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TeacherInvitationService
{
    public function __construct(private readonly AuditService $audit) {}

    /** @return array{invitation: TeacherInvitation, token: string} */
    public function issue(User $actor, Association $association, TeacherProfile $teacher): array
    {
        $this->assertManager($actor, $association);

        return DB::transaction(function () use ($actor, $association, $teacher): array {
            $teacher = TeacherProfile::query()->with('person')->lockForUpdate()->findOrFail($teacher->id);
            $membership = TeacherAssociationMembership::query()->where('association_id', $association->id)
                ->where('teacher_profile_id', $teacher->id)->lockForUpdate()->firstOrFail();
            if ($teacher->status !== 'active' || $membership->status !== 'active' || $teacher->person->status !== 'active') {
                throw ValidationException::withMessages(['teacher' => 'Ative o professor e seu vínculo institucional antes de enviar o convite.']);
            }
            $email = mb_strtolower(trim((string) $teacher->person->email));
            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw ValidationException::withMessages(['email' => 'Informe um e-mail individual válido no cadastro do professor antes de enviar o convite.']);
            }

            $byPerson = User::query()->where('person_id', $teacher->person_id)->lockForUpdate()->first();
            $byEmail = User::query()->where('email', $email)->lockForUpdate()->first();
            if ($byPerson !== null && $byPerson->email !== $email) {
                throw ValidationException::withMessages(['email' => 'A conta desta pessoa usa outro e-mail. Solicite uma revisão de identidade antes de convidar.']);
            }
            if ($byEmail !== null && $byEmail->person_id !== $teacher->person_id) {
                throw ValidationException::withMessages(['email' => 'Este e-mail já pertence a outra conta. Solicite uma revisão de identidade.']);
            }
            $user = $byPerson ?? $byEmail;
            if ($user !== null && ($user->association_id !== $association->id || $user->status === 'inactive')) {
                throw ValidationException::withMessages(['email' => 'A conta existente não pode ser vinculada automaticamente a esta associação.']);
            }
            if ($user === null) {
                $user = User::query()->create([
                    'person_id' => $teacher->person_id,
                    'association_id' => $association->id,
                    'name' => $teacher->person->social_name ?: $teacher->person->full_name,
                    'email' => $email,
                    'password' => Hash::make(Str::random(64)),
                    'status' => 'pending',
                ]);
            }

            TeacherInvitation::query()->where('association_id', $association->id)
                ->where('teacher_profile_id', $teacher->id)->whereIn('status', ['pending', 'sent'])
                ->lockForUpdate()->get()->each->update([
                    'status' => 'revoked',
                    'revoked_at' => now(),
                    'encrypted_token' => null,
                    'error_message' => null,
                ]);

            $token = Str::random(64);
            $invitation = TeacherInvitation::query()->create([
                'association_id' => $association->id,
                'teacher_profile_id' => $teacher->id,
                'user_id' => $user->id,
                'email' => $email,
                'token_hash' => hash('sha256', $token),
                'encrypted_token' => $token,
                'status' => 'pending',
                'delivery_status' => 'pending',
                'scheduled_at' => now(),
                'expires_at' => now()->addHours(72),
                'invited_by_user_id' => $actor->id,
            ]);
            $this->audit->record('teacher_invitation.created', 'created', $invitation, null, [
                'status' => 'pending',
                'teacher_profile_id' => $teacher->id,
            ], ['association_id' => $association->id]);

            return ['invitation' => $invitation, 'token' => $token];
        }, 3);
    }

    public function findByToken(string $token): ?TeacherInvitation
    {
        if (strlen($token) !== 64) {
            return null;
        }

        return TeacherInvitation::query()->with(['association', 'teacher.person', 'user'])
            ->where('token_hash', hash('sha256', $token))->first();
    }

    public function accept(string $token, array $credentials): User
    {
        return DB::transaction(function () use ($token, $credentials): User {
            $invitation = TeacherInvitation::query()->where('token_hash', hash('sha256', $token))->lockForUpdate()->first();
            $this->assertUsable($invitation);
            $invitation->load(['association', 'teacher.person', 'inviter']);
            $membership = TeacherAssociationMembership::query()->where('association_id', $invitation->association_id)
                ->where('teacher_profile_id', $invitation->teacher_profile_id)->lockForUpdate()->first();
            if ($membership === null || $membership->status !== 'active' || $invitation->teacher->status !== 'active'
                || $invitation->teacher->person->status !== 'active' || mb_strtolower((string) $invitation->teacher->person->email) !== $invitation->email) {
                throw ValidationException::withMessages(['invitation' => 'Este convite não corresponde mais a um cadastro ativo. Solicite um novo convite.']);
            }
            if (! $invitation->inviter?->isActive()
                || ! $invitation->inviter->hasPermission('teacher.manage', $invitation->association_id)) {
                throw ValidationException::withMessages(['invitation' => 'A autorização deste convite mudou. Solicite um novo convite à associação.']);
            }

            $user = User::query()->lockForUpdate()->findOrFail($invitation->user_id);
            if ($user->person_id !== $invitation->teacher->person_id || $user->association_id !== $invitation->association_id
                || $user->email !== $invitation->email || $user->status === 'inactive') {
                throw ValidationException::withMessages(['invitation' => 'A conta vinculada mudou. Solicite revisão ao gestor da associação.']);
            }
            if ($user->status === 'active') {
                if (! Hash::check((string) ($credentials['current_password'] ?? ''), $user->password)) {
                    throw ValidationException::withMessages(['current_password' => 'A senha atual não confere.']);
                }
            } else {
                $user->password = $credentials['password'];
                $user->status = 'active';
            }
            $user->email_verified_at ??= now();
            $user->save();

            $role = $this->teacherAccountRole();
            $user->roles()->syncWithoutDetaching([$role->id => ['association_id' => $invitation->association_id]]);
            $previousStatus = $invitation->status;
            $invitation->update([
                'status' => 'accepted',
                'email_verified_at' => now(),
                'accepted_at' => now(),
                'encrypted_token' => null,
                'error_message' => null,
            ]);
            $this->audit->record('teacher_invitation.accepted', 'accepted', $invitation, ['status' => $previousStatus], [
                'status' => 'accepted',
                'user_id' => $user->id,
            ], ['association_id' => $invitation->association_id]);

            return $user;
        }, 3);
    }

    public function decline(string $token): void
    {
        DB::transaction(function () use ($token): void {
            $invitation = TeacherInvitation::query()->where('token_hash', hash('sha256', $token))->lockForUpdate()->first();
            $this->assertUsable($invitation);
            $invitation->update(['status' => 'declined', 'declined_at' => now(), 'encrypted_token' => null, 'error_message' => null]);
            $this->audit->record('teacher_invitation.declined', 'declined', $invitation, null, ['status' => 'declined'], [
                'association_id' => $invitation->association_id,
            ]);
        }, 3);
    }

    public function revoke(User $actor, Association $association, TeacherInvitation $invitation): void
    {
        $this->assertManager($actor, $association);
        abort_unless($invitation->association_id === $association->id, 404);
        DB::transaction(function () use ($invitation, $association): void {
            $locked = TeacherInvitation::query()->lockForUpdate()->findOrFail($invitation->id);
            if (in_array($locked->status, ['accepted', 'declined', 'revoked'], true)) {
                throw ValidationException::withMessages(['invitation' => 'Este convite não pode mais ser revogado.']);
            }
            $locked->update(['status' => 'revoked', 'revoked_at' => now(), 'encrypted_token' => null, 'error_message' => null]);
            $this->audit->record('teacher_invitation.revoked', 'revoked', $locked, null, ['status' => 'revoked'], [
                'association_id' => $association->id,
            ]);
        }, 3);
    }

    private function assertUsable(?TeacherInvitation $invitation): void
    {
        if ($invitation === null || ! in_array($invitation->status, ['pending', 'sent'], true)
            || $invitation->revoked_at !== null || $invitation->expires_at->isPast()) {
            throw ValidationException::withMessages(['invitation' => 'Este convite é inválido, expirou ou já foi utilizado.']);
        }
    }

    private function assertManager(User $actor, Association $association): void
    {
        if (! Association::query()->whereKey($association->id)->where('status', 'active')->exists()
            // users.association_id é ponteiro de conveniência: o administrador do sistema não tem
            // associação e é autorizado pelo papel de escopo system em hasPermission (ADR-006).
            || ($actor->association_id !== null && $actor->association_id !== $association->id)
            || ! $actor->hasPermission('teacher.manage', $association->id)) {
            abort(403);
        }
    }

    private function teacherAccountRole(): Role
    {
        $permission = Permission::query()->firstOrCreate(['code' => 'dashboard.teacher'], ['description' => 'Visualizar dashboard do professor']);
        $role = Role::query()->firstOrCreate(['association_id' => null, 'code' => 'teacher_account'], [
            'name' => 'Conta de professor', 'scope_type' => 'association', 'status' => 'active',
        ]);
        $role->permissions()->syncWithoutDetaching([$permission->id]);

        return $role;
    }
}
