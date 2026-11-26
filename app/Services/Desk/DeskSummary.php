<?php

declare(strict_types=1);

namespace App\Services\Desk;

use App\Models\SavedView;
use App\Models\WebAccount;
use App\Services\AccountAccess;
use App\Services\Lifecycle\LifecycleService;
use App\Services\Lifecycle\ProjectTaskService;
use App\Services\Reports\ReportQuery;
use App\Services\Staff\NotificationInboxService;
use App\Services\Staff\TaskBoardService;
use App\Support\Datasets\Dataset;
use App\Support\Datasets\DatasetRegistry;
use App\Support\Datasets\InvalidQuerySpec;
use App\Support\Datasets\QuerySpec;
use App\Support\Datasets\ReportSpec;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The data of "My desk" (UX F4, X3): what waits on the account, its own saved views and reports as live cards, and the quick actions its
 * capabilities allow. Rules:
 *  - a tile exists only when the account holds its capability in at least one city, and its number counts only the cities that hold it;
 *  - every number is one aggregate query (the task tile adds one small query per city to know which tasks the account may tick);
 *  - the saved items are the account's own (`saved_views.account_id`), never another account's, shared or not; a card counts or ranks
 *    only what the account may read now, because the dataset's own city rule is applied again each time.
 * The tiles link to the page that already lists the same thing, with the equivalent filters where the page reads them from its address.
 */
final class DeskSummary
{
    /** How many saved items the desk shows. */
    public const SAVED_LIMIT = 8;

    /** "Due this week" and "expiring soon" windows, in days counted from today. */
    public const WEEK = 7;

    public const EXPIRY_DAYS = 30;

    public function __construct(
        private AccountAccess $access,
        private TaskBoardService $board,
        private ProjectTaskService $tasks,
        private NotificationInboxService $inbox,
    ) {}

