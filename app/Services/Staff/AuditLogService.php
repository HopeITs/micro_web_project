<?php

declare(strict_types=1);

namespace App\Services\Staff;

use App\Models\WebAccount;
use App\Services\AccountAccess;
use App\Services\AccountAuditTrail;
use App\Services\ProjectNumberAllocator;
use Carbon\CarbonImmutable;
use Generator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * M4.19 audit log (the desktop's Log form), read only (owner decision 20 Nov 2026: nothing is deleted or hidden).
 *
 * Two sources, both limited to the cities where the viewer holds `accounts.manage`:
 *  - the website trail `account_audit_events`: events recorded for one of those cities, and events with no city (sign-ins, password
 *    changes) whose actor or subject is an account with a capability or role there;
 *  - the desktop history `desktop_activity_log`, transferred from each city's `log` table (about 680,000 rows).
 * Every listing and export is itself recorded (`logs.listed` / `reports.exported`).
 */
final class AuditLogService
{
    public const PER_PAGE = 50;

    public const TIMEZONE = 'Asia/Damascus';

    /** Areas offered in the filter: label key => event-name prefixes. */
    public const AREAS = [
        'sign_in' => ['login', 'logout', 'password'],
        'accounts' => ['accounts', 'account', 'legacy', 'capability'],
        'people' => ['people'],
        'applications' => ['applications', 'application_draft', 'projects'],
        'lifecycle' => ['lifecycle', 'assessments', 'tasks'],
        'finance' => ['finance', 'executive_files', 'purchasing'],
        'visits' => ['visits', 'me_visits', 'special_cases'],
        'meetings' => ['profession_meetings'],
        'lookups' => ['lookups'],
        'attachments' => ['attachments'],
        'reports' => ['reports', 'logs', 'national', 'notifications'],
    ];

    /** Last part of an event name meaning the account only looked (no change). */
    private const VIEW_ACTIONS = ['view', 'list', 'listed', 'board', 'search', 'statistics', 'viewed', 'schedule', 'exported', 'checked', 'printed'];

    private const SECURITY_PREFIXES = ['login', 'logout', 'password', 'account', 'legacy', 'capability', 'access'];

    public function __construct(private AccountAccess $access, private AccountAuditTrail $audit) {}

    /** Cities whose audit trail the account may read. */
    public function cities(WebAccount $account): Collection
    {
        return $this->access->cities($account, 'accounts.manage');
    }

    /**
     * Kind of an event for the filter and the badge: a refused action, a sign-in/security event, a look, or a change.
     */
    public static function kind(string $event, string $outcome): string
    {
        if ($outcome !== 'succeeded') {
            return 'denied';
        }
        $parts = explode('.', $event);
        if (in_array($parts[0], self::SECURITY_PREFIXES, true) || in_array($event, ['accounts.role_assigned', 'accounts.role_revoked', 'accounts.email_set', 'accounts.disabled', 'accounts.enabled'], true)) {
            return 'security';
        }

        return in_array(end($parts), self::VIEW_ACTIONS, true) ? 'view' : 'change';
    }

    // ---------------------------------------------------------------- website trail

    /**
     * @param  array{city?: ?int, from?: ?string, to?: ?string, kind?: ?string, area?: ?string, actor?: ?string}  $filters
     * @return array{rows: LengthAwarePaginator, cities: Collection}
     */
    public function web(WebAccount $account, array $filters): array
    {
        [$ids, $permitted] = $this->scope($account, $filters, 'logs.listed');
        $rows = $this->webQuery($ids, $filters)->orderByDesc('e.id')->paginate(self::PER_PAGE)->withQueryString();
        $rows->setCollection(collect($this->describe($account, $rows->getCollection())));

        return ['rows' => $rows, 'cities' => $permitted];
    }

    /** @return Generator<int, list<string|int|null>> */
    public function webExport(WebAccount $account, array $filters): Generator
    {
        [$ids] = $this->scope($account, $filters, 'reports.exported');
        $query = $this->webQuery($ids, $filters);

        return (function () use ($query, $account) {
            foreach ($query->lazyByIdDesc(1000, 'e.id', 'id')->chunk(1000) as $chunk) {
                foreach ($this->describe($account, collect($chunk->all())) as $row) {
                    yield [$row['id'], $row['time'], $row['actor'], $row['city'], $row['label'], __('audit.kinds.'.$row['kind']), $row['subject'], $row['event'], $row['decision']];
                }
            }
        })();
    }

    public static function webHeadings(): array
    {
        return array_map(fn ($k) => __('audit.col.'.$k), ['number', 'time', 'account', 'city', 'action', 'kind', 'record', 'event', 'details']);
    }

    /** @param list<int> $ids */
    private function webQuery(array $ids, array $filters): Builder
    {
        $staff = fn (Builder $q) => $q->select('account_id')->from('account_city_capabilities')->whereIn('city_id', $ids)->whereNull('revoked_at')
            ->union(DB::table('account_city_roles')->select('account_id')->whereIn('city_id', $ids)->whereNull('revoked_at'));
        $query = DB::table('account_audit_events as e')
            ->leftJoin('web_accounts as actor', 'actor.id', '=', 'e.actor_account_id')
            ->leftJoin('web_accounts as subject', 'subject.id', '=', 'e.subject_account_id')
            ->leftJoin('cities as c', 'c.id', '=', 'e.city_id')
            ->select(['e.id', 'e.occurred_at', 'e.event_type', 'e.outcome', 'e.city_id', 'e.subject_type', 'e.subject_id', 'e.subject_account_id', 'e.decision_locator',
                'actor.username as actor_name', 'subject.username as subject_name', 'c.code as city_code', 'c.name_en as city_en', 'c.name_ar as city_ar'])
            ->where(function (Builder $scope) use ($ids, $staff) {
                $scope->whereIn('e.city_id', $ids)->orWhere(function (Builder $global) use ($staff) {
                    $global->whereNull('e.city_id')->where(function (Builder $who) use ($staff) {
                        $who->whereIn('e.actor_account_id', fn (Builder $q) => $staff($q))->orWhereIn('e.subject_account_id', fn (Builder $q) => $staff($q));
                    });
                });
            });
        if (($filters['from'] ?? '') !== '') {
            $query->where('e.occurred_at', '>=', CarbonImmutable::parse($filters['from'].' 00:00:00', self::TIMEZONE)->utc()->format('Y-m-d H:i:s'));
        }
        if (($filters['to'] ?? '') !== '') {
            $query->where('e.occurred_at', '<', CarbonImmutable::parse($filters['to'].' 00:00:00', self::TIMEZONE)->addDay()->utc()->format('Y-m-d H:i:s'));
        }
        if (($filters['area'] ?? '') !== '' && isset(self::AREAS[$filters['area']])) {
            $query->where(function (Builder $q) use ($filters) {
                foreach (self::AREAS[$filters['area']] as $prefix) {
                    $q->orWhere('e.event_type', 'like', $prefix.'.%');
                }
            });
        }
        $this->kindFilter($query, $filters['kind'] ?? null);
        if (($filters['actor'] ?? '') !== '') {
            $this->like($query, 'actor.username', $filters['actor']);
        }

        return $query;
    }

    private function kindFilter(Builder $query, ?string $kind): void
    {
        $views = function (Builder $q) {
            foreach (self::VIEW_ACTIONS as $action) {
                $q->orWhere('e.event_type', 'like', '%.'.$action);
            }
        };
        $security = function (Builder $q) {
            foreach (self::SECURITY_PREFIXES as $prefix) {
                $q->orWhere('e.event_type', 'like', $prefix.'.%');
            }
            $q->orWhereIn('e.event_type', ['accounts.role_assigned', 'accounts.role_revoked', 'accounts.email_set', 'accounts.disabled', 'accounts.enabled']);
        };
        match ($kind) {
            'denied' => $query->where('e.outcome', '!=', 'succeeded'),
            'security' => $query->where('e.outcome', 'succeeded')->where($security),
            'view' => $query->where('e.outcome', 'succeeded')->whereNot($security)->where($views),
            'change' => $query->where('e.outcome', 'succeeded')->whereNot($security)->whereNot($views),
            default => null,
        };
    }

    /**
     * Readable lines: the action in words, the record it touched (name or number) and a link where the viewer may open it.
     *
     * @return list<array<string, mixed>>
     */
    private function describe(WebAccount $viewer, Collection $rows): array
    {
        $ar = app()->isLocale('ar');
        $locale = app()->getLocale();
        $byType = fn (string $type) => $rows->where('subject_type', $type)->pluck('subject_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
        $projects = DB::table('projects as p')->join('cities as c', 'c.id', '=', 'p.city_id')->whereIn('p.id', $byType('application_entity'))
            ->get(['p.id', 'p.project_number', 'c.code'])->keyBy('id');
        $people = DB::table('people')->whereIn('id', $byType('person_entity'))->get(['id', 'first_name', 'father_name', 'last_name'])->keyBy('id');
        $may = [];
        $can = function (string $capability, ?int $city) use ($viewer, &$may): bool {
            return $city !== null && ($may[$capability.':'.$city] ??= $this->access->check($viewer, $city, $capability));
        };

        return $rows->map(function ($row) use ($ar, $locale, $projects, $people, $can) {
            $event = (string) $row->event_type;
            $city = $row->city_id !== null ? (int) $row->city_id : null;
            $id = $row->subject_id !== null ? (int) $row->subject_id : null;
            [$subject, $url] = match ($row->subject_type) {
                'application_entity' => [isset($projects[$id]) ? ProjectNumberAllocator::display($projects[$id]->code, (int) $projects[$id]->project_number) : '#'.$id,
                    isset($projects[$id]) && $can('projects.view', $city) ? route('projects.show', ['locale' => $locale, 'project' => $id]) : null],
                'person_entity' => [isset($people[$id]) ? trim(implode(' ', array_filter([$people[$id]->first_name, $people[$id]->father_name, $people[$id]->last_name]))) : '#'.$id,
                    isset($people[$id]) && $can('people.view', $city) ? route('people.show', ['locale' => $locale, 'person' => $id]) : null],
                'family_entity' => [__('audit.subjects.family', ['id' => $id]), null],
                'meeting_entity' => [__('audit.subjects.meeting', ['id' => $id]), null],
                'account', 'capability_grant', 'legacy_link' => [$row->subject_name ?? '#'.$row->subject_account_id,
                    $row->subject_account_id ? route('admin.accounts.show', ['locale' => $locale, 'account' => (int) $row->subject_account_id]) : null],
                'lookup_value' => $this->lookupSubject((string) $row->decision_locator, $locale),
                'project_record', 'attachment_record' => ['#'.$id, null],
                default => [$row->subject_name, $row->subject_account_id ? route('admin.accounts.show', ['locale' => $locale, 'account' => (int) $row->subject_account_id]) : null],
            };

            return [
                'id' => (int) $row->id,
                'time' => CarbonImmutable::parse((string) $row->occurred_at, 'UTC')->setTimezone(self::TIMEZONE)->format('Y-m-d H:i:s'),
                'actor' => $row->actor_name, 'city' => $row->city_code ? ($ar ? $row->city_ar : $row->city_en) : null,
                'event' => $event, 'label' => self::label($event), 'kind' => self::kind($event, (string) $row->outcome), 'outcome' => (string) $row->outcome,
                'subject' => $subject, 'url' => $url, 'decision' => $row->decision_locator,
            ];
        })->values()->all();
    }

    /** @return array{0: string, 1: ?string} */
    private function lookupSubject(string $decision, string $locale): array
    {
        $list = str_starts_with($decision, 'lookup:') ? substr($decision, 7) : '';
        $name = __('lookups.names.'.$list);

        return [$list !== '' && $name !== 'lookups.names.'.$list ? $name : $list, $list !== '' && preg_match('/\A[a-z_]{2,40}\z/', $list) ? route('lookups.show', ['locale' => $locale, 'list' => $list]) : null];
    }

    /** The action in words: a known event, else "<action> · <area>" from the event name's parts, else the raw name. */
    public static function label(string $event): string
    {
        $key = 'audit.events.'.str_replace('.', '_', $event);
        if (__($key) !== $key) {
            return __($key);
        }
        $parts = explode('.', $event);
        $area = array_shift($parts);
        $action = implode('_', $parts);
        $areaKey = 'audit.nouns.'.$area;
        $actionKey = 'audit.verbs.'.$action;

        return __($actionKey) !== $actionKey && __($areaKey) !== $areaKey ? __($actionKey).' · '.__($areaKey) : $event;
    }

    // ---------------------------------------------------------------- desktop history

    /**
     * @param  array{city?: ?int, from?: ?string, to?: ?string, table?: ?string, user?: ?string, q?: ?string}  $filters
     * @return array{rows: LengthAwarePaginator, cities: Collection, tables: list<string>}
     */
    public function desktop(WebAccount $account, array $filters): array
    {
        [$ids, $permitted] = $this->scope($account, $filters, 'logs.listed');
        $rows = $this->desktopQuery($ids, $filters)->orderByDesc('d.logged_at')->orderByDesc('d.id')->paginate(self::PER_PAGE)->withQueryString();
        $rows->setCollection($rows->getCollection()->map(fn ($row) => $this->desktopLine($row)));

        return ['rows' => $rows, 'cities' => $permitted, 'tables' => $this->tables()];
    }

    /** @return Generator<int, list<string|int|null>> */
    public function desktopExport(WebAccount $account, array $filters): Generator
    {
        [$ids] = $this->scope($account, $filters, 'reports.exported');
        $query = $this->desktopQuery($ids, $filters);

        return (function () use ($query) {
            foreach ($query->lazyByIdDesc(2000, 'd.id', 'id') as $row) {
                $line = $this->desktopLine($row);
                yield [$line['legacy_id'], $line['time'], $line['user'], $line['city'], $line['table'], $line['text']];
            }
        })();
    }

    public static function desktopHeadings(): array
    {
        return array_map(fn ($k) => __('audit.col.'.$k), ['desktop_number', 'time', 'user', 'city', 'table', 'description']);
    }

    /** Distinct desktop table names (about 40), cached: grouping 680,000 rows on every page would be wasteful. */
    public function tables(): array
    {
        return Cache::remember('audit.desktop_tables', 600, fn () => DB::table('desktop_activity_log')->whereNotNull('on_table')->distinct()->orderBy('on_table')->pluck('on_table')->all());
    }

    /** @param list<int> $ids */
    private function desktopQuery(array $ids, array $filters): Builder
    {
        $query = DB::table('desktop_activity_log as d')->join('cities as c', 'c.id', '=', 'd.city_id')->whereIn('d.city_id', $ids)
            ->select(['d.id', 'd.legacy_id', 'd.logged_at', 'd.log_type', 'd.on_table', 'd.user_name', 'c.name_en as city_en', 'c.name_ar as city_ar']);
        if (($filters['from'] ?? '') !== '') {
            $query->where('d.logged_at', '>=', $filters['from'].' 00:00:00');
        }
        if (($filters['to'] ?? '') !== '') {
            $query->where('d.logged_at', '<', date('Y-m-d', strtotime($filters['to'].' +1 day')).' 00:00:00');
        }
        if (($filters['table'] ?? '') !== '') {
            $filters['table'] === '-' ? $query->whereNull('d.on_table') : $query->where('d.on_table', $filters['table']);
        }
        if (($filters['user'] ?? '') !== '') {
            $this->like($query, 'd.user_name', $filters['user']);
        }
        if (($filters['q'] ?? '') !== '') {
            $this->like($query, 'd.log_type', $filters['q']);
        }

        return $query;
    }

    /** @return array<string, mixed> */
    private function desktopLine(object $row): array
    {
        return [
            'legacy_id' => $row->legacy_id !== null ? (int) $row->legacy_id : null,
            'time' => $row->logged_at !== null ? substr((string) $row->logged_at, 0, 19) : null,
            'user' => $row->user_name, 'city' => app()->isLocale('ar') ? $row->city_ar : $row->city_en,
            'table' => $row->on_table, 'text' => $row->log_type,
        ];
    }

    // ---------------------------------------------------------------- shared

    /** @return array{0: list<int>, 1: Collection} */
    private function scope(WebAccount $account, array $filters, string $event): array
    {
        $permitted = $this->cities($account);
        if ($permitted->isEmpty()) {
            $this->audit->record($account->id, 'access.denied', 'denied');
            abort(403);
        }
        $all = $permitted->pluck('id')->map(fn ($id) => (int) $id)->all();
        $city = (int) ($filters['city'] ?? 0);
        $ids = in_array($city, $all, true) ? [$city] : $all;
        $this->audit->record($account->id, $event, 'succeeded');

        return [$ids, $permitted];
    }

    /** Contains-search where % and _ typed by the user are literal (ESCAPE '!' behaves the same in MariaDB and SQLite). */
    private function like(Builder $query, string $column, string $value): void
    {
        $query->whereRaw($column." like ? escape '!'", ['%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_substr($value, 0, 100)).'%']);
    }
}
