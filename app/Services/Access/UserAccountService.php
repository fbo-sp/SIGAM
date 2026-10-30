<?php

namespace App\Services\Access;

use App\Mail\AccountActivationMail;
use App\Models\Association;
use App\Models\Permission;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditService;
use App\Support\Access\AssociationRoleCatalog;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Contas de acesso da associação com perfis fixos (AssociationRoleCatalog).
 * Concessões por dojo continuam em AccessAdministration.
 */
class UserAccountService
{
    public function __construct(private readonly AuditService $audit) {}

    public function authorize(User $actor, Association $association): void
    {
        $active = Association::query()->whereKey($association->id)->where('status', 'active')->exists();
        abort_unless($active && ($actor->association_id === null || $actor->association_id === $association->id)
            && $actor->hasPermission('user.manage', $association->id), 403);
    }

    public function list(User $actor, Association $association, array $filters): LengthAwarePaginator
    {
        $this->authorize($actor, $association);
        $search = trim((string) ($filters['q'] ?? ''));
        $status = $filters['status'] ?? null;

        return User::query()->where('association_id', $association->id)
            ->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->where('name', 'like', '%'.$search.'%')->orWhere('email', 'like', '%'.mb_strtolower($search).'%')))
            ->when(in_array($status, ['active', 'pending', 'inactive'], true), fn (Builder $q) => $q->where('status', $status))
            ->with(['roles' => fn ($q) => $q->wherePivot('association_id', $association->id)])
            ->orderBy('name')->paginate(25)->withQueryString();
    }

    /** @return Collection<int, Person> */
    public function eligiblePeople(User $actor, Association $association, ?string $search): Collection
    {
        $this->authorize($actor, $association);
        $search = trim((string) $search);

        return $this->activePeople($association)
            ->whereDoesntHave('user')
            ->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->where('full_name', 'like', '%'.$search.'%')->orWhere('email', 'like', '%'.mb_strtolower($search).'%')))
            ->orderBy('full_name')->limit(20)->get(['people.id', 'people.full_name', 'people.email']);
    }

    public function findEligiblePerson(User $actor, Association $association, int $personId): ?Person
    {
        $this->authorize($actor, $association);

        return $this->activePeople($association)->whereDoesntHave('user')->find($personId, ['people.id', 'people.full_name', 'people.email']);
    }

    /** @return array{user: User, sent: bool} */
    public function create(User $actor, Association $association, array $data): array
    {
        $this->authorize($actor, $association);
        $codes = $this->normalizeCodes($data['roles'] ?? []);
        if ($codes === []) {
            throw ValidationException::withMessages(['roles' => 'Escolha pelo menos um perfil.']);
        }
        $roles = AssociationRoleCatalog::ensure()->only($codes);
        $this->assertCanGrant($actor, $association, $roles);
        $user = $this->createAccount($actor, $association, (int) $data['person_id'], (string) $data['email'], $roles);

        return ['user' => $user, 'sent' => $this->sendActivation($user, $association)];
    }

    /**
     * Libera o portal para um responsável (decisão de Fabio em 30/10/2026): gestores da associação e do dojo e o
     * professor enviam o convite sem precisar de `user.manage`. Quem chama já provou que pode cuidar dos responsáveis
     * do aluno (GuardianService); a conta nasce só com o perfil Portal.
     *
     * @return array{user: User, sent: bool}
     */
    public function invitePortal(User $actor, Association $association, Person $person, string $email): array
    {
        $user = $this->createAccount($actor, $association, $person->id, $email, AssociationRoleCatalog::ensure()->only([AssociationRoleCatalog::PORTAL]));

        return ['user' => $user, 'sent' => $this->sendActivation($user, $association)];
    }

    /** @param  Collection<string, Role>  $roles */
    private function createAccount(User $actor, Association $association, int $personId, string $email, Collection $roles): User
    {
        $email = mb_strtolower(trim($email));

        return DB::transaction(function () use ($actor, $association, $personId, $roles, $email): User {
            $person = $this->activePeople($association)->lockForUpdate()->find($personId);
            if ($person === null) {
                throw ValidationException::withMessages(['person_id' => 'Escolha uma pessoa ativa desta associação.']);
            }
            if (User::query()->where('person_id', $person->id)->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['person_id' => 'Esta pessoa já possui uma conta.']);
            }
            if (User::query()->where('email', $email)->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['email' => 'Este e-mail já está em uso por outra conta.']);
            }
            // A senha aleatória nunca é informada: a pessoa define a própria senha pelo link de ativação.
            $user = User::query()->create([
                'person_id' => $person->id,
                'association_id' => $association->id,
                'name' => $person->full_name,
                'email' => $email,
                'password' => Hash::make(Str::random(64)),
                'status' => 'pending',
            ]);
            $user->roles()->attach($roles->mapWithKeys(fn (Role $role) => [$role->id => ['association_id' => $association->id]])->all());
            $this->audit->record('user_account.created', 'created', $user, null, [
                'person_id' => $person->id, 'email' => $email, 'status' => 'pending', 'roles' => $roles->keys()->values()->all(),
            ], ['association_id' => $association->id, 'actor_user_id' => $actor->id]);

            return $user;
        });
    }

    public function updateRoles(User $actor, Association $association, User $user, array $codes): User
    {
        $this->authorize($actor, $association);
        $this->assertManageable($association, $user);
        $codes = $this->normalizeCodes($codes);

        return DB::transaction(function () use ($actor, $association, $user, $codes): User {
            Association::query()->whereKey($association->id)->lockForUpdate()->first();
            $locked = User::query()->where('association_id', $association->id)->lockForUpdate()->findOrFail($user->id);
            $catalog = AssociationRoleCatalog::ensure();
            $current = $this->catalogRoles($locked, $association)->pluck('code')->all();
            $added = array_values(array_diff($codes, $current));
            $removed = array_values(array_diff($current, $codes));
            if ($added === [] && $removed === []) {
                return $locked;
            }
            $this->assertCanGrant($actor, $association, $catalog->only([...$added, ...$removed]));
            if (in_array(AssociationRoleCatalog::MANAGER, $removed, true) && $locked->status === 'active') {
                $this->assertNotLastManager($association, $locked);
            }
            if ($removed !== []) {
                DB::table('user_roles')->where('user_id', $locked->id)->where('association_id', $association->id)
                    ->whereIn('role_id', $catalog->only($removed)->pluck('id'))->delete();
            }
            if ($added !== []) {
                $locked->roles()->attach($catalog->only($added)->mapWithKeys(fn (Role $role) => [$role->id => ['association_id' => $association->id]])->all());
            }
            $this->audit->record('user_account.roles_updated', 'updated', $locked, ['roles' => $current], ['roles' => $codes], [
                'association_id' => $association->id, 'added' => $added, 'removed' => $removed,
            ]);

            return $locked;
        });
    }

    public function deactivate(User $actor, Association $association, User $user): User
    {
        $this->authorize($actor, $association);
        $this->assertManageable($association, $user);
        if ($actor->id === $user->id) {
            throw ValidationException::withMessages(['status' => 'Você não pode inativar a própria conta.']);
        }

        return DB::transaction(function () use ($actor, $association, $user): User {
            Association::query()->whereKey($association->id)->lockForUpdate()->first();
            $locked = User::query()->where('association_id', $association->id)->lockForUpdate()->findOrFail($user->id);
            if ($locked->status === 'inactive') {
                return $locked;
            }
            $roles = $this->catalogRoles($locked, $association);
            $this->assertCanGrant($actor, $association, $roles->keyBy('code'));
            if ($locked->status === 'active' && $roles->contains('code', AssociationRoleCatalog::MANAGER)) {
                $this->assertNotLastManager($association, $locked);
            }
            $previous = $locked->status;
            $locked->forceFill(['status' => 'inactive', 'remember_token' => Str::random(60)])->save();
            // Sessões abertas caem na hora; o login já exige status ativo.
            DB::table('sessions')->where('user_id', $locked->id)->delete();
            DB::table('password_reset_tokens')->where('email', $locked->email)->delete();
            $this->audit->record('user_account.deactivated', 'updated', $locked, ['status' => $previous], ['status' => 'inactive'], ['association_id' => $association->id]);

            return $locked;
        });
    }

    public function reactivate(User $actor, Association $association, User $user): User
    {
        $this->authorize($actor, $association);
        $this->assertManageable($association, $user);

        return DB::transaction(function () use ($actor, $association, $user): User {
            $locked = User::query()->where('association_id', $association->id)->lockForUpdate()->findOrFail($user->id);
            if ($locked->status !== 'inactive') {
                return $locked;
            }
            $this->assertCanGrant($actor, $association, $this->catalogRoles($locked, $association)->keyBy('code'));
            // Quem nunca definiu senha volta como pendente e precisa de novo convite.
            $status = ($locked->email_verified_at || $locked->last_login_at) ? 'active' : 'pending';
            $locked->forceFill(['status' => $status])->save();
            $this->audit->record('user_account.reactivated', 'updated', $locked, ['status' => 'inactive'], ['status' => $status], ['association_id' => $association->id]);

            return $locked;
        });
    }

    public function resendActivation(User $actor, Association $association, User $user): bool
    {
        $this->authorize($actor, $association);
        $this->assertManageable($association, $user);
        $fresh = User::query()->where('association_id', $association->id)->findOrFail($user->id);
        if (! $this->awaitsActivation($fresh)) {
            throw ValidationException::withMessages(['status' => 'Só é possível reenviar o convite de contas pendentes criadas nesta tela.']);
        }

        return $this->sendActivation($fresh, $association);
    }

    public function awaitsActivation(User $user): bool
    {
        return $user->status === 'pending' && $user->association_id !== null
            && $this->catalogRoles($user, $user->association)->isNotEmpty();
    }

    /** Conclui o convite: define a senha escolhida pela pessoa e ativa a conta pendente. */
    public function activate(string $token, string $email, string $password): bool
    {
        $email = mb_strtolower(trim($email));
        $user = User::query()->where('email', $email)->first();
        if ($user === null || ! $this->awaitsActivation($user)) {
            return false;
        }

        $status = Password::broker('activations')->reset(
            ['email' => $email, 'token' => $token, 'password' => $password],
            function (User $user, string $password): void {
                DB::transaction(function () use ($user, $password): void {
                    $locked = User::query()->lockForUpdate()->findOrFail($user->id);
                    if ($locked->status !== 'pending') {
                        return;
                    }
                    $locked->forceFill([
                        'password' => Hash::make($password),
                        'remember_token' => Str::random(60),
                        'status' => 'active',
                        'email_verified_at' => now(),
                    ])->save();
                    $this->audit->record('user_account.activated', 'updated', $locked, ['status' => 'pending'], ['status' => 'active'], ['association_id' => $locked->association_id]);
                });
            },
        );

        return $status === Password::PASSWORD_RESET && $user->fresh()->status === 'active';
    }

    /** @return Collection<int, Role> */
    public function catalogRoles(User $user, Association $association): Collection
    {
        return $user->roles()->whereNull('roles.association_id')->whereIn('roles.code', AssociationRoleCatalog::codes())
            ->wherePivot('association_id', $association->id)->get();
    }

    public function isSystemAccount(User $user): bool
    {
        return $user->roles()->where('roles.scope_type', 'system')->exists();
    }

    /** Códigos de perfil que o ator pode conceder ou retirar nesta associação. */
    public function grantableCodes(User $actor, Association $association): array
    {
        $held = $this->permissionCodes($actor, $association);

        return AssociationRoleCatalog::ensure()
            ->filter(fn (Role $role) => $this->powerOf($role)->diff($held)->isEmpty())
            ->keys()->values()->all();
    }

    private function sendActivation(User $user, Association $association): bool
    {
        try {
            $token = Password::broker('activations')->createToken($user);
            Mail::to($user->email)->send(new AccountActivationMail(
                route('account-activation.show', ['token' => $token, 'email' => $user->email]),
                (string) ($association->trade_name ?: $association->legal_name),
            ));
            $this->audit->record('user_account.invitation_sent', 'sent', $user, null, null, ['association_id' => $association->id]);

            return true;
        } catch (\Throwable $exception) {
            report($exception);
            $this->audit->record('user_account.invitation_failed', 'failed', $user, null, null, ['association_id' => $association->id]);

            return false;
        }
    }

    private function activePeople(Association $association): Builder
    {
        return Person::query()->whereHas('associations', fn (Builder $q) => $q
            ->where('associations.id', $association->id)
            ->where('association_person_roles.status', 'active')
            ->whereDate('association_person_roles.start_date', '<=', today())
            ->where(fn (Builder $w) => $w->whereNull('association_person_roles.end_date')->orWhereDate('association_person_roles.end_date', '>=', today())));
    }

    private function assertManageable(Association $association, User $user): void
    {
        abort_unless($user->association_id === $association->id, 404);
        if ($this->isSystemAccount($user)) {
            throw ValidationException::withMessages(['roles' => 'A conta do administrador do sistema não é alterada por esta tela.']);
        }
    }

    /** @param  Collection<string, Role>  $roles */
    private function assertCanGrant(User $actor, Association $association, Collection $roles): void
    {
        $held = $this->permissionCodes($actor, $association);
        foreach ($roles as $role) {
            if ($this->powerOf($role)->diff($held)->isNotEmpty()) {
                throw ValidationException::withMessages(['roles' => 'Você não pode conceder ou retirar o perfil "'.$role->name.'", porque ele tem permissões que você não possui.']);
            }
        }
    }

    private function assertNotLastManager(Association $association, User $user): void
    {
        $others = User::query()->where('association_id', $association->id)->where('status', 'active')->whereKeyNot($user->id)
            ->whereHas('roles', fn (Builder $q) => $q->whereNull('roles.association_id')->where('roles.code', AssociationRoleCatalog::MANAGER)
                ->where('user_roles.association_id', $association->id))
            ->exists();
        if (! $others) {
            throw ValidationException::withMessages(['roles' => 'A associação precisa manter pelo menos um gestor ativo.']);
        }
    }

    /** Permissões do perfil que dão poder sobre terceiros. */
    private function powerOf(Role $role): Collection
    {
        return $role->permissions()->pluck('code')->diff(AssociationRoleCatalog::PERSONAL_PERMISSIONS)->values();
    }

    private function permissionCodes(User $user, Association $association): Collection
    {
        if (! $user->isActive()) {
            return collect();
        }

        return Permission::query()->whereHas('roles', fn (Builder $role) => $role
            ->whereIn('roles.scope_type', ['system', 'association'])->where('roles.status', 'active')
            ->where(fn (Builder $q) => $q->whereNull('roles.association_id')->orWhere('roles.association_id', $association->id))
            ->whereHas('users', fn (Builder $u) => $u->where('users.id', $user->id)
                ->where(fn (Builder $q) => $q->whereNull('user_roles.association_id')->orWhere('user_roles.association_id', $association->id))))
            ->pluck('code');
    }

    /** @return list<string> */
    private function normalizeCodes(array $codes): array
    {
        return array_values(array_intersect(AssociationRoleCatalog::codes(), array_map('strval', $codes)));
    }
}