    /** @return list<int> */
    private function ids(WebAccount $account, array $capabilities): array
    {
        return $this->access->citiesWithAll($account, $capabilities)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    private function locale(): array
    {
        return ['locale' => app()->getLocale()];
    }

    /** @return list<int> ids of the project states with these English names */
    private function stateIds(array $names): array
    {
        return DB::table('project_states')->whereIn('name_en', $names)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /** Projects whose current state is one of the ids, in the cities (one row per project: a project has one current state). */
    private function inState(array $cities, array $states): Builder
    {
        return DB::table('project_state_history as h')->join('projects as p', 'p.id', '=', 'h.project_id')
            ->where('h.is_current', true)->whereNull('h.deleted_at')->whereNull('p.deleted_at')
            ->whereIn('h.project_state_id', $states ?: [0])->whereIn('p.city_id', $cities);
    }

    /**
     * The "waiting on me" tiles in display order. Each is ['key', 'count', 'tone', 'icon', 'href']; a tile whose capability the account
     * lacks is absent (not shown as zero).
     *
     * @return list<array{key: string, count: int, tone: ?string, icon: string, href: ?string}>
     */
    public function tiles(WebAccount $account): array
    {
        $locale = $this->locale();
        $tiles = [];
        $add = function (string $key, int $count, ?string $tone, string $icon, ?string $href) use (&$tiles) {
            $tiles[] = ['key' => $key, 'count' => $count, 'tone' => $count > 0 ? $tone : null, 'icon' => $icon, 'href' => $href];
        };

        // 1. My open tasks: the task board's "My tasks" rule (cities with tasks.view + applications.view, tasks the account is assigned and may tick).
        if ($this->board->cities($account)->isNotEmpty()) {
            $add('tasks', $this->openTasks($account), 'warn', 'task', route('tasks.board', $locale));
        }
        // 2. Unseen notices: the size of the notification box (cities with notifications.view).
        if ($this->inbox->cities($account)->isNotEmpty()) {
            $add('notices', $this->inbox->count($account), 'info', 'bell', route('notifications.index', $locale));
        }
        // 3. Applications waiting for a decision (lifecycle.manage): current state Waiting or Under Review.
        if (($cities = $this->ids($account, ['lifecycle.manage', 'projects.view'])) !== []) {
            $states = $this->stateIds([LifecycleService::WAITING, LifecycleService::UNDER_REVIEW]);
            $add('decisions', $this->inState($cities, $states)->count(), 'warn', 'clipboard',
                route('projects.index', $locale + ['f' => ['state' => ['op' => 'in', 'values' => array_map('strval', $states)]]]));
        }
        // 4. Accepted projects without a loan (finance.view).
        if (($cities = $this->ids($account, ['finance.view', 'projects.view'])) !== []) {
            $states = $this->stateIds([LifecycleService::ACCEPTED]);
            $count = $this->inState($cities, $states)->whereNotExists(fn (Builder $l) => $l->selectRaw('1')->from('loans as l')->whereColumn('l.project_id', 'p.id')->whereNull('l.deleted_at'))->count();
            $add('no_loan', $count, 'warn', 'wallet',
                route('projects.index', $locale + ['f' => ['state' => ['op' => 'in', 'values' => array_map('strval', $states)], 'loan_amount' => ['op' => 'empty', 'values' => []]]]));
        }
        // 5. Instalments overdue or due within the week (applications.view + finance.view, as the payment schedule).
        if (($cities = $this->ids($account, ['applications.view', 'finance.view'])) !== []) {
            $until = now()->addDays(self::WEEK)->toDateString();
            $count = DB::table('loan_installments as i')->join('loans as l', 'l.id', '=', 'i.loan_id')->join('projects as p', 'p.id', '=', 'l.project_id')
                ->whereNull('i.deleted_at')->whereNull('l.deleted_at')->whereNull('p.deleted_at')->whereIn('p.city_id', $cities)
                ->where('i.is_paid', false)->where('i.due_on', '<', $until)->count();
            $add('installments', $count, 'danger', 'calendar', route('finance.payments', $locale + ['f' => [
                'due_on' => ['op' => 'lt', 'values' => [$until]], 'status' => ['op' => 'in', 'values' => ['overdue', 'unpaid']]]]));
        }
        // 6. Visits due (visits.manage): unseen shared visit notices dated within the week, overdue ones included.
        if (($cities = $this->ids($account, ['applications.view', 'visits.view', 'visits.manage'])) !== []) {
            $count = DB::table('web_notices as n')->whereIn('n.city_id', $cities)->where('n.kind', 'visit')->whereNull('n.account_id')->where('n.seen', false)
                ->where('n.notice_date', '<', now()->addDays(self::WEEK)->toDateString())->count();
            $add('visits', $count, 'warn', 'map', route('visits.schedule', $locale));
        }
        // 7. Executive files expiring within 30 days or already expired (executive_files.view).
        if (($cities = $this->ids($account, ['applications.view', 'executive_files.view'])) !== []) {
            $add('executive', $this->expiringFiles($cities), 'danger', 'file', $this->executiveHref($account));
        }

        return $tiles;
    }

    /** Open task cells the account is responsible for: the same rule as `TaskBoardService::mine`. */
    private function openTasks(WebAccount $account): int
    {
        $cities = $this->board->cities($account)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $assigned = DB::table('account_city_tasks')->where('account_id', $account->id)->whereIn('city_id', $cities)->whereNull('revoked_at')->get(['city_id', 'task_definition_id'])->groupBy('city_id');
        $perCity = [];
        foreach ($assigned as $city => $list) {
            $defs = collect($list->pluck('task_definition_id')->map(fn ($id) => (int) $id)->all())->intersect($this->tasks->tickable($account, (int) $city))->values()->all();
            if ($defs !== []) {
                $perCity[(int) $city] = $defs;
            }
        }
        if ($perCity === []) {
            return 0;
        }
        $funded = $this->stateIds(TaskBoardService::FUNDED_STATES);

        return DB::table('project_tasks as t')->join('task_definitions as d', 'd.id', '=', 't.task_definition_id')->join('projects as p', 'p.id', '=', 't.project_id')
            ->whereNull('p.deleted_at')->whereNull('t.deleted_at')->where('t.state', 0)->where('d.state', 1)
            ->where(function (Builder $any) use ($perCity) {
                foreach ($perCity as $city => $defs) {
                    $any->orWhere(fn (Builder $one) => $one->where('p.city_id', $city)->whereIn('t.task_definition_id', $defs));
                }
            })
            ->whereExists(fn (Builder $s) => $s->selectRaw('1')->from('project_state_history as h')->whereColumn('h.project_id', 'p.id')->where('h.is_current', true)->whereNull('h.deleted_at')
                ->where(fn ($w) => $w->where('d.type', 'a')->orWhereIn('h.project_state_id', $funded ?: [0])))
            ->count();
    }

    /** Executive files whose expiry date (current date plus months) falls before today plus the window. */
    private function expiringFiles(array $cities): int
    {
        $expiry = DB::connection()->getDriverName() === 'sqlite' ? "date(f.current_on, '+' || f.months || ' months')" : 'DATE_ADD(f.current_on, INTERVAL f.months MONTH)';

        return DB::table('executive_files as f')->join('projects as p', 'p.id', '=', 'f.project_id')->whereNull('f.deleted_at')->whereNull('p.deleted_at')
            ->whereIn('f.city_id', $cities)->whereNotNull('f.current_on')->whereNotNull('f.months')
            ->whereRaw($expiry.' < ?', [now()->addDays(self::EXPIRY_DAYS)->toDateString()])->count();
    }

    /** The expiry notices of the notification box where the account reads it, else the projects list (there is no list of executive files). */
    private function executiveHref(WebAccount $account): string
    {
        return $this->inbox->cities($account)->isNotEmpty()
            ? route('notifications.index', $this->locale() + ['kind' => 'executive_file'])
            : route('projects.index', $this->locale());
    }

    /**
     * The quick actions the account's capabilities allow: ['route', 'icon', 'key'].
     *
     * @return list<array{key: string, icon: string, href: string}>
     */
    public function quickActions(WebAccount $account): array
    {
        $locale = $this->locale();
        $holds = fn (string $capability) => $this->access->cities($account, $capability)->isNotEmpty();
        $out = [];
        $holds('applications.manage') && $out[] = ['key' => 'new_application', 'icon' => 'plus', 'href' => route('applications.create', $locale)];
        $holds('people.manage') && $out[] = ['key' => 'add_person', 'icon' => 'user', 'href' => route('people.create', $locale)];
        $holds('people.view') && $out[] = ['key' => 'national_check', 'icon' => 'id', 'href' => route('people.national', $locale)];
        $holds('visits.manage') && $out[] = ['key' => 'record_visit', 'icon' => 'map', 'href' => route('visits.schedule', $locale)];
        $holds('tasks.view') && $out[] = ['key' => 'task_board', 'icon' => 'task', 'href' => route('tasks.board', $locale)];
        $holds('notifications.send') && $out[] = ['key' => 'send_message', 'icon' => 'mail', 'href' => route('notifications.create', $locale)];
        DatasetRegistry::readableBy($account) !== [] && $out[] = ['key' => 'reports', 'icon' => 'chart', 'href' => route('reports.index', $locale)];

        return $out;
    }

    // ------------------------------------------------------------------ saved views and reports

    /** @return Collection<int, SavedView> the account's own saved items, default ones first (no pin column: owner schema decision) */
    public function savedItems(WebAccount $account): Collection
    {
        return SavedView::query()->where('account_id', $account->id)->orderByDesc('is_default')->orderBy('kind')->orderBy('name')->orderBy('id')
            ->limit(self::SAVED_LIMIT)->get();
    }

    public function savedTotal(WebAccount $account): int
    {
        return SavedView::query()->where('account_id', $account->id)->count();
    }

    /**
     * One live card per saved item. Each is ['id', 'kind', 'name', 'default', 'href', 'count' (view), 'rows' (report: label, value, share 0-100), 'unit'];
     * a count or rows that cannot be worked out now (no access, a field that no longer exists) are null.
     *
     * @return list<array<string, mixed>>
     */
    public function savedCards(WebAccount $account): array
    {
        return $this->savedItems($account)->map(function (SavedView $item) use ($account) {
            $card = ['id' => $item->id, 'kind' => $item->kind, 'name' => $item->name, 'default' => (bool) $item->is_default, 'dataset' => $item->dataset,
                'href' => $this->itemHref($item), 'count' => null, 'rows' => null, 'currency' => null];
            $dataset = DatasetRegistry::find($item->dataset);
            if ($dataset === null) {
                return $card; // the search and statistics pages keep their own view format: they open, they are not counted here
            }
            try {
                if ($item->kind === 'report') {
                    $this->reportRows($account, $dataset, $item, $card);
                } else {
                    $card['count'] = $this->viewCount($account, $dataset, $item);
                }
            } catch (InvalidQuerySpec) {
                // A stored item that no longer fits its dataset still opens; the page tells what was dropped.
            }

            return $card;
        })->all();
    }

    private function viewCount(WebAccount $account, Dataset $dataset, SavedView $item): ?int
    {
        $cities = $this->ids($account, $dataset->viewCapabilities());
        if ($cities === []) {
            return null;
        }
        $spec = QuerySpec::fromArray(['filters' => is_array($item->spec['filters'] ?? null) ? $item->spec['filters'] : []])->validate($dataset);
        $query = $dataset->query()->whereIn($dataset->cityColumn(), $cities);
        $spec->apply($query, $dataset, [], false);

        return $query->count();
    }

    /** Fills the top five rows of the report, by size, with their share of the largest. */
    private function reportRows(WebAccount $account, Dataset $dataset, SavedView $item, array &$card): void
    {
        $spec = ReportSpec::fromArray(is_array($item->spec) ? $item->spec : []);
        $result = app(ReportQuery::class)->run($account, $dataset, $spec);
        if ($result->isEmpty()) {
            $card['rows'] = [];

            return;
        }
        $pivot = $result->pivot(0);
        $rows = collect($pivot['rows'])->filter(fn ($row) => $row['total'] !== null)->sortByDesc('total')->take(5)->values();
        $max = (float) ($rows->max('total') ?: 0);
        $card['currency'] = $pivot['currency'];
        $card['unit'] = $result->measureLabel();
        $card['rows'] = $rows->map(fn ($row) => ['label' => (string) $row['label'], 'value' => (float) $row['total'], 'share' => $max > 0 ? (int) round($row['total'] / $max * 100) : 0])->all();
    }

    /** The page that opens the item, with its filters in the address when they can travel there (ids, codes, numbers and dates only). */
    private function itemHref(SavedView $item): string
    {
        $locale = $this->locale();
        $dataset = DatasetRegistry::find($item->dataset);
        if ($dataset === null) {
            return $item->dataset === 'statistics' ? route('statistics.index', $locale) : route('search.index', $locale);
        }
        $spec = is_array($item->spec) ? $item->spec : [];
        $filters = [];
        foreach (is_array($spec['filters'] ?? null) ? $spec['filters'] : [] as $filter) {
            $field = is_array($filter) && is_string($filter['field'] ?? null) ? $dataset->field($filter['field']) : null;
            if ($field === null || $field->type === 'text' || $field->sensitive) {
                $filters = null; // a name or national number never goes in an address: the page opens without this item's filters
                break;
            }
            $filters[$filter['field']] = ['op' => (string) ($filter['op'] ?? ''), 'values' => array_map('strval', array_values((array) ($filter['values'] ?? [])))];
        }
        $filters ??= [];

        if ($item->kind === 'report') {
            $rows = $spec['rows'] ?? null;
            $columns = $spec['columns'] ?? null;
            $measure = $spec['measure'] ?? null;
            $query = $locale + ['ds' => $item->dataset, 'rows' => is_array($rows) ? (string) ($rows['field'] ?? '') : (string) $rows, 'rg' => is_array($rows) ? (string) ($rows['grain'] ?? '') : '',
                'cols' => is_array($columns) ? (string) ($columns['field'] ?? '') : (string) $columns, 'cg' => is_array($columns) ? (string) ($columns['grain'] ?? '') : '',
                'm' => is_array($measure) ? (string) ($measure['type'] ?? 'count') : (string) ($measure ?: 'count'), 'mf' => is_array($measure) ? (string) ($measure['field'] ?? '') : ''];

            return route('reports.index', array_filter($query, fn ($v) => $v !== '') + ($filters === [] ? [] : ['f' => $filters]));
        }

        $query = $locale;
        foreach ($filters as $key => $filter) {
            $param = $dataset->listParams()[$key] ?? null;
            if ($param !== null && $filter['op'] === 'in' && $filter['values'] !== []) {
                $query[$param[0]] = $param[1] ? $filter['values'] : $filter['values'][0];
                unset($filters[$key]);
            }
        }
        $filters === [] || $query['f'] = $filters;

        return route((string) $dataset->listRoute(), $query);
    }
}
