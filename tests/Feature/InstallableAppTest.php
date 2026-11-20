<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * F1 installable app (docs/planning/ux-borrowed-features-2026-11.md), as the people who use it:
 * a field worker installs from the sign-in page on a phone, a data-entry user from the account menu,
 * and nobody's data is ever kept by the service worker.
 */
class InstallableAppTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_manifest_opens_the_app_in_the_language_it_was_installed_from(): void
    {
        $arabic = $this->get('/ar/manifest.webmanifest')->assertOk()->assertHeader('Content-Type', 'application/manifest+json');
        $arabic->assertJson(['lang' => 'ar', 'dir' => 'rtl', 'start_url' => '/ar?source=pwa', 'scope' => '/', 'display' => 'standalone', 'theme_color' => '#be1f24'])
            ->assertJsonPath('name', __('hc.pwa_name', [], 'ar'));

        $this->get('/en/manifest.webmanifest')->assertOk()
            ->assertJson(['lang' => 'en', 'dir' => 'ltr', 'start_url' => '/en?source=pwa', 'name' => 'Micro Projects · Hope Center']);
    }

    public function test_every_manifest_icon_exists_with_a_192_512_and_maskable_size(): void
    {
        $icons = collect($this->get('/ar/manifest.webmanifest')->json('icons'));

        $this->assertTrue($icons->contains(fn ($icon) => $icon['sizes'] === '192x192'));
        $this->assertTrue($icons->contains(fn ($icon) => $icon['sizes'] === '512x512' && $icon['purpose'] === 'any'));
        $this->assertTrue($icons->contains(fn ($icon) => $icon['purpose'] === 'maskable'));
        foreach ($icons as $icon) {
            $this->assertFileExists(public_path(ltrim($icon['src'], '/')), $icon['src']);
        }
        $this->assertFileExists(public_path('icons/apple-touch-icon.png'));
    }

    public function test_the_manifest_carries_no_account_data_and_needs_no_sign_in(): void
    {
        $body = $this->get('/ar/manifest.webmanifest')->assertOk()->getContent();

        $this->assertStringNotContainsString('username', $body);
        $this->assertStringNotContainsString('csrf', strtolower($body));
    }

    public function test_a_field_worker_can_install_from_the_sign_in_page(): void
    {
        $this->get('/ar/login')->assertOk()
            ->assertSee('rel="manifest" href="'.url('/ar/manifest.webmanifest').'"', false)
            ->assertSee('name="mp-service-worker" content="'.url('/sw.js').'"', false)
            ->assertSee('data-pwa-install hidden', false)
            ->assertSee(__('hc.pwa_install', [], 'ar'))
            ->assertSee(__('hc.pwa_ios_title', [], 'ar'));
    }

    public function test_a_signed_in_user_finds_install_in_the_account_menu_in_both_languages(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->post('/ar/login', ['username' => 'role.data-entry', 'password' => 'password'])->assertRedirect('/ar');

        $this->get('/ar')->assertOk()->assertSee('hc-install-item', false)->assertSee('تثبيت التطبيق')
            ->assertSee('/ar/manifest.webmanifest', false);
        $this->get('/en')->assertOk()->assertSee('Install the app')->assertSee('/en/manifest.webmanifest', false);
    }

    public function test_the_service_worker_keeps_only_shell_files_and_the_offline_page(): void
    {
        $worker = file_get_contents(public_path('sw.js'));

        // Pages go to the network and fall back to the offline page; nothing else is answered from the cache.
        $this->assertStringContainsString("request.mode === 'navigate'", $worker);
        $this->assertStringContainsString("['build/', 'assets/fonts/', 'assets/brand/', 'icons/']", $worker);
        $this->assertStringContainsString("if (request.method !== 'GET') return;", $worker);
        $this->assertSame(1, substr_count($worker, 'cache.put('), 'Only shell files may be written to the cache.');

        $offline = file_get_contents(public_path('offline.html'));
        $this->assertStringContainsString('لا يوجد اتصال بالإنترنت', $offline);
        $this->assertStringContainsString('You are offline', $offline);
        $this->assertDoesNotMatchRegularExpression('/(src|href)="(?!#)[^"]+"/', $offline, 'The offline page is served at any URL, so it must not use relative links.');
    }
}
