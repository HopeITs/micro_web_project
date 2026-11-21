<?php

namespace App\Support;

use App\Models\WebAccount;
use App\Services\AccountAccess;
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
            ['key' => 'checklist', 'icon' => 'clipboard', 'capability' => 'assessments.view', 'task' => 'M4.7'],
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
            ['key' => 'kobo', 'icon' => 'sync', 'capability' => 'visits.manage', 'task' => 'M4.14'],
            ['key' => 'meetings', 'icon' => 'meeting', 'capability' => 'profession_meetings.view', 'task' => 'M4.15'],
        ],
        'tools' => [
            ['key' => 'search', 'icon' => 'search', 'capability' => 'projects.view', 'task' => 'M4.17'],
            ['key' => 'statistics', 'icon' => 'chart', 'capability' => 'applications.view', 'task' => 'M4.17'],
            ['key' => 'tasks', 'icon' => 'task', 'capability' => 'tasks.view', 'task' => 'M4.16'],
            ['key' => 'accounts', 'icon' => 'shield', 'route' => 'admin.accounts.index', 'capability' => 'accounts.manage'],
            ['key' => 'lookups', 'icon' => 'clipboard', 'route' => 'lookups.index', 'capability' => 'lookups.manage'],
            ['key' => 'audit', 'icon' => 'clock', 'route' => 'admin.audit', 'capability' => 'accounts.manage'],
            ['key' => 'design', 'icon' => 'settings', 'route' => 'design', 'capability' => 'accounts.manage'],
        ],
    ];

    /** @return array<string, list<array{key:string, icon:string, url:?string, current:bool, task:?string}>> */
    public static function for(WebAccount $account): array
    {
        $access = app(AccountAccess::class);
        $held = [];
        $out = [];
        foreach (self::GROUPS as $group => $items) {
            foreach ($items as $item) {
                $held[$item['capability']] ??= $access->cities($account, $item['capability'])->isNotEmpty();
                if (! $held[$item['capability']]) {
                    continue;
                }
                $route = $item['route'] ?? null;
                $out[$group][] = [
                    'key' => $item['key'], 'icon' => $item['icon'], 'task' => $item['task'] ?? null,
                    'url' => $route && Route::has($route) ? route($route, ['locale' => app()->getLocale()]) : null,
                    'current' => $route !== null && request()->routeIs(match ($route) {
                        'projects.index' => 'projects.*', 'admin.accounts.index' => ['admin.accounts.*', 'admin.tasks', 'admin.staff'], 'admin.audit' => 'admin.audit*', 'lookups.index' => 'lookups.*', 'people.index' => ['people.index', 'people.show', 'people.edit', 'people.create'], 'finance.payments' => ['finance.payments', 'finance.show'], 'visits.schedule' => ['visits.schedule', 'visits.hub', 'visits.show', 'visits.me.*'], default => $route
                    }),
                ];
            }
        }

        return $out;
    }
}
