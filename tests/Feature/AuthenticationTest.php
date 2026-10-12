<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_page_is_accessible_and_uses_plain_portuguese(): void
    {
        $this->get('/entrar')
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertSee('Bem-vindo ao JUDOMA')
            ->assertDontSee('SIGAM')
            ->assertSee('E-mail');
    }

    public function test_active_user_can_log_in_and_event_is_audited(): void
    {
        $user = User::factory()->create(['email' => 'gestor@example.com']);

        $this->post('/entrar', [
            'email' => ' GESTOR@example.com ',
            'password' => 'password',
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh()->last_login_at);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $user->id,
            'event_type' => 'security.login_succeeded',
        ]);
    }

    public function test_inactive_user_cannot_log_in(): void
    {
        User::factory()->create([
            'email' => 'inativo@example.com',
            'status' => 'inactive',
        ]);

        $this->post('/entrar', [
            'email' => 'inativo@example.com',
            'password' => 'password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertDatabaseHas('audit_logs', ['event_type' => 'security.login_failed']);
    }

    public function test_logout_invalidates_authenticated_access(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/sair')
            ->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertSame(1, AuditLog::query()->where('event_type', 'security.logout')->count());
    }
}
