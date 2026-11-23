<?php

namespace App\Support;

use App\Models\WebAccount;
use App\Services\AccountAccess;
use App\Services\Reports\Search\SearchService;
use App\Support\Datasets\DatasetRegistry;
use Illuminate\Support\Facades\Route;

/**
 * The sidebar, grouped like the desktop menu (Application, Financial, M&E, Tools). An item shows when the
 * account holds its capability in at least one city. Items whose page is not rebuilt yet carry the plan task
 * (M4.x) and are shown as "soon" instead of a link.
 */
final class Navigation
{
    public const GROUPS = [
        'main' => [
            ['key' => 'home', 'icon' => 'home', 'route' => 'home', 'capability' => 'projects.view'],
            ['key' => 'projects', 'icon' => 'folder', 'route' => 'projects.index', 'capability' => 'projects.view'],
        ],
        'applications' => [
            ['key' => 'new_application', 'icon' => 'plus', 'route' => 'applications.create', 'capability' => 'applications.manage'],
            ['key' => 'people', 'icon' => 'users', 'route' => 'people.index', 'capability' => 'people.view'],
            ['key' => 'checklist', 'icon' => 'clipboard', 'route' => 'checklists.index', 'capability' => 'assessments.view'],
            ['key' => 'national_number', 'icon' => 'id', 'route' => 'people.national', 'capability' => 'people.view'],
        ],
        'finance' => [
            // M4.9: loans are recorded from each project's finance page; the schedule lists every instalment.
            ['key' => 'payments', 'icon' => 'calendar', 'route' => 'finance.payments', 'capability' => 'finance.view'],
            ['key' => 'attachments', 'icon' => 'file', 'route' => 'attachments.index', 'capability' => 'attachments.download'],
        ],
        'field' => [
            // M4.12: the schedule, with the results report and the visitor statistics (administrator) under it.
            ['key' => 'visits', 'icon' => 'map', 'route' => 'visits.schedule', 'capability' => 'visits.view'],
            ['key' => 'visit_results', 'icon' => 'chart', 'route' => 'visits.results', 'capability' => 'me_visits.view'],
            ['key' => 'visitor_statistics', 'icon' => 'users', 'route' => 'visits.statistics', 'capability' => 'visits.statistics'],
            // M4.13: the list of all special cases (each project's cases open from its page).
            ['key' => 'special_cases', 'icon' => 'clipboard', 'route' => 'special-cases.index', 'capability' => 'special_cases.view'],
            ['key' => 'kobo', 'icon' => 'sync', 'route' => 'kobo.index', 'capability' => 'visits.manage'],
            ['key' => 'meetings', 'icon' => 'meeting', 'route' => 'meetings.index', 'capability' => 'profession_meetings.view'],
        ],
        'tools' => [
            // M4.17: shown when at least one of the 22 search types is available to the account (SearchService decides, per city).
            ['key' => 'search', 'icon' => 'search', 'route' => 'search.index', 'capability' => 'projects.view', 'gate' => 'search'],
            ['key' => 'statistics', 'icon' => 'chart', 'route' => 'statistics.index', 'capability' => 'applications.view'],
            // Smart-search phase 3: the pivot report builder; 'reports.view' is not a grant, for() shows it when the account can read at least one dataset.
            ['key' => 'reports', 'icon' => 'chart', 'route' => 'reports.index', 'capability' => 'reports.view'],
            ['key' => 'tasks', 'icon' => 'task', 'route' => 'tasks.board', 'capability' => 'tasks.view'],
            ['key' => 'notifications', 'icon' => 'bell', 'route' => 'notifications.index', 'capability' => 'notifications.view'],
            ['key' => 'accounts', 'icon' => 'shield', 'route' => 'admin.accounts.index', 'capability' => 'accounts.manage'],
            ['key' => 'lookups', 'icon' => 'clipboard', 'route' => 'lookups.index', 'capability' => 'lookups.manage'],
            ['key' => 'papers', 'icon' => 'file', 'route' => 'papers.index', 'capability' => 'projects.view'],
            ['key' => 'audit', 'icon' => 'clock', 'route' => 'admin.audit', 'capability' => 'accounts.manage'],
            ['key' => 'design', 'icon' => 'settings', 'route' => 'design', 'capability' => 'accounts.manage'],
        ],
    ];

    /** @return array<string, list<array{key:string, icon:string, url:?string, current:bool, task:?string}>> */
    public static function for(WebAccount $account): array
    {
        $access = app(AccountAccess::class);
        $held = [];
        $searchable = null;
        $out = [];
        foreach (self::GROUPS as $group => $items) {
            foreach ($items as $item) {
                if (($item['gate'] ?? null) === 'search') {
                    $searchable ??= app(SearchService::class)->available($account) !== [];
                    if (! $searchable) {
                        continue;
                    }
                } else {
                    $held[$item['capability']] ??= $item['capability'] === 'reports.view'
                        ? DatasetRegistry::readableBy($account) !== [] // not a grant: at least one dataset is readable
                        : $access->cities($account, $item['capability'])->isNotEmpty();
                    if (! $held[$item['capability']]) {
                        continue;
                    }
                }
                $route = $item['route'] ?? null;
                $out[$group][] = [
                    'key' => $item['key'], 'icon' => $item['icon'], 'task' => $item['task'] ?? null,
                    'url' => $route && Route::has($route) ? route($route, ['locale' => app()->getLocale()]) : null,
                    'current' => $route !== null && request()->routeIs(match ($route) {
                        'projects.index' => 'projects.*', 'meetings.index' => 'meetings.*', 'admin.accounts.index' => ['admin.accounts.*', 'admin.tasks', 'admin.staff'], 'admin.audit' => 'admin.audit*', 'lookups.index' => 'lookups.*', 'people.index' => ['people.index', 'people.show', 'people.edit', 'people.create'], 'checklists.index' => ['checklists.index', 'checklists.show', 'checklists.print'], 'search.index' => 'search.*', 'statistics.index' => 'statistics.*', 'finance.payments' => ['finance.payments', 'finance.show'], 'visits.schedule' => ['visits.schedule', 'visits.hub', 'visits.show', 'visits.me.*'], default => $route
                    }),
                ];
            }
        }

        return $out;
    }
}
