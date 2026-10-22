<?php

namespace Tests\Feature;

use App\Models\Association;
use App\Models\Dojo;
use App\Models\DojoRoleAssignment;
use App\Models\DojoTeacherAssignment;
use App\Models\Permission;
use App\Models\Person;
use App\Models\Role;
use App\Models\TeacherAssociationMembership;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Services\Access\OperationalScope;
use App\Services\Access\TeacherInvitationService;
use Database\Seeders\OperationalRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class TeacherDojoAccessTest extends TestCase
{
    use RefreshDatabase;

    private Association $association;

    private Dojo $dojo;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(OperationalRolesSeeder::class);
        $this->association = Association::query()->create(['legal_name' => 'Associação', 'trade_name' => 'Associação', 'status' => 'active']);
        $this->dojo = Dojo::query()->create(['association_id' => $this->association->id, 'code' => 'N', 'name' => 'Dojo Norte']);
        $this->manager = User::factory()->create(['association_id' => $this->association->id]);
        $this->grant($this->manager, 'dojo_operator');
    }

    public function test_linking_teacher_grants_dojo_access_and_unlinking_revokes_it(): void
    {
        [$teacher, $user] = $this->teacher('Professor Caio', true);
        $this->assertSame([], $this->dojoIds($user));

        $this->actingAs($this->manager)->post(route('operation.teachers.link', $this->dojo), [
            'teacher_profile_id' => $teacher->id, 'start_date' => today()->toDateString(),
        ])->assertSessionHasNoErrors();
        $grant = DojoRoleAssignment::query()->where('user_id', $user->id)->sole();
        $this->assertSame('dojo_teacher', $grant->role->code);
        $this->assertSame($this->manager->id, $grant->created_by_user_id);
        $this->assertDatabaseHas('audit_logs', ['event_type' => 'dojo_access.granted', 'association_id' => $this->association->id]);
        $this->assertSame([$this->dojo->id], $this->dojoIds($user));
        $this->actingAs($user->fresh())->get(route('operation.sessions', $this->dojo))->assertOk();

        $link = DojoTeacherAssignment::query()->sole();
        $this->actingAs($this->manager)->post(route('operation.teachers.unlink', [$this->dojo, $link]))->assertSessionHasNoErrors();
        $this->assertNotNull($grant->fresh()->revoked_at);
        $this->assertDatabaseHas('audit_logs', ['event_type' => 'dojo_access.revoked', 'association_id' => $this->association->id]);
        $this->assertSame([], $this->dojoIds($user));
    }

    public function test_existing_manual_grant_is_not_duplicated(): void
    {
        [$teacher, $user] = $this->teacher('Professor Caio', true);
        $this->grant($user, 'dojo_teacher');

        $this->actingAs($this->manager)->post(route('operation.teachers.link', $this->dojo), [
            'teacher_profile_id' => $teacher->id, 'start_date' => today()->toDateString(),
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, DojoRoleAssignment::query()->where('user_id', $user->id)->count());
    }

    public function test_teacher_without_account_gets_access_when_accepting_invitation(): void
    {
        [$teacher] = $this->teacher('Professora Ana', false);
        $this->actingAs($this->manager)->post(route('operation.teachers.link', $this->dojo), [
            'teacher_profile_id' => $teacher->id, 'start_date' => today()->toDateString(),
        ])->assertSessionHasNoErrors();
        $this->assertSame(0, DojoRoleAssignment::query()->whereHas('role', fn ($q) => $q->where('code', 'dojo_teacher'))->count());

        $inviter = User::factory()->create(['association_id' => $this->association->id]);
        $inviter->roles()->attach($this->associationRole(['teacher.manage']), ['association_id' => $this->association->id]);
        $issued = app(TeacherInvitationService::class)->issue($inviter, $this->association, $teacher);
        $this->post(route('logout'));
        $this->post(route('teacher-invitations.accept', ['token' => $issued['token']]), [
            'password' => 'SenhaSegura123', 'password_confirmation' => 'SenhaSegura123', 'accept_terms' => '1',
        ])->assertRedirect(route('dashboard'));

        $user = User::query()->where('person_id', $teacher->person_id)->sole();
        $this->assertSame([$this->dojo->id], $this->dojoIds($user));
        $this->assertSame(1, DojoRoleAssignment::query()->where('user_id', $user->id)->count());
    }

    public function test_sync_command_is_dry_run_by_default_and_idempotent_with_apply(): void
    {
        [$teacher, $user] = $this->teacher('Professor Caio', true);
        DojoTeacherAssignment::query()->create([
            'association_id' => $this->association->id, 'dojo_id' => $this->dojo->id, 'teacher_profile_id' => $teacher->id,
            'start_date' => today()->subMonth(), 'created_by_user_id' => $this->manager->id,
        ]);

        $this->artisan('sigam:access:sync-teacher-dojos')->assertSuccessful();
        $this->assertSame(0, DojoRoleAssignment::query()->where('user_id', $user->id)->count());

        $this->artisan('sigam:access:sync-teacher-dojos', ['--apply' => true])->assertSuccessful();
        $this->artisan('sigam:access:sync-teacher-dojos', ['--apply' => true])->assertSuccessful();
        $grant = DojoRoleAssignment::query()->where('user_id', $user->id)->sole();
        $this->assertSame(today()->subMonth()->toDateString(), $grant->start_date->toDateString());
        $this->assertSame([$this->dojo->id], $this->dojoIds($user));
    }

    /** @return array{0: TeacherProfile, 1: ?User} */
    private function teacher(string $name, bool $withAccount): array
    {
        $person = Person::query()->create(['full_name' => $name, 'email' => Str::random(8).'@example.com', 'status' => 'active']);
        $this->association->people()->attach($person, ['role_type' => 'teacher', 'start_date' => today()->subYear(), 'status' => 'active']);
        $teacher = TeacherProfile::query()->create(['person_id' => $person->id, 'status' => 'active']);
        TeacherAssociationMembership::query()->create(['association_id' => $this->association->id, 'teacher_profile_id' => $teacher->id, 'status' => 'active']);
        $user = $withAccount ? User::factory()->create(['association_id' => $this->association->id, 'person_id' => $person->id, 'email' => $person->email]) : null;

        return [$teacher, $user];
    }

    /** Dojos em que o usuário entra como professor, pelo mesmo escopo usado nas rotas. */
    private function dojoIds(User $user): array
    {
        return Dojo::query()->get()->filter(fn (Dojo $dojo) => app(OperationalScope::class)->canEnter($user->fresh(), $dojo))
            ->pluck('id')->values()->all();
    }

    private function grant(User $user, string $code): void
    {
        DojoRoleAssignment::query()->create([
            'association_id' => $this->association->id, 'dojo_id' => $this->dojo->id, 'user_id' => $user->id,
            'role_id' => Role::query()->where('code', $code)->firstOrFail()->id, 'start_date' => today()->subDay(), 'created_by_user_id' => $this->manager->id,
        ]);
    }

    private function associationRole(array $codes): Role
    {
        $role = Role::query()->create(['association_id' => $this->association->id, 'name' => 'Perfil', 'code' => 'role_'.Str::random(8), 'scope_type' => 'association']);
        $role->permissions()->sync(collect($codes)->map(fn ($code) => Permission::query()->firstOrCreate(['code' => $code], ['description' => $code])->id));

        return $role;
    }
}
