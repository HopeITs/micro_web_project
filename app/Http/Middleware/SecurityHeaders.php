<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        if ($request->is('en/people', 'en/people/*', 'ar/people', 'ar/people/*', 'en/applications', 'en/applications/*', 'ar/applications', 'ar/applications/*')) {
            $response->headers->set('Cache-Control', 'no-store, private');
            $response->headers->set('Referrer-Policy', 'no-referrer');
        }
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        // M4.20: the review page of a redrawn paper shows the printed paper and its original side by side, so the print pages of the
        // papers and the original (and only these) may be framed by this same site. Every other page stays unframable.
        // The identity reader shows a PDF scan (an attachment file) beside the proposed values the same way.
        $pdfFrame = $request->is('en/papers/original', 'ar/papers/original', 'en/attachments/*', 'ar/attachments/*');
        $sameSiteFrame = $pdfFrame || $request->is('en/applications/*/print', 'ar/applications/*/print', 'en/applications/*/papers/*', 'ar/applications/*/papers/*',
            'en/applications/*/finance-papers/*', 'ar/applications/*/finance-papers/*', 'en/applications/*/purchase-papers/*', 'ar/applications/*/purchase-papers/*');
        $response->headers->set('X-Frame-Options', $sameSiteFrame ? 'SAMEORIGIN' : 'DENY');
        if (! $response->headers->has('Referrer-Policy')) {
            $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        }
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        // These restrictions work with Livewire/Alpine without allowing frames,
        // plugins or cross-origin form submissions. A full script policy needs
        // a separate nonce/CSP-compatible Alpine design before deployment.
        // The original paper is a PDF: Chrome's viewer needs object-src to stay open for that one response.
        $response->headers->set('Content-Security-Policy', match (true) {
            $pdfFrame => "base-uri 'self'; frame-ancestors 'self'; form-action 'self'",
            $sameSiteFrame => "base-uri 'self'; object-src 'none'; frame-ancestors 'self'; form-action 'self'",
            default => "base-uri 'self'; object-src 'none'; frame-ancestors 'none'; form-action 'self'",
        });
        if ($request->isSecure() && app()->environment('production')) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000');
        }

        return $response;
    }
}
