<?php

namespace Tests\Feature;

use App\Mail\PasswordChangedMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AccountPasswordTest extends TestCase
{
    use RefreshDatabase;

    public function test_account_page_is_linked_and_shows_the_form(): void
    {
        $user = User::factory()->create(['name' => 'Ana Conta']);

        $this->actingAs($user)->get(route('account.show'))->assertOk()
            ->assertSee('class="account-name" href="'.route('account.show').'"', false)->assertDontSee('← Meus dados')
            ->assertSee('Trocar senha')->assertSee('Senha atual')->assertSee($user->email);
    }

    public function test_user_changes_own_password_and_other_sessions_end(): void
    {
        Mail::fake();
        $user = User::factory()->create();
        $user->forceFill(['remember_token' => 'antigo'])->save();
        DB::table('sessions')->insert(['id' => 'outro-aparelho', 'user_id' => $user->id, 'ip_address' => '10.0.0.1', 'user_agent' => 'x', 'payload' => '', 'last_activity' => time()]);

        $this->actingAs($user)->put(route('account.password.update'), [
            'current_password' => 'password',
            'password' => 'NovaSenha2026x',
            'password_confirmation' => 'NovaSenha2026x',
        ])->assertRedirect(route('account.show'))->assertSessionHas('status');

        $user->refresh();
        $this->assertTrue(Hash::check('NovaSenha2026x', $user->password));
        $this->assertNotSame('antigo', $user->remember_token);
        $this->assertDatabaseMissing('sessions', ['id' => 'outro-aparelho']);
        $this->assertDatabaseHas('audit_logs', ['user_id' => $user->id, 'event_type' => 'security.password_changed']);
        $this->assertAuthenticatedAs($user);
        Mail::assertSent(PasswordChangedMail::class, fn (PasswordChangedMail $mail) => $mail->hasTo($user->email));
    }

    public function test_wrong_current_password_or_weak_new_password_is_rejected(): void
    {
        Mail::fake();
        $user = User::factory()->create();

        $this->actingAs($user)->from(route('account.show'))->put(route('account.password.update'), [
            'current_password' => 'errada',
            'password' => 'NovaSenha2026x',
            'password_confirmation' => 'NovaSenha2026x',
        ])->assertRedirect(route('account.show'))->assertSessionHasErrors('current_password');
        $this->assertDatabaseHas('audit_logs', ['user_id' => $user->id, 'event_type' => 'security.password_change_failed']);

        $this->actingAs($user)->put(route('account.password.update'), [
            'current_password' => 'password',
            'password' => 'curta1',
            'password_confirmation' => 'outra',
        ])->assertSessionHasErrors('password');

        $this->assertTrue(Hash::check('password', $user->fresh()->password));
        Mail::assertNothingSent();
    }

    public function test_guest_cannot_open_account_page(): void
    {
        $this->get(route('account.show'))->assertRedirect(route('login'));
        $this->put(route('account.password.update'), [])->assertRedirect(route('login'));
    }
}
