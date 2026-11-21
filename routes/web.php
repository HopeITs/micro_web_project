<?php

use App\Http\Controllers\AccountAdminController;
use App\Http\Controllers\AccountEmailController;
use App\Http\Controllers\AccountPasswordController;
use App\Http\Controllers\ApplicationController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ChecklistController;
use App\Http\Controllers\FinanceController;
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
        // Forgot password: a single-use link is e-mailed to the institution address of the account.
        Route::get('/forgot-password', [AccountPasswordController::class, 'forgot'])->name('auth.forgot');
        Route::post('/forgot-password', [AccountPasswordController::class, 'request'])->middleware('throttle:login')->name('auth.forgot.request');
        Route::get('/reset-password/{token}', [AccountPasswordController::class, 'reset'])->where('token', '[A-Za-z0-9]{40}')->name('auth.reset');
        Route::post('/reset-password/{token}', [AccountPasswordController::class, 'resetStore'])->where('token', '[A-Za-z0-9]{40}')->middleware('throttle:login')->name('auth.reset.store');
    });
    Route::middleware('auth')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::middleware([RequireCurrentAccount::class, RestrictToPermittedCities::class])->group(function () {
            Route::get('/account/password', [AccountPasswordController::class, 'edit'])->name('account.password');
            Route::post('/account/password', [AccountPasswordController::class, 'update'])->block(10, 10)->name('account.password.update');
            // An account without an e-mail adds its institution e-mail (proven by an e-mailed code) before anything else.
            Route::get('/account/email', [AccountEmailController::class, 'edit'])->name('account.email');
            Route::post('/account/email', [AccountEmailController::class, 'send'])->middleware('throttle:login')->name('account.email.send');
            Route::post('/account/email/confirm', [AccountEmailController::class, 'confirm'])->middleware('throttle:login')->name('account.email.confirm');
            Route::get('/', HomeController::class)->name('home');
            Route::get('/projects', [ProjectController::class, 'index'])->name('projects.index');
            Route::get('/projects/{project}', [ProjectController::class, 'show'])->whereNumber('project')->name('projects.show');
            Route::view('/design', 'design')->name('design');

            // M4.5 application form (ApplicationService rechecks applications.manage + people.manage per city).
            Route::get('/applications/create', [ApplicationController::class, 'create'])->name('applications.create');
            Route::get('/applications/{project}/edit', [ApplicationController::class, 'edit'])->whereNumber('project')->name('applications.edit');
            Route::get('/applications/{project}/annex', [ApplicationController::class, 'annex'])->whereNumber('project')->name('applications.annex');
            // M4.7 checklist and assessment (ChecklistService rechecks assessments.view / assessments.manage).
            Route::get('/applications/{project}/checklist', [ChecklistController::class, 'show'])->whereNumber('project')->name('checklists.show');
            Route::get('/applications/{project}/checklist/print', [ChecklistController::class, 'print'])->whereNumber('project')->name('checklists.print');

            // M4.9 finance (FinanceService / PaymentScheduleService recheck applications.view + finance.view, finance.manage, finance.export per city).
            Route::get('/applications/{project}/finance', [FinanceController::class, 'show'])->whereNumber('project')->name('finance.show');
            Route::get('/payments', [FinanceController::class, 'payments'])->name('finance.payments');
            Route::get('/payments/export', [FinanceController::class, 'export'])->middleware('throttle:10,1')->name('finance.payments.export');

            // M4.12 visits (VisitService / MonitoringVisitService / VisitScheduleService / VisitResultsService / VisitorStatisticsService recheck the capabilities per city).
            Route::get('/applications/{project}/visits', [\App\Http\Controllers\VisitController::class, 'hub'])->whereNumber('project')->name('visits.hub');
            Route::get('/applications/{project}/visits/{kind}', [\App\Http\Controllers\VisitController::class, 'show'])->whereNumber('project')->where('kind', '[OVR]-(op|cl)')->name('visits.show');
            Route::get('/applications/{project}/me-visits/new/{person}', [\App\Http\Controllers\VisitController::class, 'meCreate'])->whereNumber('project')->whereNumber('person')->name('visits.me.create');
            Route::get('/applications/{project}/me-visits/{visit}', [\App\Http\Controllers\VisitController::class, 'meShow'])->whereNumber('project')->whereNumber('visit')->name('visits.me.show');
            Route::get('/visits/schedule', [\App\Http\Controllers\VisitController::class, 'schedule'])->name('visits.schedule');
            Route::get('/visits/schedule/export', [\App\Http\Controllers\VisitController::class, 'scheduleExport'])->middleware('throttle:10,1')->name('visits.schedule.export');
            Route::get('/visits/results', [\App\Http\Controllers\VisitController::class, 'results'])->name('visits.results');
            Route::get('/visits/results/export', [\App\Http\Controllers\VisitController::class, 'resultsExport'])->middleware('throttle:10,1')->name('visits.results.export');
            Route::get('/visits/statistics', [\App\Http\Controllers\VisitController::class, 'statistics'])->name('visits.statistics');
            Route::get('/visits/statistics/export', [\App\Http\Controllers\VisitController::class, 'statisticsExport'])->middleware('throttle:10,1')->name('visits.statistics.export');

            // M4.3 people and families (PeopleService rechecks people.view / people.manage per city on every action).
            Route::get('/people', [PeopleController::class, 'index'])->name('people.index');
            Route::get('/people/create', [PeopleController::class, 'create'])->name('people.create');
            Route::get('/national-check', [PeopleController::class, 'national'])->name('people.national');
            Route::get('/people/{person}', [PeopleController::class, 'show'])->whereNumber('person')->name('people.show');
            Route::get('/people/{person}/edit', [PeopleController::class, 'edit'])->whereNumber('person')->name('people.edit');

            // M4.11 attachments (AttachmentService rechecks the owner, attachments.download and the category grant on every file).
            Route::get('/attachments', [\App\Http\Controllers\AttachmentFileController::class, 'index'])->name('attachments.index');
            Route::get('/attachments/{attachment}', [\App\Http\Controllers\AttachmentFileController::class, 'show'])->whereNumber('attachment')->name('attachments.file');

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
