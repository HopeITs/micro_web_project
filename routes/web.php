<?php

use App\Http\Controllers\AccountAdminController;
use App\Http\Controllers\AccountEmailController;
use App\Http\Controllers\AccountPasswordController;
use App\Http\Controllers\ApplicationController;
use App\Http\Controllers\AttachmentFileController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ChecklistController;
use App\Http\Controllers\ChecklistListController;
use App\Http\Controllers\ExecutiveFileController;
use App\Http\Controllers\FinanceController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\KoboController;
use App\Http\Controllers\LookupController;
use App\Http\Controllers\ManifestController;
use App\Http\Controllers\MeetingPaperController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PaperLibraryController;
use App\Http\Controllers\PaperPrintController;
use App\Http\Controllers\PaymentExportController;
use App\Http\Controllers\PeopleController;
use App\Http\Controllers\PeopleExportController;
use App\Http\Controllers\ProfessionMeetingController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProjectExportController;
use App\Http\Controllers\PurchasingController;
use App\Http\Controllers\ReportBuilderExportController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\SpecialCaseController;
use App\Http\Controllers\StatisticsController;
use App\Http\Controllers\TaskBoardController;
use App\Http\Controllers\VisitController;
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
            // Smart-search phase 2: Excel/CSV of the list as shown; the single-use token carries the search server-side (DatasetExport rechecks reports.export per city).
            Route::get('/projects/export/{token}', ProjectExportController::class)->where('token', '[A-Za-z0-9]{40}')->middleware('throttle:10,1')->name('projects.export');
            Route::view('/design', 'design')->name('design');

            // M4.5 application form (ApplicationService rechecks applications.manage + people.manage per city).
            Route::get('/applications/create', [ApplicationController::class, 'create'])->name('applications.create');
            Route::get('/applications/{project}/edit', [ApplicationController::class, 'edit'])->whereNumber('project')->name('applications.edit');
            Route::get('/applications/{project}/annex', [ApplicationController::class, 'annex'])->whereNumber('project')->name('applications.annex');
            // M4.7 checklist and assessment (ChecklistService rechecks assessments.view / assessments.manage); the general list is the sidebar page.
            Route::get('/checklists', [ChecklistListController::class, 'index'])->name('checklists.index');
            Route::get('/applications/{project}/checklist', [ChecklistController::class, 'show'])->whereNumber('project')->name('checklists.show');
            Route::get('/applications/{project}/checklist/print', [ChecklistController::class, 'print'])->whereNumber('project')->name('checklists.print');

            // M4.9 finance (FinanceService / PaymentScheduleService recheck applications.view + finance.view, finance.manage, finance.export per city).
            Route::get('/applications/{project}/finance', [FinanceController::class, 'show'])->whereNumber('project')->name('finance.show');
            Route::get('/payments', [FinanceController::class, 'payments'])->name('finance.payments');
            Route::get('/payments/export', [FinanceController::class, 'export'])->middleware('throttle:10,1')->name('finance.payments.export');
            // Smart-search phase 2: the payment schedule as shown (columns, filters, search, sort) from the list's single-use token; DatasetExport rechecks finance.export per city.
            Route::get('/payments/export/{token}', PaymentExportController::class)->where('token', '[A-Za-z0-9]{40}')->middleware('throttle:10,1')->name('payments.export');

            // Smart-search phase 3: the report builder (ReportBuilder rechecks the datasets the account may read per city); its Excel/CSV
            // comes from a single-use token and ReportExport rechecks reports.export (finance.export for instalments) per city.
            Route::view('/reports', 'reports.builder')->name('reports.index');
            Route::get('/reports/export/{token}', ReportBuilderExportController::class)->where('token', '[A-Za-z0-9]{40}')->middleware('throttle:10,1')->name('reports.export');
            // M4.14 Kobo, read and review only (KoboReadService / KoboResultsService recheck visits.manage and reports.export per city).
            Route::get('/kobo', [KoboController::class, 'index'])->name('kobo.index');
            // M4.14b the Kobo visit forms on the web (KoboVisitService rechecks visits.manage / me_visits.manage and the delete capabilities per city).
            Route::get('/kobo/visits/new/{project}', [KoboController::class, 'create'])->whereNumber('project')->name('kobo.visit.create');
            Route::get('/kobo/visits/{submission}', [KoboController::class, 'show'])->whereNumber('submission')->name('kobo.visit.show');
            Route::get('/kobo/export', [KoboController::class, 'export'])->middleware('throttle:10,1')->name('kobo.export');

            // M4.12 visits (VisitService / MonitoringVisitService / VisitScheduleService / VisitResultsService / VisitorStatisticsService recheck the capabilities per city).
            Route::get('/applications/{project}/visits', [VisitController::class, 'hub'])->whereNumber('project')->name('visits.hub');
            Route::get('/applications/{project}/visits/{kind}', [VisitController::class, 'show'])->whereNumber('project')->where('kind', '[OVR]-(op|cl)')->name('visits.show');
            Route::get('/applications/{project}/me-visits/new/{person}', [VisitController::class, 'meCreate'])->whereNumber('project')->whereNumber('person')->name('visits.me.create');
            Route::get('/applications/{project}/me-visits/{visit}', [VisitController::class, 'meShow'])->whereNumber('project')->whereNumber('visit')->name('visits.me.show');
            Route::get('/visits/schedule', [VisitController::class, 'schedule'])->name('visits.schedule');
            Route::get('/visits/schedule/export', [VisitController::class, 'scheduleExport'])->middleware('throttle:10,1')->name('visits.schedule.export');
            Route::get('/visits/results', [VisitController::class, 'results'])->name('visits.results');
            Route::get('/visits/results/export', [VisitController::class, 'resultsExport'])->middleware('throttle:10,1')->name('visits.results.export');
            Route::get('/visits/statistics', [VisitController::class, 'statistics'])->name('visits.statistics');
            Route::get('/visits/statistics/export', [VisitController::class, 'statisticsExport'])->middleware('throttle:10,1')->name('visits.statistics.export');

            // M4.15 profession meetings (ProfessionMeetingService rechecks profession_meetings.view / manage / delete per city; reopening needs accounts.manage).
            Route::get('/profession-meetings', [ProfessionMeetingController::class, 'index'])->name('meetings.index');
            Route::get('/profession-meetings/new', [ProfessionMeetingController::class, 'create'])->name('meetings.create');
            Route::get('/profession-meetings/report', [ProfessionMeetingController::class, 'report'])->name('meetings.report');
            Route::get('/profession-meetings/{meeting}', [ProfessionMeetingController::class, 'show'])->whereNumber('meeting')->name('meetings.show');
            // M4.18: printed minutes and survey of a final meeting (MeetingPaper rechecks profession_meetings.view in the city and audits papers.printed).
            Route::get('/profession-meetings/{meeting}/print/{kind}', [MeetingPaperController::class, 'show'])->whereNumber('meeting')->where('kind', 'minutes|survey')->name('meetings.print');
            Route::get('/profession-meetings/{meeting}/word/{kind}', [MeetingPaperController::class, 'word'])->whereNumber('meeting')->where('kind', 'minutes|survey')->middleware('throttle:30,1')->name('meetings.word');
            // M4.16 task board (TaskBoardService / ProjectTaskService recheck tasks.view, applications.view, tasks.manage and the responsibility per city) and the notification box.
            Route::get('/task-board', [TaskBoardController::class, 'index'])->name('tasks.board');
            Route::get('/task-board/export', [TaskBoardController::class, 'export'])->middleware('throttle:10,1')->name('tasks.board.export');
            Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
            Route::get('/notifications/export', [NotificationController::class, 'export'])->middleware('throttle:10,1')->name('notifications.export');
            Route::post('/notifications/seen', [NotificationController::class, 'seen'])->block(10, 10)->name('notifications.seen');
            Route::get('/notifications/send', [NotificationController::class, 'create'])->name('notifications.create');
            Route::post('/notifications/send', [NotificationController::class, 'send'])->block(10, 10)->name('notifications.send');

            // M4.13 special cases (SpecialCaseService / SpecialCaseListService recheck applications.view + special_cases.view, manage, delete and reports.export per city).
            Route::get('/applications/{project}/special-cases', [SpecialCaseController::class, 'project'])->whereNumber('project')->name('special-cases.project');
            Route::get('/special-cases', [SpecialCaseController::class, 'index'])->name('special-cases.index');
            Route::get('/special-cases/export', [SpecialCaseController::class, 'export'])->middleware('throttle:10,1')->name('special-cases.export');
            // M4.17 search, 22 types (SearchService rechecks each type's capabilities per city; the export also reports.export, loans finance.export).
            Route::get('/search', [SearchController::class, 'index'])->name('search.index');
            Route::get('/search/export/{token}', [SearchController::class, 'export'])->where('token', '[A-Za-z0-9]{40}')->middleware('throttle:10,1')->name('search.export');
            // M4.17 statistics, 15 axes (StatisticsService rechecks applications.view per city, the export also reports.export).
            Route::get('/statistics', [StatisticsController::class, 'index'])->name('statistics.index');
            Route::get('/statistics/export/{token}', [StatisticsController::class, 'export'])->where('token', '[A-Za-z0-9]{40}')->middleware('throttle:10,1')->name('statistics.export');
            // M4.20 official papers library (PaperLibrary rechecks projects.view per city).
            Route::get('/papers', [PaperLibraryController::class, 'index'])->name('papers.index');
            Route::get('/papers/download', [PaperLibraryController::class, 'download'])->middleware('throttle:60,1')->name('papers.download');
            Route::get('/papers/original', [PaperLibraryController::class, 'original'])->middleware('throttle:60,1')->name('papers.original');
            // Keeping the papers (papers.manage, rechecked per city by PaperManager): add or replace a file, remove it, bring an original back.
            Route::post('/papers/files', [PaperLibraryController::class, 'upload'])->middleware('throttle:30,1')->name('papers.upload');
            Route::delete('/papers/files', [PaperLibraryController::class, 'remove'])->middleware('throttle:30,1')->name('papers.remove');
            Route::post('/papers/files/restore', [PaperLibraryController::class, 'restore'])->middleware('throttle:30,1')->name('papers.restore');
            Route::get('/papers/settings', [\App\Http\Controllers\PaperSettingsController::class, 'show'])->name('papers.settings');
            Route::post('/papers/settings', [\App\Http\Controllers\PaperSettingsController::class, 'save'])->middleware('throttle:30,1')->name('papers.settings.save');
            Route::post('/papers/settings/signatory', [\App\Http\Controllers\PaperSettingsController::class, 'saveSignatory'])->middleware('throttle:30,1')->name('papers.settings.signatory');
            Route::delete('/papers/settings/signatory', [\App\Http\Controllers\PaperSettingsController::class, 'removeSignatory'])->middleware('throttle:30,1')->name('papers.settings.signatory.remove');
            Route::get('/papers/review/{form}', [PaperLibraryController::class, 'review'])->where('form', 'F[0-9]{2}')->name('papers.review');
            Route::get('/applications/{project}/print', [PaperPrintController::class, 'application'])->whereNumber('project')->name('applications.print');
            Route::get('/applications/{project}/papers/{paper}', [\App\Http\Controllers\PaperPrintController::class, 'project'])->whereNumber('project')->where('paper', 'audio-consent|special-case|experience-test')->name('applications.paper');
            Route::get('/applications/{project}/finance-papers/{paper}', [\App\Http\Controllers\PaperPrintController::class, 'finance'])->whereNumber('project')->where('paper', 'promissory-note|clearance|disbursement-request|partnership-contract|beneficiary-card')->name('applications.finance-paper');
            Route::get('/applications/{project}/paper-data', [\App\Http\Controllers\PaperDataController::class, 'show'])->whereNumber('project')->name('applications.paper-data');
            Route::post('/applications/{project}/paper-data/{form}', [\App\Http\Controllers\PaperDataController::class, 'save'])->whereNumber('project')->where('form', 'disbursement|contract')->middleware('throttle:30,1')->name('applications.paper-data.save');
            // M4.20 wave 3: the purchasing papers and their entry page (applications.view + purchasing.view / purchasing.manage per city).
            Route::get('/applications/{project}/purchase-papers/{paper}', [\App\Http\Controllers\PurchasePaperController::class, 'print'])->whereNumber('project')->where('paper', 'purchase-request|price-quote|equipment-receipt|equipment-return')->name('applications.purchase-paper');
            Route::get('/applications/{project}/purchase-data', [\App\Http\Controllers\PurchasePaperController::class, 'show'])->whereNumber('project')->name('applications.purchase-data');
            Route::post('/applications/{project}/purchase-data/{form}', [\App\Http\Controllers\PurchasePaperController::class, 'save'])->whereNumber('project')->where('form', 'request|quote|receipt|return')->middleware('throttle:30,1')->name('applications.purchase-data.save');
            Route::get('/applications/{project}/file/print', [PaperPrintController::class, 'file'])->whereNumber('project')->name('applications.file');
            // M4.10 executive file and purchasing (the services recheck applications.view + executive_files.view / purchasing.view and the manage capability per city).
            Route::get('/applications/{project}/executive-file', [ExecutiveFileController::class, 'show'])->whereNumber('project')->name('executive.show');
            Route::get('/applications/{project}/purchases', [PurchasingController::class, 'show'])->whereNumber('project')->name('purchasing.show');

            // M4.3 people and families (PeopleService rechecks people.view / people.manage per city on every action).
            Route::get('/people', [PeopleController::class, 'index'])->name('people.index');
            Route::get('/people/create', [PeopleController::class, 'create'])->name('people.create');
            Route::get('/national-check', [PeopleController::class, 'national'])->name('people.national');
            // Smart-search phase 2: Excel/CSV of the people list as shown (single-use token; DatasetExport rechecks reports.export per city).
            Route::get('/people/export/{token}', PeopleExportController::class)->where('token', '[A-Za-z0-9]{40}')->middleware('throttle:10,1')->name('people.export');
            Route::get('/people/{person}', [PeopleController::class, 'show'])->whereNumber('person')->name('people.show');
            Route::get('/people/{person}/edit', [PeopleController::class, 'edit'])->whereNumber('person')->name('people.edit');
            // Reading the identity document from an attachment (IdentityReviewService rechecks people.manage and the attachment's own permissions).
            Route::get('/people/{person}/identity', [PeopleController::class, 'identity'])->whereNumber('person')->name('people.identity');

            // M4.11 attachments (AttachmentService rechecks the owner, attachments.download and the category grant on every file).
            Route::get('/attachments', [AttachmentFileController::class, 'index'])->name('attachments.index');
            Route::get('/attachments/{attachment}', [AttachmentFileController::class, 'show'])->whereNumber('attachment')->name('attachments.file');

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
