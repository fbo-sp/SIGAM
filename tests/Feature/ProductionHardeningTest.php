<?php

namespace Tests\Feature;

use App\Models\Association;
use App\Models\Dojo;
use App\Models\Notification;
use App\Models\NotificationPreference;
use App\Models\NotificationTemplate;
use App\Models\Permission;
use App\Models\Person;
use App\Models\Role;
use App\Models\Student;
use App\Models\StudentGuardian;
use App\Models\User;
use App\Services\Notifications\EmailDeliveryService;
use App\Services\Notifications\NotificationService;
use App\Services\Operations\ProductionReadiness;
use App\Services\Reporting\DashboardService;
use App\Support\Export\CsvWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ProductionHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_private_pages_are_not_cacheable_and_print_uses_external_script(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get('/dashboard')->assertOk()->assertHeader('X-Frame-Options', 'DENY')->assertSee('js/common.js');
        $response = $this->get('/notificacoes');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_csv_neutralizes_formulas_after_whitespace(): void
    {
        foreach (['=1+1', '+cmd', '-cmd', '@SUM(1)', " \t=1+1", "\r\n@evil"] as $value) {
            $this->assertSame("'".$value, CsvWriter::cell($value));
        }
        $this->assertSame('123.45', CsvWriter::cell('123.45'));
    }

    public function test_seed_does_not_reset_or_elevate_existing_account_or_templates(): void
    {
        $user = User::factory()->create(['status' => 'inactive']);
        $hash = $user->password;
        $template = NotificationTemplate::query()->create(['event_type' => 'charge.due_soon', 'channel' => 'email', 'subject_template' => 'Personalizado', 'body_template' => 'Texto aprovado']);
        $env = Env::getRepository();
        $previousEmail = $env->get('SIGAM_ADMIN_EMAIL');
        $previousPassword = $env->get('SIGAM_ADMIN_PASSWORD');
        try {
            $env->set('SIGAM_ADMIN_EMAIL', $user->email);
            $env->set('SIGAM_ADMIN_PASSWORD', 'synthetic-test-only-password');
            $this->seed();
            $this->assertSame($hash, $user->fresh()->password);
            $this->assertSame('inactive', $user->fresh()->status);
            $this->assertSame(0, $user->roles()->count());
            $this->assertSame('Personalizado', $template->fresh()->subject_template);
        } finally {
            $previousEmail === null ? $env->clear('SIGAM_ADMIN_EMAIL') : $env->set('SIGAM_ADMIN_EMAIL', $previousEmail);
            $previousPassword === null ? $env->clear('SIGAM_ADMIN_PASSWORD') : $env->set('SIGAM_ADMIN_PASSWORD', $previousPassword);
        }
    }

    public function test_email_claim_prevents_interleaved_replay_and_sent_rows_are_not_retried(): void
    {
        $notification = $this->queuedEmail();
        Mail::shouldReceive('raw')->once()->andReturnUsing(function (): void {
            $this->assertSame(['processed' => 0, 'failed' => 0], app(EmailDeliveryService::class)->sendBatch(10));
        });
        $this->assertSame(['processed' => 1, 'failed' => 0], app(EmailDeliveryService::class)->sendBatch(10));
        $this->assertSame('sent', $notification->fresh()->status);
        $this->assertSame(0, app(EmailDeliveryService::class)->sendBatch(10)['processed']);
    }

    public function test_email_rechecks_preferences_and_does_not_expose_transport_secrets(): void
    {
        $notification = $this->queuedEmail();
        NotificationPreference::query()->create(['user_id' => $notification->user_id, 'event_type' => $notification->event_type, 'email_enabled' => false]);
        Mail::fake();
        app(EmailDeliveryService::class)->sendBatch(10);
        $this->assertSame('suppressed', $notification->fresh()->status);
        $notification->refresh()->update(['status' => 'pending']);
        NotificationPreference::query()->where('user_id', $notification->user_id)->update(['email_enabled' => true]);
        Mail::shouldReceive('raw')->once()->andThrow(new \RuntimeException('password=SYNTHETIC_SECRET'));
        app(EmailDeliveryService::class)->sendBatch(10);
        $this->assertStringNotContainsString('SYNTHETIC_SECRET', $notification->fresh()->error_message);
    }

    public function test_moved_user_cannot_read_previous_association_inbox(): void
    {
        $notification = $this->queuedEmail();
        $internal = Notification::query()->where('notification_event_id', $notification->notification_event_id)->where('channel', 'internal')->firstOrFail();
        $other = Association::query()->create(['legal_name' => 'Outra', 'trade_name' => 'Outra']);
        $user = $notification->user;
        $user->update(['association_id' => $other->id]);
        $this->actingAs($user)->post(route('notifications.read', $internal))->assertNotFound();
        $this->get('/notificacoes')->assertOk()->assertDontSee('Mensagem privada');
    }

    public function test_dashboard_query_count_is_bounded_on_synthetic_dataset(): void
    {
        $association = Association::query()->create(['legal_name' => 'Carga sintética', 'trade_name' => 'Carga']);
        $dojo = Dojo::query()->create(['association_id' => $association->id, 'code' => 'LOAD', 'name' => 'Dojo de carga']);
        $people = [];
        for ($i = 0; $i < 1000; $i++) {
            $people[] = ['full_name' => 'Carga sintética '.$i];
        }
        DB::table('people')->insert($people);
        $students = DB::table('people')->pluck('id')->map(fn ($id) => ['association_id' => $association->id, 'person_id' => $id, 'registration_number' => 'LOAD-'.$id, 'entry_date' => today()->format('Y-m-d'), 'primary_dojo_id' => $dojo->id, 'status' => 'active'])->all();
        foreach (array_chunk($students, 100) as $chunk) {
            DB::table('students')->insert($chunk);
        }
        $user = User::factory()->create(['association_id' => $association->id]);
        $permission = Permission::query()->create(['code' => 'dashboard.association', 'description' => 'Dashboard']);
        $role = Role::query()->create(['association_id' => $association->id, 'name' => 'Gestor', 'code' => 'load']);
        $role->permissions()->attach($permission);
        $user->roles()->attach($role, ['association_id' => $association->id]);
        DB::enableQueryLog();
        $times = [];
        for ($i = 0; $i < 10; $i++) {
            DB::flushQueryLog();
            $start = hrtime(true);
            $dashboard = app(DashboardService::class)->for($user);
            $times[] = (hrtime(true) - $start) / 1e6;
            $this->assertSame(1000, $dashboard['metrics']['active_students']);
            $this->assertLessThanOrEqual(30, count(DB::getQueryLog()));
        }
        DB::disableQueryLog();
        sort($times);
        fwrite(STDERR, sprintf("\nDashboard: 1000 alunos, 10 leituras, p50=%.1fms p95=%.1fms\n", $times[4], $times[9]));
        if (DB::getDriverName() === 'mysql') {
            $plan = DB::select('EXPLAIN SELECT id FROM students WHERE association_id = ? AND status = ?', [$association->id, 'active']);
            $this->assertNotEmpty($plan[0]->possible_keys);
            fwrite(STDERR, 'EXPLAIN students: type='.$plan[0]->type.' key='.($plan[0]->key ?? 'none')."\n");
        }
    }

    private function queuedEmail(): Notification
    {
        $association = Association::query()->create(['legal_name' => 'Notificações', 'trade_name' => 'Notificações']);
        $user = User::factory()->create(['association_id' => $association->id]);
        foreach (['email', 'internal'] as $channel) {
            NotificationTemplate::query()->create(['event_type' => 'championship.opened', 'channel' => $channel, 'subject_template' => 'Aviso', 'body_template' => 'Mensagem privada']);
        }
        app(NotificationService::class)->publish($association, 'championship.opened', 'hardening', [], [$user]);

        return Notification::query()->where('channel', 'email')->firstOrFail();
    }

    public function test_personal_notification_is_redacted_after_guardian_relationship_ends(): void
    {
        $email = $this->queuedEmail();
        $user = $email->user;
        $guardian = Person::query()->create(['full_name' => 'Responsável sintético']);
        $person = Person::query()->create(['full_name' => 'Aluno sintético']);
        $user->update(['person_id' => $guardian->id]);
        $dojo = Dojo::query()->create(['association_id' => $user->association_id, 'code' => 'REVOKE', 'name' => 'Dojo']);
        $student = Student::query()->create(['association_id' => $user->association_id, 'person_id' => $person->id, 'registration_number' => 'REVOKE', 'entry_date' => today(), 'primary_dojo_id' => $dojo->id]);
        $link = StudentGuardian::query()->create(['student_id' => $student->id, 'guardian_person_id' => $guardian->id, 'relationship_type' => 'mother', 'can_receive_notifications' => true, 'start_date' => today()->subYear()]);
        $email->event->update(['context' => ['student_id' => $student->id]]);
        $internal = Notification::query()->where('notification_event_id', $email->notification_event_id)->where('channel', 'internal')->firstOrFail();
        $internal->update(['event_type' => 'charge.overdue', 'body' => 'Detalhe pessoal protegido']);
        $this->actingAs($user)->get('/notificacoes')->assertSee('Detalhe pessoal protegido');
        $link->update(['end_date' => today()->subDay()]);
        $this->get('/notificacoes')->assertDontSee('Detalhe pessoal protegido')->assertSee('Aviso anterior');
        $this->assertSame('Detalhe pessoal protegido', $internal->fresh()->body);
    }

    public function test_production_preflight_fails_closed_and_outputs_no_configuration_values(): void
    {
        config(['app.debug' => true, 'app.key' => 'SYNTHETIC_SECRET', 'mail.default' => 'log']);
        $checks = app(ProductionReadiness::class)->checks();
        $this->assertFalse($checks['debug_disabled']);
        $this->assertFalse($checks['application_key']);
        $this->assertFalse($checks['smtp_selected']);
        $this->assertStringNotContainsString('SYNTHETIC_SECRET', json_encode($checks));
        $this->artisan('sigam:production:check', ['--json' => true])->assertExitCode(1);
    }
}
