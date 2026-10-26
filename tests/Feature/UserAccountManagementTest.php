<?php

namespace Tests\Feature;

use App\Mail\AccountActivationMail;
use App\Models\Association;
use App\Models\Dojo;
use App\Models\Permission;
use App\Models\Person;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use App\Support\Access\AssociationRoleCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class UserAccountManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Mail::fake();
    }

    public function test_manager_creates_account_by_invitation_and_person_sets_own_password(): void
    {
        [$association, $manager] = $this->associationWithManager();
        $person = $this->person($association, 'Maria Financeira', 'maria@example.com');

        $this->actingAs($manager)->get(route('associations.users.index', $association))->assertOk()->assertSee('Usuários');
        $this->get(route('associations.users.create', [$association, 'q' => 'Maria']))->assertOk()->assertSee('Maria Financeira');
        $this->post(route('associations.users.store', $association), [
            'person_id' => $person->id, 'email' => ' MARIA@example.com ', 'roles' => [AssociationRoleCatalog::FINANCE],
        ])->assertSessionHasNoErrors();

        $account = User::query()->where('email', 'maria@example.com')->firstOrFail();
        $this->assertSame('pending', $account->status);
        $this->assertSame($person->id, $account->person_id);
        $this->assertTrue($account->roles()->where('code', AssociationRoleCatalog::FINANCE)->wherePivot('association_id', $association->id)->exists());
        $this->assertFalse($account->hasPermission('finance.view', $association->id), 'Conta pendente não deve ter acesso.');
        $this->assertDatabaseHas('audit_logs', ['event_type' => 'user_account.created', 'entity_id' => $account->id, 'association_id' => $association->id]);

        $url = $this->sentActivationUrl('maria@example.com');
        auth()->logout();
        $this->get($url)->assertOk()->assertSee('Ative sua conta');
        $this->post($this->activationPostUrl($url), ['email' => 'maria@example.com', 'password' => 'NovaSenhaSegura123', 'password_confirmation' => 'NovaSenhaSegura123'])
            ->assertRedirect(route('login'));

        $account->refresh();
        $this->assertSame('active', $account->status);
        $this->assertNotNull($account->email_verified_at);
        $this->assertTrue(Hash::check('NovaSenhaSegura123', $account->password));
        $this->assertTrue($account->hasPermission('finance.view', $association->id));
        $this->assertDatabaseHas('audit_logs', ['event_type' => 'user_account.activated', 'entity_id' => $account->id]);

        $this->post('/entrar', ['email' => 'maria@example.com', 'password' => 'NovaSenhaSegura123'])->assertRedirect();
        $this->assertAuthenticatedAs($account);

        auth()->logout();
        $this->post($this->activationPostUrl($url), ['email' => 'maria@example.com', 'password' => 'OutraSenha12345', 'password_confirmation' => 'OutraSenha12345'])
            ->assertSessionHasErrors('email');
    }

    public function test_activation_link_expires_and_resend_replaces_previous_link(): void
    {
        [$association, $manager] = $this->associationWithManager();
        $account = $this->invite($manager, $association, $this->person($association, 'Pessoa Convite', 'convite@example.com'), [AssociationRoleCatalog::PORTAL]);
        $first = $this->sentActivationUrl('convite@example.com');

        $this->actingAs($manager)->get(route('associations.users.show', [$association, $account]))->assertOk()->assertSee('Reenviar convite')->assertSee('Inativar conta');
        $this->post(route('associations.users.invitation.resend', [$association, $account]))->assertSessionHasNoErrors();
        $second = $this->sentActivationUrl('convite@example.com', last: true);
        $this->assertNotSame($first, $second);
        auth()->logout();

        $this->post($this->activationPostUrl($first), ['email' => 'convite@example.com', 'password' => 'SenhaSegura1234', 'password_confirmation' => 'SenhaSegura1234'])
            ->assertSessionHasErrors('email');
        $this->travel(73)->hours();
        $this->post($this->activationPostUrl($second), ['email' => 'convite@example.com', 'password' => 'SenhaSegura1234', 'password_confirmation' => 'SenhaSegura1234'])
            ->assertSessionHasErrors('email');
        $this->assertSame('pending', $account->fresh()->status);
    }

    public function test_screen_requires_user_manage_and_own_association(): void
    {
        [$association, $manager] = $this->associationWithManager();
        [$other, $otherManager] = $this->associationWithManager();
        $finance = $this->accountWithRoles($association, [AssociationRoleCatalog::FINANCE]);
        $foreign = $this->accountWithRoles($other, [AssociationRoleCatalog::FINANCE]);

        $this->actingAs($finance)->get(route('associations.users.index', $association))->assertForbidden();
        $this->actingAs($otherManager)->get(route('associations.users.index', $association))->assertForbidden();
        $this->actingAs($manager)->get(route('associations.users.show', [$association, $foreign]))->assertNotFound();
        $this->post(route('associations.users.deactivate', [$association, $foreign]))->assertNotFound();
        $this->assertSame('active', $foreign->fresh()->status);

        $outsider = Person::query()->create(['full_name' => 'Pessoa de fora']);
        $other->people()->attach($outsider, ['role_type' => 'student', 'start_date' => today()->subYear(), 'status' => 'active']);
        $this->post(route('associations.users.store', $association), ['person_id' => $outsider->id, 'email' => 'fora@example.com', 'roles' => [AssociationRoleCatalog::PORTAL]])
            ->assertSessionHasErrors('person_id');
        $this->assertDatabaseMissing('users', ['email' => 'fora@example.com']);
    }

    public function test_actor_cannot_grant_role_with_more_power_than_own(): void
    {
        [$association] = $this->associationWithManager();
        $limitedRole = Role::query()->create(['association_id' => $association->id, 'code' => 'user_admin_only', 'name' => 'Só usuários', 'scope_type' => 'association', 'status' => 'active']);
        $limitedRole->permissions()->sync(Permission::query()->whereIn('code', ['user.manage', 'association.view'])->pluck('id'));
        $limited = User::factory()->create(['association_id' => $association->id]);
        $limited->roles()->attach($limitedRole, ['association_id' => $association->id]);
        $person = $this->person($association, 'Pessoa Escalonamento', 'escala@example.com');

        $this->actingAs($limited)->post(route('associations.users.store', $association), [
            'person_id' => $person->id, 'email' => 'escala@example.com', 'roles' => [AssociationRoleCatalog::MANAGER],
        ])->assertSessionHasErrors('roles');
        $this->assertDatabaseMissing('users', ['email' => 'escala@example.com']);

        // Portal só dá acesso aos próprios dados; pode ser liberado por quem administra usuários.
        $this->post(route('associations.users.store', $association), [
            'person_id' => $person->id, 'email' => 'escala@example.com', 'roles' => [AssociationRoleCatalog::PORTAL],
        ])->assertSessionHasNoErrors();
        $account = User::query()->where('email', 'escala@example.com')->firstOrFail();

        $this->put(route('associations.users.roles.update', [$association, $account]), ['roles' => [AssociationRoleCatalog::PORTAL, AssociationRoleCatalog::FINANCE]])
            ->assertSessionHasErrors('roles');
        $this->assertFalse($account->roles()->where('code', AssociationRoleCatalog::FINANCE)->exists());
    }

    public function test_last_active_manager_cannot_be_removed_or_deactivated(): void
    {
        [$association, $manager] = $this->associationWithManager();

        $this->actingAs($manager)->put(route('associations.users.roles.update', [$association, $manager]), ['roles' => [AssociationRoleCatalog::FINANCE]])
            ->assertSessionHasErrors('roles');
        $this->post(route('associations.users.deactivate', [$association, $manager]))->assertSessionHasErrors('status');
        $this->assertSame('active', $manager->fresh()->status);

        $second = $this->accountWithRoles($association, [AssociationRoleCatalog::MANAGER]);
        $this->put(route('associations.users.roles.update', [$association, $second]), ['roles' => [AssociationRoleCatalog::FINANCE]])->assertSessionHasNoErrors();
        $this->assertFalse($second->roles()->where('code', AssociationRoleCatalog::MANAGER)->exists());

        $this->actingAs($second->fresh())->put(route('associations.users.roles.update', [$association, $manager]), ['roles' => []])->assertForbidden();

        $third = $this->accountWithRoles($association, [AssociationRoleCatalog::MANAGER]);
        $this->actingAs($third)->post(route('associations.users.deactivate', [$association, $manager]))->assertSessionHasNoErrors();
        $this->assertSame('inactive', $manager->fresh()->status);
        $this->put(route('associations.users.roles.update', [$association, $third]), ['roles' => []])->assertSessionHasErrors('roles');
    }

    public function test_system_administrator_account_is_not_changed_by_association_screen(): void
    {
        [$association, $manager] = $this->associationWithManager();
        $admin = User::factory()->create(['association_id' => $association->id]);
        $admin->roles()->attach(Role::query()->where('code', 'system_administrator')->firstOrFail(), ['association_id' => null]);

        $this->actingAs($manager)->get(route('associations.users.show', [$association, $admin]))->assertOk()->assertSee('administrador do sistema');
        $this->put(route('associations.users.roles.update', [$association, $admin]), ['roles' => [AssociationRoleCatalog::PORTAL]])->assertSessionHasErrors('roles');
        $this->post(route('associations.users.deactivate', [$association, $admin]))->assertSessionHasErrors('roles');
        $this->assertSame('active', $admin->fresh()->status);
    }

    public function test_deactivation_ends_sessions_and_reactivation_restores_access(): void
    {
        [$association, $manager] = $this->associationWithManager();
        $account = $this->accountWithRoles($association, [AssociationRoleCatalog::FINANCE]);
        DB::table('sessions')->insert(['id' => 'sessao-teste', 'user_id' => $account->id, 'ip_address' => '127.0.0.1', 'user_agent' => 'teste', 'payload' => '', 'last_activity' => time()]);

        $this->actingAs($manager)->post(route('associations.users.deactivate', [$association, $account]))->assertSessionHasNoErrors();
        $this->assertSame('inactive', $account->fresh()->status);
        $this->assertDatabaseMissing('sessions', ['user_id' => $account->id]);
        $this->assertFalse($account->fresh()->hasPermission('finance.view', $association->id));
        $this->assertDatabaseHas('audit_logs', ['event_type' => 'user_account.deactivated', 'entity_id' => $account->id]);

        $this->post(route('associations.users.deactivate', [$association, $manager]))->assertSessionHasErrors('status');

        $this->post(route('associations.users.reactivate', [$association, $account]))->assertSessionHasNoErrors();
        $this->assertSame('active', $account->fresh()->status);
        $this->assertTrue($account->fresh()->hasPermission('finance.view', $association->id));
    }

    public function test_release_portal_from_student_record_gives_portal_access(): void
    {
        [$association, $manager] = $this->associationWithManager();
        $dojo = Dojo::query()->create(['association_id' => $association->id, 'code' => 'D'.uniqid(), 'name' => 'Dojo Central']);
        $person = $this->person($association, 'Aluno Portal', 'aluno@example.com', 'student');
        $student = Student::query()->create(['association_id' => $association->id, 'person_id' => $person->id, 'registration_number' => 'M'.uniqid(), 'entry_date' => today()->subYear(), 'primary_dojo_id' => $dojo->id, 'status' => 'active']);

        $create = route('associations.users.create', ['association' => $association, 'person_id' => $person->id, 'perfil' => AssociationRoleCatalog::PORTAL]);
        $this->actingAs($manager)->get(route('associations.students.show', [$association, $student]))->assertOk()->assertSee('Liberar portal')->assertSee(e($create), false);
        $this->get($create)->assertOk()->assertSee('aluno@example.com');

        $account = $this->invite($manager, $association, $person, [AssociationRoleCatalog::PORTAL]);
        $this->get(route('associations.students.show', [$association, $student]))->assertSee('aguardando ativação');
        auth()->logout();
        $this->post($this->activationPostUrl($this->sentActivationUrl('aluno@example.com')), ['email' => 'aluno@example.com', 'password' => 'SenhaDoAluno123', 'password_confirmation' => 'SenhaDoAluno123']);

        $this->actingAs($account->fresh())->get(route('portal.index'))->assertRedirect();
        $this->followingRedirects()->get(route('portal.index'))->assertOk()->assertSee('Aluno Portal');
        $this->get(route('associations.users.index', $association))->assertForbidden();
    }

    private function associationWithManager(): array
    {
        $association = Association::query()->create(['legal_name' => 'Associação Usuários '.uniqid(), 'trade_name' => 'Usuários']);

        return [$association, $this->accountWithRoles($association, [AssociationRoleCatalog::MANAGER])];
    }

    private function accountWithRoles(Association $association, array $codes): User
    {
        $user = User::factory()->create(['association_id' => $association->id]);
        foreach (AssociationRoleCatalog::ensure()->only($codes) as $role) {
            $user->roles()->attach($role, ['association_id' => $association->id]);
        }

        return $user;
    }

    private function person(Association $association, string $name, string $email, string $roleType = 'staff'): Person
    {
        $person = Person::query()->create(['full_name' => $name, 'email' => $email]);
        $association->people()->attach($person, ['role_type' => $roleType, 'start_date' => today()->subYear(), 'status' => 'active']);

        return $person;
    }

    private function invite(User $actor, Association $association, Person $person, array $codes): User
    {
        $this->actingAs($actor)->post(route('associations.users.store', $association), ['person_id' => $person->id, 'email' => $person->email, 'roles' => $codes])
            ->assertSessionHasNoErrors();

        return User::query()->where('person_id', $person->id)->firstOrFail();
    }

    private function sentActivationUrl(string $email, bool $last = false): string
    {
        $sent = Mail::sent(AccountActivationMail::class, fn (AccountActivationMail $mail) => $mail->hasTo($email));
        $this->assertNotEmpty($sent, 'Convite de ativação não enviado.');

        return ($last ? $sent->last() : $sent->first())->activationUrl;
    }

    private function activationPostUrl(string $url): string
    {
        return strtok($url, '?');
    }
}
