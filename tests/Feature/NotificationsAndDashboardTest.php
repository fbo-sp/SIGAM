<?php

namespace Tests\Feature;

use App\Models\Association;
use App\Models\Charge;
use App\Models\ClassGroup;
use App\Models\ClassSession;
use App\Models\Dojo;
use App\Models\DojoRoleAssignment;
use App\Models\DojoTeacherAssignment;
use App\Models\MartialArt;
use App\Models\Notification;
use App\Models\NotificationTemplate;
use App\Models\Permission;
use App\Models\Person;
use App\Models\Role;
use App\Models\Student;
use App\Models\TeacherAssociationMembership;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use App\Services\Reporting\DashboardService;
use App\Support\Notifications\NotificationCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\TestCase;

class NotificationsAndDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_generation_and_email_delivery_are_idempotent_and_observable(): void
    {
        [$association, $dojo, $student, $user] = $this->context();
        $this->templates();
        Charge::query()->create(['association_id' => $association->id, 'dojo_id' => $dojo->id, 'student_id' => $student->id, 'person_id' => $student->person_id, 'source_type' => 'manual', 'source_id' => 9001, 'description' => 'Mensalidade de setembro', 'competence' => today()->startOfMonth(), 'issued_at' => now(), 'due_date' => today()->addDays(2), 'original_amount' => '100.00', 'discount_amount' => '0.00', 'final_amount' => '100.00', 'paid_amount' => '0.00', 'balance_amount' => '100.00', 'calculation_snapshot' => [], 'due_policy_snapshot' => [], 'status' => 'open', 'created_by' => $user->id]);

        $this->artisan('sigam:notifications:generate', ['--association' => $association->id])->assertSuccessful();
        $this->artisan('sigam:notifications:generate', ['--association' => $association->id])->assertSuccessful();
        $this->assertDatabaseCount('notification_events', 1);
        $this->assertDatabaseCount('notifications', 2);
        $this->assertDatabaseHas('scheduled_job_runs', ['job_name' => 'GenerateNotifications', 'status' => 'completed']);

        Mail::fake();
        $this->artisan('sigam:notifications:send')->assertSuccessful();
        $this->assertDatabaseHas('notifications', ['user_id' => $user->id, 'channel' => 'email', 'status' => 'sent', 'attempt_count' => 1]);
    }

    public function test_preferences_disable_email_without_disabling_internal_inbox(): void
    {
        [$association, , , $user] = $this->context();
        $this->templates();
        $this->actingAs($user)->put(route('notifications.preferences'), ['preferences' => ['charge.due_soon' => ['internal' => '1']]])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('notification_preferences', ['user_id' => $user->id, 'event_type' => 'charge.due_soon', 'internal_enabled' => true, 'email_enabled' => false]);

        app(NotificationService::class)->publish($association, 'charge.due_soon', 'preference-test', ['item' => 'Mensalidade', 'date' => '10/09/2026', 'student_name' => 'Aluno'], collect([$user]));
        $this->assertDatabaseHas('notifications', ['user_id' => $user->id, 'channel' => 'internal']);
        $this->assertDatabaseMissing('notifications', ['user_id' => $user->id, 'channel' => 'email']);
    }

    public function test_email_failure_is_observable_and_scheduled_for_retry(): void
    {
        [$association, , , $user] = $this->context();
        $this->templates();
        app(NotificationService::class)->publish($association, 'championship.opened', 'smtp-failure', ['item' => 'Copa', 'date' => '10/09/2026', 'student_name' => ''], collect([$user]));
        Mail::shouldReceive('raw')->once()->andThrow(new RuntimeException('SMTP indisponível'));

        $this->artisan('sigam:notifications:send')->assertExitCode(1);

        $delivery = Notification::query()->where('channel', 'email')->firstOrFail();
        $this->assertSame('failed', $delivery->status);
        $this->assertSame(1, $delivery->attempt_count);
        $this->assertStringContainsString('SMTP indisponível', $delivery->error_message);
        $this->assertTrue($delivery->scheduled_at->isFuture());
    }

    public function test_inbox_cannot_mark_another_users_notification_as_read(): void
    {
        [$association, , , $user] = $this->context();
        $this->templates();
        $other = User::factory()->create(['association_id' => $association->id]);
        app(NotificationService::class)->publish($association, 'championship.opened', 'inbox-scope', ['item' => 'Copa', 'date' => '10/09/2026', 'student_name' => ''], collect([$other]));
        $notification = Notification::query()->where('user_id', $other->id)->where('channel', 'internal')->firstOrFail();

        $this->actingAs($user)->post(route('notifications.read', $notification))->assertNotFound();
        $this->assertNull($notification->fresh()->read_at);
    }

    public function test_association_and_dojo_dashboards_apply_backend_scope(): void
    {
        [$association, $dojo, , $dojoUser] = $this->context(['dashboard.dojo']);
        $dojoUser->update(['dojo_id' => $dojo->id]);
        $otherDojo = Dojo::query()->create(['association_id' => $association->id, 'code' => 'O'.uniqid(), 'name' => 'Outro dojo']);
        $this->student($association, $otherDojo, 'Aluno fora do escopo');

        $dojoDashboard = app(DashboardService::class)->for($dojoUser->fresh());
        $this->assertSame('dojo', $dojoDashboard['kind']);
        $this->assertSame(1, $dojoDashboard['metrics']['active_students']);
        $this->actingAs($dojoUser)->get(route('dashboard'))->assertOk()->assertSee('Painel do dojo');

        $associationUser = $this->user($association, null, ['dashboard.association']);
        $associationDashboard = app(DashboardService::class)->for($associationUser);
        $this->assertSame('association', $associationDashboard['kind']);
        $this->assertSame(2, $associationDashboard['metrics']['active_students']);
    }

    public function test_teacher_dashboard_only_uses_the_teachers_classes(): void
    {
        [$association, $dojo] = $this->context();
        $person = Person::query()->create(['full_name' => 'Professora do painel']);
        $association->people()->attach($person, ['role_type' => 'teacher', 'start_date' => today(), 'status' => 'active']);
        $teacher = TeacherProfile::query()->create(['person_id' => $person->id, 'registration_number' => 'PROF-1', 'status' => 'active']);
        $art = MartialArt::query()->create(['association_id' => $association->id, 'name' => 'Karatê', 'status' => 'active']);
        $group = ClassGroup::query()->create(['association_id' => $association->id, 'dojo_id' => $dojo->id, 'martial_art_id' => $art->id, 'name' => 'Turma da manhã', 'main_teacher_id' => $teacher->id, 'start_date' => today(), 'status' => 'active']);
        ClassSession::query()->create(['class_group_id' => $group->id, 'session_date' => today(), 'start_time' => '09:00', 'end_time' => '10:00', 'planned_teacher_id' => $teacher->id, 'status' => 'planned', 'attendance_state' => 'open']);
        $user = $this->user($association, $person, ['dashboard.teacher', 'session.view']);

        $dashboard = app(DashboardService::class)->for($user);
        $this->assertSame('teacher', $dashboard['kind']);
        $this->assertSame(1, $dashboard['metrics']['today_classes']);
        $this->assertSame(1, $dashboard['metrics']['pending_attendance']);
        $this->actingAs($user)->get(route('dashboard'))->assertOk()->assertSee('Painel do professor')->assertSee('Turma da manhã');
    }

    public function test_teacher_dashboard_links_next_sessions_to_the_dojo_attendance_screen(): void
    {
        [$association, $dojo] = $this->context();
        $person = Person::query()->create(['full_name' => 'Professora com chamada']);
        $association->people()->attach($person, ['role_type' => 'teacher', 'start_date' => today(), 'status' => 'active']);
        $teacher = TeacherProfile::query()->create(['person_id' => $person->id, 'registration_number' => 'PROF-2', 'status' => 'active']);
        TeacherAssociationMembership::query()->create(['association_id' => $association->id, 'teacher_profile_id' => $teacher->id, 'status' => 'active']);
        $art = MartialArt::query()->create(['association_id' => $association->id, 'name' => 'Judô', 'status' => 'active']);
        $group = ClassGroup::query()->create(['association_id' => $association->id, 'dojo_id' => $dojo->id, 'martial_art_id' => $art->id, 'name' => 'Turma da tarde', 'main_teacher_id' => $teacher->id, 'start_date' => today(), 'status' => 'active']);
        $session = ClassSession::query()->create(['class_group_id' => $group->id, 'session_date' => today()->addDay(), 'start_time' => '15:00', 'end_time' => '16:00', 'planned_teacher_id' => $teacher->id, 'status' => 'planned', 'attendance_state' => 'open']);
        $user = $this->user($association, $person, ['dashboard.teacher']);

        $this->actingAs($user)->get(route('dashboard'))->assertOk()->assertSee('peça ao gestor acesso de professor ao dojo')
            ->assertDontSee(route('associations.attendance.show', [$association, $session]), false);

        $role = Role::query()->create(['association_id' => $association->id, 'name' => 'Professor', 'code' => 'teacher_'.uniqid(), 'scope_type' => 'teacher']);
        $role->permissions()->sync(collect(['dojo.access', 'class.view', 'session.view', 'attendance.manage'])->map(fn ($code) => Permission::query()->firstOrCreate(['code' => $code], ['description' => $code])->id));
        DojoRoleAssignment::query()->create(['association_id' => $association->id, 'dojo_id' => $dojo->id, 'user_id' => $user->id, 'role_id' => $role->id, 'start_date' => today(), 'created_by_user_id' => $user->id]);
        DojoTeacherAssignment::query()->create(['association_id' => $association->id, 'dojo_id' => $dojo->id, 'teacher_profile_id' => $teacher->id, 'start_date' => today(), 'created_by_user_id' => $user->id]);

        $link = route('operation.session', [$dojo, $session]);
        $this->get(route('dashboard'))->assertOk()->assertSee($link, false);
        $this->get($link)->assertOk()->assertSee('Turma da tarde');
    }

    private function context(array $permissions = ['portal.access']): array
    {
        $association = Association::query()->create(['legal_name' => 'Associação '.uniqid(), 'trade_name' => 'Associação']);
        $dojo = Dojo::query()->create(['association_id' => $association->id, 'code' => 'N'.uniqid(), 'name' => 'Dojo principal']);
        $student = $this->student($association, $dojo, 'Aluno notificado');
        $user = $this->user($association, $student->person, $permissions);

        return [$association, $dojo, $student, $user];
    }

    private function student(Association $association, Dojo $dojo, string $name): Student
    {
        $person = Person::query()->create(['full_name' => $name.' '.uniqid(), 'email' => uniqid().'@example.test']);
        $association->people()->attach($person, ['role_type' => 'student', 'start_date' => today()->subYear(), 'status' => 'active']);

        return Student::query()->create(['association_id' => $association->id, 'person_id' => $person->id, 'registration_number' => 'N'.uniqid(), 'entry_date' => today()->subYear(), 'primary_dojo_id' => $dojo->id, 'status' => 'active']);
    }

    private function user(Association $association, ?Person $person, array $codes): User
    {
        $permissions = collect($codes)->map(fn (string $code) => Permission::query()->firstOrCreate(['code' => $code], ['description' => $code]));
        $role = Role::query()->create(['association_id' => $association->id, 'name' => 'Dashboard', 'code' => 'dashboard_'.uniqid(), 'scope_type' => 'association']);
        $role->permissions()->sync($permissions->pluck('id'));
        $user = User::factory()->create(['association_id' => $association->id, 'person_id' => $person?->id]);
        $user->roles()->attach($role, ['association_id' => $association->id]);

        return $user;
    }

    private function templates(): void
    {
        foreach (NotificationCatalog::templates() as $eventType => [$subject, $body]) {
            foreach (['internal', 'email'] as $channel) {
                NotificationTemplate::query()->create(['event_type' => $eventType, 'channel' => $channel, 'subject_template' => $subject, 'body_template' => $body, 'status' => 'active']);
            }
        }
    }
}
