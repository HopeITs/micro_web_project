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
            ['key' => 'new_application', 'icon' => 'plus', 'capability' => 'applications.manage', 'task' => 'M4.5'],
            ['key' => 'people', 'icon' => 'users', 'capability' => 'people.view', 'task' => 'M4.3'],
            ['key' => 'checklist', 'icon' => 'clipboard', 'capability' => 'assessments.view', 'task' => 'M4.7'],
            ['key' => 'national_number', 'icon' => 'id', 'capability' => 'people.view', 'task' => 'M4.4'],
        ],
        'finance' => [
            ['key' => 'loans', 'icon' => 'wallet', 'capability' => 'finance.manage', 'task' => 'M4.9'],
            ['key' => 'payments', 'icon' => 'calendar', 'capability' => 'finance.view', 'task' => 'M4.9'],
            ['key' => 'attachments', 'icon' => 'file', 'capability' => 'attachments.download', 'task' => 'M4.11'],
        ],
        'field' => [
            ['key' => 'visits', 'icon' => 'map', 'capability' => 'visits.view', 'task' => 'M4.12'],
            ['key' => 'kobo', 'icon' => 'sync', 'capability' => 'visits.manage', 'task' => 'M4.14'],
            ['key' => 'meetings', 'icon' => 'meeting', 'capability' => 'profession_meetings.view', 'task' => 'M4.15'],
        ],
        'tools' => [
            ['key' => 'search', 'icon' => 'search', 'capability' => 'projects.view', 'task' => 'M4.17'],
            ['key' => 'statistics', 'icon' => 'chart', 'capability' => 'applications.view', 'task' => 'M4.17'],
            ['key' => 'tasks', 'icon' => 'task', 'capability' => 'tasks.view', 'task' => 'M4.16'],
            ['key' => 'accounts', 'icon' => 'shield', 'route' => 'admin.accounts.index', 'capability' => 'accounts.manage'],
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
                        'projects.index' => 'projects.*', 'admin.accounts.index' => 'admin.*', default => $route
                    }),
                ];
            }
        }

        return $out;
    }
}
