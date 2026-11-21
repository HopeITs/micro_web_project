<?php

use App\Http\Controllers\AccountAdminController;
use App\Http\Controllers\AccountPasswordController;
use App\Http\Controllers\ApplicationController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LookupController;
use App\Http\Controllers\ManifestController;
use App\Http\Controllers\PeopleController;
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
    // F1 installable app: one public manifest per language (no account data in it).
    Route::get('/manifest.webmanifest', ManifestController::class)->name('pwa.manifest');

    Route::middleware('guest')->group(function () {
        Route::get('/login', [AuthController::class, 'show'])->name('auth.login');
        Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login')->name('auth.attempt');
    });
    Route::middleware('auth')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::middleware([RequireCurrentAccount::class, RestrictToPermittedCities::class])->group(function () {
            Route::get('/account/password', [AccountPasswordController::class, 'edit'])->name('account.password');
            Route::post('/account/password', [AccountPasswordController::class, 'update'])->block(10, 10)->name('account.password.update');
            Route::get('/', HomeController::class)->name('home');
            Route::get('/projects', [ProjectController::class, 'index'])->name('projects.index');
            Route::get('/projects/{project}', [ProjectController::class, 'show'])->whereNumber('project')->name('projects.show');
            Route::view('/design', 'design')->name('design');

            // M4.5 application form (ApplicationService rechecks applications.manage + people.manage per city).
            Route::get('/applications/create', [ApplicationController::class, 'create'])->name('applications.create');
            Route::get('/applications/{project}/edit', [ApplicationController::class, 'edit'])->whereNumber('project')->name('applications.edit');

            // M4.3 people and families (PeopleService rechecks people.view / people.manage per city on every action).
            Route::get('/people', [PeopleController::class, 'index'])->name('people.index');
            Route::get('/people/create', [PeopleController::class, 'create'])->name('people.create');
            Route::get('/national-check', [PeopleController::class, 'national'])->name('people.national');
            Route::get('/people/{person}', [PeopleController::class, 'show'])->whereNumber('person')->name('people.show');
            Route::get('/people/{person}/edit', [PeopleController::class, 'edit'])->whereNumber('person')->name('people.edit');

            // M4.2 lists (every action rechecks lookups.manage per city / in all cities in LookupAdminService).
            Route::get('/lookups', [LookupController::class, 'index'])->name('lookups.index');
            Route::get('/lookups/{list}', [LookupController::class, 'show'])->where('list', '[a-z_]{2,40}')->name('lookups.show');
            Route::get('/lookups/{list}/export', [LookupController::class, 'export'])->where('list', '[a-z_]{2,40}')->name('lookups.export');

            // M4.1 accounts, roles and task responsibilities (every action rechecks accounts.manage per city in AccountAdminService).
            Route::get('/admin/accounts', [AccountAdminController::class, 'index'])->name('admin.accounts.index');
            Route::get('/admin/accounts/new', [AccountAdminController::class, 'create'])->name('admin.accounts.new');
            Route::post('/admin/accounts', [AccountAdminController::class, 'store'])->block(10, 10)->name('admin.accounts.store');
            Route::post('/admin/accounts/plain', [AccountAdminController::class, 'storePlain'])->block(10, 10)->name('admin.accounts.store-plain');
            Route::get('/admin/staff', [AccountAdminController::class, 'staff'])->name('admin.staff');
            Route::get('/admin/tasks', [AccountAdminController::class, 'tasks'])->name('admin.tasks');
            Route::post('/admin/tasks', [AccountAdminController::class, 'saveTasks'])->block(10, 10)->name('admin.tasks.save');
            // M4.19 audit log, read only (AuditLogService rechecks accounts.manage per city).
            Route::get('/admin/audit', [AuditLogController::class, 'index'])->name('admin.audit');
            Route::get('/admin/audit/export', [AuditLogController::class, 'export'])->middleware('throttle:10,1')->name('admin.audit.export');
            Route::get('/admin/audit/desktop', [AuditLogController::class, 'desktop'])->name('admin.audit.desktop');
            Route::get('/admin/audit/desktop/export', [AuditLogController::class, 'desktopExport'])->middleware('throttle:10,1')->name('admin.audit.desktop.export');
            Route::prefix('/admin/accounts/{account}')->where(['account' => '[1-9][0-9]{0,18}', 'link' => '[1-9][0-9]{0,18}'])->group(function () {
                Route::get('/', [AccountAdminController::class, 'show'])->name('admin.accounts.show');
                Route::post('/capabilities', [AccountAdminController::class, 'capabilities'])->block(10, 10)->name('admin.accounts.capabilities');
                Route::post('/role', [AccountAdminController::class, 'role'])->block(10, 10)->name('admin.accounts.role');
                Route::post('/role/revoke', [AccountAdminController::class, 'roleRevoke'])->block(10, 10)->name('admin.accounts.role-revoke');
                Route::post('/email', [AccountAdminController::class, 'email'])->block(10, 10)->name('admin.accounts.email');
                Route::post('/links', [AccountAdminController::class, 'link'])->block(10, 10)->name('admin.accounts.link');
                Route::post('/links/{link}', [AccountAdminController::class, 'linkState'])->block(10, 10)->name('admin.accounts.link-state');
                Route::post('/disable', [AccountAdminController::class, 'disable'])->block(10, 10)->name('admin.accounts.disable');
            });
        });
    });
});
