<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Http\Middleware\TrustHosts;
use Tests\TestCase;

/** The protections of a public address (owner instruction, 26 Nov 2026). */
final class SecurityHardeningTest extends TestCase
{
    public function test_a_visitor_gets_the_protective_headers_and_search_engines_are_turned_away(): void
    {
        $response = $this->get('/ar/login')->assertOk();

        $this->assertSame('noindex, nofollow, noarchive', $response->headers->get('X-Robots-Tag'));
        $this->assertSame('same-origin', $response->headers->get('Cross-Origin-Opener-Policy'));
        $this->assertSame('same-origin', $response->headers->get('Cross-Origin-Resource-Policy'));
        $this->assertSame('none', $response->headers->get('X-Permitted-Cross-Domain-Policies'));
        $this->assertSame('DENY', $response->headers->get('X-Frame-Options'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        // The visit forms may read the GPS point; camera and microphone stay closed, and no other site gets any of them.
        $this->assertSame('camera=(), microphone=(), geolocation=(self)', $response->headers->get('Permissions-Policy'));
        $this->assertStringContainsString("Disallow: /", (string) file_get_contents(public_path('robots.txt')));
    }

    public function test_only_the_host_of_the_application_address_is_trusted(): void
    {
        config(['app.url' => 'https://mp.example.test']);

        $patterns = array_filter(app(TrustHosts::class)->hosts());
        $trusted = fn (string $host): bool => collect($patterns)->contains(fn (string $p) => preg_match('{'.$p.'}i', $host) === 1);

        $this->assertTrue($trusted('mp.example.test'));
        $this->assertFalse($trusted('evil.example.com'));
        $this->assertFalse($trusted('sub.mp.example.test'));
    }
}
