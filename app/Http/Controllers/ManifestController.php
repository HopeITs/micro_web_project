<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

/**
 * F1 (docs/planning/ux-borrowed-features-2026-11.md): the web app manifest, one per language, so the
 * installed app opens in the language and reading direction it was installed from. Public: browsers
 * fetch it without cookies, and it carries no account data.
 */
class ManifestController
{
    public function __invoke(string $locale): JsonResponse
    {
        $arabic = $locale === 'ar';
        $base = rtrim(url('/'), '/');
        $path = parse_url($base, PHP_URL_PATH) ?: '';

        return response()->json([
            'id' => $path.'/',
            'name' => __('hc.pwa_name'),
            'short_name' => __('hc.brand'),
            'description' => __('hc.pwa_description'),
            'lang' => $locale,
            'dir' => $arabic ? 'rtl' : 'ltr',
            'start_url' => $path.'/'.$locale.'?source=pwa',
            'scope' => $path.'/',
            'display' => 'standalone',
            'display_override' => ['window-controls-overlay', 'standalone'],
            'orientation' => 'any',
            'background_color' => '#ffffff',
            'theme_color' => '#be1f24',
            'categories' => ['business', 'productivity'],
            'icons' => [
                ['src' => $path.'/icons/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => $path.'/icons/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => $path.'/icons/icon-maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
                ['src' => $path.'/icons/icon.svg', 'sizes' => 'any', 'type' => 'image/svg+xml', 'purpose' => 'any'],
            ],
            'shortcuts' => [
                ['name' => __('hc.nav_home'), 'url' => $path.'/'.$locale, 'icons' => [['src' => $path.'/icons/icon-192.png', 'sizes' => '192x192']]],
                ['name' => __('hc.nav_people'), 'url' => $path.'/'.$locale.'/people', 'icons' => [['src' => $path.'/icons/icon-192.png', 'sizes' => '192x192']]],
            ],
        ], 200, ['Content-Type' => 'application/manifest+json', 'Cache-Control' => 'public, max-age=3600'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
