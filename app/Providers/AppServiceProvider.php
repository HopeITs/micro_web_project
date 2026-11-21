<?php

namespace App\Providers;

use App\Http\Middleware\RequireCurrentAccount;
use App\Http\Middleware\RestrictToPermittedCities;
use App\Http\Middleware\SetLocale;
use App\Support\CityContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One city context per request (or per queued job).
        $this->app->scoped(CityContext::class);
    }

    public function boot(): void
    {
        // Livewire update requests re-run these, so components see the same locale and city limits as the page.
        Livewire::addPersistentMiddleware([
            SetLocale::class,
            RequireCurrentAccount::class,
            RestrictToPermittedCities::class,
        ]);

        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(30)->by('login-ip:'.hash('sha256', (string) $request->ip())),
            Limit::perMinute(6)->by('login-identity:'.hash('sha256', json_encode([mb_strtolower(trim((string) $request->input('login', $request->input('email')))), $request->ip()]))),
        ]);
    }
}
