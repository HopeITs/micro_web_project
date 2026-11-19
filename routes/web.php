<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\ProjectController;
use App\Http\Middleware\RequireCurrentAccount;
use App\Http\Middleware\RestrictToPermittedCities;
use App\Http\Middleware\SetLocale;
use Illuminate\Support\Facades\Route;

/*
 * Rebuild (M2.6): the shell of the new site. Modules are added here as they are ported in M4;
 * routes/legacy-web.php keeps the previous routes as a porting reference and is not loaded.
 */
Route::get('/', fn () => redirect()->route(auth()->check() ? 'home' : 'auth.login', ['locale' => 'ar']));

Route::prefix('{locale}')->where(['locale' => 'en|ar'])->middleware(SetLocale::class)->group(function () {
    Route::middleware('guest')->group(function () {
        Route::get('/login', [AuthController::class, 'show'])->name('auth.login');
        Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login')->name('auth.attempt');
    });
    Route::middleware('auth')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::middleware([RequireCurrentAccount::class, RestrictToPermittedCities::class])->group(function () {
            Route::get('/', HomeController::class)->name('home');
            Route::get('/projects', [ProjectController::class, 'index'])->name('projects.index');
            Route::get('/projects/{project}', [ProjectController::class, 'show'])->whereNumber('project')->name('projects.show');
            Route::view('/design', 'design')->name('design');
        });
    });
});
