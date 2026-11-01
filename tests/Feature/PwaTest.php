<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Pwa\PwaVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PwaTest extends TestCase
{
    use RefreshDatabase;

    public function test_manifest_icons_and_static_files_exist(): void
    {
        $manifest = json_decode((string) file_get_contents(public_path('manifest.json')), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('JUDOMA', $manifest['name']);
        $this->assertSame('standalone', $manifest['display']);
        $this->assertSame('./dashboard', $manifest['start_url']);
        $this->assertContains('maskable', array_column($manifest['icons'], 'purpose'));
        foreach ($manifest['icons'] as $icon) {
            $this->assertFileExists(public_path($icon['src']));
            [$width, $height] = getimagesize(public_path($icon['src']));
            $this->assertSame($icon['sizes'], $width.'x'.$height);
        }
        foreach (array_merge(PwaVersion::FILES, ['icons/apple-touch-icon.png', 'icons/favicon-32.png']) as $file) {
            $this->assertFileExists(public_path($file));
        }
        $this->assertMatchesRegularExpression('/^[0-9a-f]{12}$/', PwaVersion::current());
    }

    public function test_service_worker_never_caches_pages_with_data(): void
    {
        $worker = (string) file_get_contents(public_path('sw.js'));

        // Navegação sempre pela rede (cai na página "Sem conexão" só sem internet); só CSS, JS e ícones são guardados.
        $this->assertStringContainsString("request.mode === 'navigate'", $worker);
        $this->assertStringContainsString('fetch(request).catch(() => caches.match(OFFLINE))', $worker);
        $this->assertStringContainsString('const STATIC = /\\/(css|js|icons)\\/[^?]+\\.(css|js|png)$/;', $worker);
        $this->assertStringContainsString("request.method !== 'GET'", $worker);
    }

    public function test_layout_declares_the_app_and_csp_allows_it(): void
    {
        $response = $this->get(route('login'))->assertOk()
            ->assertSee('<link rel="manifest" href="'.asset('manifest.json').'">', false)
            ->assertSee('<meta name="judoma-sw" content="'.asset('sw.js').'?v='.PwaVersion::current().'">', false)
            ->assertSee('apple-touch-icon.png')
            ->assertDontSee('data-pwa-banner', false);

        $csp = $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("manifest-src 'self'", $csp);
        $this->assertStringContainsString("worker-src 'self'", $csp);
        $this->assertStringContainsString("script-src 'self'", $csp);
    }

    public function test_logged_user_sees_install_banner_and_install_page(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('account.show'))->assertOk()
            ->assertSee('data-pwa-banner hidden', false)
            ->assertSee('href="'.route('app.install').'"', false)
            ->assertSee('data-pwa-logout', false);

        $this->actingAs($user)->get(route('app.install'))->assertOk()
            ->assertSee('Instalar o app')->assertSee('Adicionar à Tela de Início')->assertSee('Instalar app')
            ->assertSee('data-pwa-install-page hidden', false)->assertSee('← Início');
    }

    public function test_guest_cannot_open_install_page(): void
    {
        $this->get(route('app.install'))->assertRedirect(route('login'));
    }
}
