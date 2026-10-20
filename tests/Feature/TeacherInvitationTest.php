<?php

namespace Tests\Feature;

use App\Mail\PasswordResetMail;
use App\Mail\TeacherInvitationMail;
use App\Models\Association;
use App\Models\MartialArt;
use App\Models\Permission;
use App\Models\Person;
use App\Models\Role;
use App\Models\TeacherAssociationMembership;
use App\Models\TeacherInvitation;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Services\Access\TeacherInvitationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class TeacherInvitationTest extends TestCase
{
    use RefreshDatabase;

    public function test_direct_teacher_registration_creates_pending_account_and_invitation_without_default_password(): void
    {
        [$association, $manager, $art] = $this->context();

        $this->actingAs($manager)->post(route('associations.teachers.direct.store', $association), [
            'idempotency_key' => (string) Str::uuid(),
            'person_mode' => 'new',
            'person' => ['full_name' => 'Professora Convidada', 'email' => 'PROFESSORA@example.com'],
            'martial_art_id' => $art->id,
            'send_invitation' => '1',
        ])->assertSessionHasNoErrors();

        $user = User::query()->where('email', 'professora@example.com')->firstOrFail();
        $this->assertSame('pending', $user->status);
        $this->assertNull($user->email_verified_at);
        $this->assertDatabaseHas('teacher_invitations', ['user_id' => $user->id, 'status' => 'pending', 'delivery_status' => 'pending']);
        $this->post(route('logout'))->assertRedirect(route('login'));
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'qualquer-senha'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_system_administrator_registers_teacher_with_invitation_without_association_link(): void
    {
        [$association, , $art] = $this->context();
        $admin = User::factory()->create(['association_id' => null]);
        $role = Role::query()->create(['association_id' => null, 'name' => 'Administrador', 'code' => 'role_'.Str::random(8), 'scope_type' => 'system']);
        $role->permissions()->sync([Permission::query()->firstOrCreate(['code' => 'teacher.manage'], ['description' => 'teacher.manage'])->id]);
        $admin->roles()->attach($role, ['association_id' => null]);

        $this->actingAs($admin)->post(route('associations.teachers.direct.store', $association), [
            'idempotency_key' => (string) Str::uuid(),
            'person_mode' => 'new',
            'person' => ['full_name' => 'Professor do Admin', 'email' => 'professor.admin@example.com'],
            'martial_art_id' => $art->id,
            'send_invitation' => '1',
        ])->assertSessionHasNoErrors()->assertRedirect(route('associations.teachers.index', $association));

        $user = User::query()->where('email', 'professor.admin@example.com')->firstOrFail();
        $this->assertDatabaseHas('teacher_invitations', ['user_id' => $user->id, 'association_id' => $association->id, 'status' => 'pending']);
    }

    public function test_manager_of_another_association_cannot_issue_invitation(): void
    {
        [$association, $manager] = $this->context();
        [$other] = $this->context();
        $person = $this->person($other, 'Professora Externa', 'externa@example.com');
        $teacher = $this->teacher($other, $person);

        $this->expectException(HttpException::class);
        app(TeacherInvitationService::class)->issue($manager, $other, $teacher);
    }

    public function test_get_does_not_accept_and_post_activates_new_account_only_once(): void
    {
        Mail::fake();
        [$association, $manager] = $this->context();
        [$teacher, $token] = $this->invitation($association, $manager);
        $this->artisan('sigam:teacher-invitations:send')->assertSuccessful();
        Mail::assertSent(TeacherInvitationMail::class, fn ($mail) => str_contains($mail->acceptUrl, $token));
        $invitation = TeacherInvitation::query()->firstOrFail();

        $this->get(route('teacher-invitations.show', ['token' => $token]))
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertSee($association->trade_name)->assertSee('não verificou seu e-mail');
        $this->assertSame('sent', $invitation->fresh()->status);
        $this->assertNull($invitation->fresh()->accepted_at);
        $this->assertNull($invitation->user->fresh()->email_verified_at);

        $this->post(route('teacher-invitations.accept', ['token' => $token]), [
            'password' => 'SenhaSegura123', 'password_confirmation' => 'SenhaSegura123', 'accept_terms' => '1',
        ])->assertRedirect(route('dashboard'));
        $user = $invitation->user->fresh();
        $this->assertAuthenticatedAs($user);
        $this->assertSame('active', $user->status);
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue(Hash::check('SenhaSegura123', $user->password));
        $this->assertTrue($user->hasPermission('dashboard.teacher', $association->id));
        $this->assertDatabaseHas('teacher_invitations', ['id' => $invitation->id, 'status' => 'accepted']);
        $this->assertDatabaseHas('audit_logs', ['event_type' => 'teacher_invitation.accepted', 'association_id' => $association->id]);
        $this->post(route('teacher-invitations.accept', ['token' => $token]), [
            'password' => 'OutraSenha123', 'password_confirmation' => 'OutraSenha123', 'accept_terms' => '1',
        ])->assertSessionHasErrors('invitation');
        $this->assertSame($teacher->id, $invitation->teacher_profile_id);
    }

    public function test_existing_account_keeps_password_and_roles_when_accepting(): void
    {
        [$association, $manager] = $this->context();
        $person = $this->person($association, 'Professor Existente', 'existente@example.com');
        $teacher = $this->teacher($association, $person);
        $oldRole = $this->role($association, ['portal.access']);
        $user = User::factory()->create(['association_id' => $association->id, 'person_id' => $person->id, 'email' => $person->email, 'password' => Hash::make('SenhaAntiga123')]);
        $user->roles()->attach($oldRole, ['association_id' => $association->id]);
        $oldHash = $user->password;
        $this->actingAs($manager);
        $issued = app(TeacherInvitationService::class)->issue($manager, $association, $teacher);

        $this->post(route('teacher-invitations.accept', ['token' => $issued['token']]), [
            'current_password' => 'SenhaIncorreta123', 'accept_terms' => '1',
        ])->assertSessionHasErrors('current_password');
        $this->assertSame('pending', $issued['invitation']->fresh()->status);
        $this->assertSame($oldHash, $user->fresh()->password);

        $this->post(route('teacher-invitations.accept', ['token' => $issued['token']]), [
            'current_password' => 'SenhaAntiga123', 'accept_terms' => '1',
        ])->assertRedirect(route('dashboard'));

        $user->refresh();
        $this->assertSame($oldHash, $user->password);
        $this->assertTrue($user->roles()->whereKey($oldRole->id)->exists());
        $this->assertTrue($user->hasPermission('dashboard.teacher', $association->id));
    }

    public function test_wrong_existing_password_expired_revoked_and_reused_tokens_do_not_activate(): void
    {
        [$association, $manager] = $this->context();
        [, $token] = $this->invitation($association, $manager);
        $invitation = TeacherInvitation::query()->firstOrFail();
        $invitation->update(['expires_at' => now()->subMinute()]);
        $this->actingAs($manager)->post(route('teacher-invitations.accept', ['token' => $token]), [
            'password' => 'SenhaSegura123', 'password_confirmation' => 'SenhaSegura123', 'accept_terms' => '1',
        ])->assertSessionHasErrors('invitation');
        $this->assertSame('pending', $invitation->user->fresh()->status);

        $fresh = app(TeacherInvitationService::class)->issue($manager, $association, $invitation->teacher);
        $this->post(route('associations.teacher-invitations.revoke', [$association, $fresh['invitation']]))->assertSessionHasNoErrors();
        $this->post(route('teacher-invitations.accept', ['token' => $fresh['token']]), [
            'password' => 'SenhaSegura123', 'password_confirmation' => 'SenhaSegura123', 'accept_terms' => '1',
        ])->assertSessionHasErrors('invitation');
        $this->assertSame('pending', $fresh['invitation']->user->fresh()->status);
    }

    public function test_reissue_invalidates_previous_token_and_decline_preserves_profile(): void
    {
        [$association, $manager] = $this->context();
        [$teacher, $oldToken] = $this->invitation($association, $manager);
        $old = TeacherInvitation::query()->firstOrFail();

        $this->actingAs($manager)->post(route('associations.teacher-invitations.reissue', [$association, $old]))->assertSessionHasNoErrors();
        $new = TeacherInvitation::query()->latest('id')->firstOrFail();
        $newToken = $new->encrypted_token;
        $this->assertSame('revoked', $old->fresh()->status);
        $this->post(route('teacher-invitations.accept', ['token' => $oldToken]), [
            'password' => 'SenhaSegura123', 'password_confirmation' => 'SenhaSegura123', 'accept_terms' => '1',
        ])->assertSessionHasErrors('invitation');
        $this->post(route('teacher-invitations.decline', ['token' => $newToken]), ['decline_confirmation' => '1'])->assertRedirect(route('login'));
        $this->assertSame('declined', $new->fresh()->status);
        $this->assertDatabaseHas('teacher_profiles', ['id' => $teacher->id, 'status' => 'active']);
        $this->assertDatabaseHas('teacher_association_memberships', ['teacher_profile_id' => $teacher->id, 'status' => 'active']);
    }

    public function test_delivery_failure_is_observable_and_retryable_without_plain_token_column(): void
    {
        [$association, $manager] = $this->context();
        [, $token] = $this->invitation($association, $manager);
        Mail::shouldReceive('to')->once()->andReturnSelf();
        Mail::shouldReceive('send')->once()->andThrow(new RuntimeException('segredo do transporte'));

        $this->artisan('sigam:teacher-invitations:send')->assertExitCode(1);
        $invitation = TeacherInvitation::query()->firstOrFail();
        $this->assertSame('failed', $invitation->delivery_status);
        $this->assertSame(1, $invitation->attempt_count);
        $this->assertStringNotContainsString('segredo do transporte', (string) $invitation->error_message);
        $this->assertSame($token, $invitation->encrypted_token);
        $this->assertNotSame($token, $invitation->getRawOriginal('encrypted_token'));
    }

    public function test_interrupted_delivery_claim_is_recovered_idempotently(): void
    {
        Mail::fake();
        [$association, $manager] = $this->context();
        $this->invitation($association, $manager);
        $invitation = TeacherInvitation::query()->firstOrFail();
        $invitation->update([
            'delivery_status' => 'sending',
            'attempt_count' => 1,
            'last_attempt_at' => now()->subMinutes(16),
        ]);

        $this->artisan('sigam:teacher-invitations:send')->assertSuccessful();

        $this->assertSame('sent', $invitation->fresh()->delivery_status);
        $this->assertSame(2, $invitation->fresh()->attempt_count);
        Mail::assertSent(TeacherInvitationMail::class, 1);
    }

    public function test_email_change_invalidates_invitation_and_unprivileged_user_cannot_issue_one(): void
    {
        [$association, $manager] = $this->context();
        [$teacher, $token] = $this->invitation($association, $manager);
        $teacher->person->update(['email' => 'novo@example.com']);

        $this->post(route('teacher-invitations.accept', ['token' => $token]), [
            'password' => 'SenhaSegura123', 'password_confirmation' => 'SenhaSegura123', 'accept_terms' => '1',
        ])->assertSessionHasErrors('invitation');
        $outsider = User::factory()->create(['association_id' => $association->id]);
        $this->actingAs($outsider)->post(route('associations.teacher-invitations.store', [$association, $teacher]))->assertForbidden();
    }

    public function test_password_recovery_is_generic_and_resets_active_account_with_single_use_token(): void
    {
        Mail::fake();
        $user = User::factory()->create(['email' => 'recuperar@example.com', 'password' => Hash::make('SenhaAntiga123')]);

        $known = $this->post(route('password.email'), ['email' => $user->email]);
        $unknown = $this->post(route('password.email'), ['email' => 'ausente@example.com']);
        $known->assertSessionHas('status');
        $unknown->assertSessionHas('status', $known->getSession()->get('status'));
        $resetUrl = null;
        Mail::assertSent(PasswordResetMail::class, function ($mail) use (&$resetUrl): bool {
            $resetUrl = $mail->resetUrl;

            return true;
        });
        $parts = parse_url($resetUrl);
        parse_str($parts['query'] ?? '', $query);
        $token = basename($parts['path']);

        $this->get($resetUrl)->assertOk()->assertHeader('Referrer-Policy', 'no-referrer');
        $this->post(route('password.update'), [
            'token' => $token, 'email' => $query['email'],
            'password' => 'SenhaNova1234', 'password_confirmation' => 'SenhaNova1234',
        ])->assertRedirect(route('login'));
        $this->assertTrue(Hash::check('SenhaNova1234', $user->fresh()->password));
        $this->post(route('password.update'), [
            'token' => $token, 'email' => $query['email'],
            'password' => 'OutraSenha1234', 'password_confirmation' => 'OutraSenha1234',
        ])->assertSessionHasErrors('email');
    }

    private function invitation(Association $association, User $manager): array
    {
        $person = $this->person($association, 'Professora Teste', Str::random(8).'@example.com');
        $teacher = $this->teacher($association, $person);
        $this->actingAs($manager);
        $this->assertTrue($manager->hasPermission('teacher.manage', $association->id));
        $issued = app(TeacherInvitationService::class)->issue($manager, $association, $teacher);

        return [$teacher, $issued['token']];
    }

    private function teacher(Association $association, Person $person): TeacherProfile
    {
        $teacher = TeacherProfile::query()->create(['person_id' => $person->id, 'status' => 'active']);
        TeacherAssociationMembership::query()->create(['association_id' => $association->id, 'teacher_profile_id' => $teacher->id, 'status' => 'active']);

        return $teacher;
    }

    private function person(Association $association, string $name, string $email): Person
    {
        $person = Person::query()->create(['full_name' => $name, 'email' => $email, 'status' => 'active']);
        $association->people()->attach($person, ['role_type' => 'teacher', 'start_date' => today(), 'status' => 'active']);

        return $person;
    }

    private function context(): array
    {
        $association = Association::query()->create(['legal_name' => 'Associação', 'trade_name' => 'Associação']);
        $manager = User::factory()->create(['association_id' => $association->id]);
        $role = $this->role($association, ['teacher.manage']);
        $manager->roles()->attach($role, ['association_id' => $association->id]);
        $art = MartialArt::query()->create(['association_id' => $association->id, 'name' => 'Judô']);

        return [$association, $manager, $art];
    }

    private function role(Association $association, array $codes): Role
    {
        $role = Role::query()->create(['association_id' => $association->id, 'name' => 'Perfil', 'code' => 'role_'.Str::random(8), 'scope_type' => 'association']);
        $permissions = collect($codes)->map(fn ($code) => Permission::query()->firstOrCreate(['code' => $code], ['description' => $code])->id);
        $role->permissions()->sync($permissions);

        return $role;
    }
}
