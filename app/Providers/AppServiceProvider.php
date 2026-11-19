<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One city context per request (or per queued job).
        $this->app->scoped(\App\Support\CityContext::class);
    }

    public function boot(): void
    {
        // Livewire update requests re-run these, so components see the same locale and city limits as the page.
        \Livewire\Livewire::addPersistentMiddleware([
            \App\Http\Middleware\SetLocale::class,
            \App\Http\Middleware\RequireCurrentAccount::class,
            \App\Http\Middleware\RestrictToPermittedCities::class,
        ]);

        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(30)->by('login-ip:'.hash('sha256', (string) $request->ip())),
            Limit::perMinute(6)->by('login-identity:'.hash('sha256', json_encode([$request->input('username'), $request->ip()]))),
        ]);
    }
}
