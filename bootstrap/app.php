<?php

use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(SecurityHeaders::class);
        // Only the host of APP_URL is answered (not in local and testing), so a forged Host header never reaches a link such as the password reset one.
        $middleware->trustHosts(at: fn () => ['^'.preg_quote((string) parse_url((string) config('app.url'), PHP_URL_HOST)).'$'], subdomains: false);
        $middleware->trimStrings(except: ['username', 'password']);
        $middleware->trimStrings(except: [fn (Request $request) => $request->is('en/people', 'en/people/*', 'ar/people', 'ar/people/*', 'en/applications', 'en/applications/*', 'ar/applications', 'ar/applications/*')]);
        $middleware->convertEmptyStringsToNull(except: [fn (Request $request) => $request->is('en/people', 'en/people/*', 'ar/people', 'ar/people/*', 'en/applications', 'en/applications/*', 'ar/applications', 'ar/applications/*')]);
        $middleware->redirectGuestsTo(fn (Request $request) => route('auth.login', ['locale' => in_array($request->route('locale'), ['en', 'ar'], true) ? $request->route('locale') : 'ar']));
        $middleware->redirectUsersTo(fn (Request $request) => route('home', ['locale' => in_array($request->route('locale'), ['en', 'ar'], true) ? $request->route('locale') : 'ar']));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
