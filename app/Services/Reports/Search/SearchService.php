<?php

declare(strict_types=1);

namespace App\Services\Reports\Search;

use App\Models\WebAccount;
use App\Services\AccountAccess;
use App\Services\AccountAuditTrail;
use App\Services\Reports\ProjectReportFilters;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The search page and its export (M4.17 part A): which of the 22 types an account may use and in which cities, one page of rows, the counter and
 * totals, the row links and the Excel/CSV rows. Read only. Every capability is checked here per city (a type is offered in the cities where the
 * account holds all the capabilities of the type); listing is audited `reports.search` (type, never the search text) and an export
 * `reports.exported` before the first byte is sent.
 */
final class SearchService
{
    private ?Collection $cityRows = null;

    public function __construct(private AccountAccess $access, private AccountAuditTrail $audit) {}

    /** The filter state of the page or an export, cleaned: common filters, the cities chosen and the "last visit only" switch. */
    public static function filters(array $input): array
    {
        return ProjectReportFilters::clean($input) + [
            'cities' => array_values(array_unique(array_filter(array_map(fn ($c) => (is_int($c) || (is_string($c) && ctype_digit($c))) ? (int) $c : 0, (array) ($input['cities'] ?? []))))),
            'last' => in_array($input['last'] ?? false, [true, 1, '1', 'on', 'true'], true),
        ];
    }

    /** @return array<int, array<string, true>> city id => the capabilities the account holds there (one query) */
    public function held(WebAccount $account): array
    {
        if (! $this->access->active($account)) {
            return [];
        }
        $held = [];
        foreach (DB::table('account_city_capabilities')->where('account_id', $account->id)->whereNull('revoked_at')->get(['city_id', 'capability']) as $g) {
            $held[(int) $g->city_id][$g->capability] = true;
        }

        return $held;
    }

    /**
     * Cities where the account may use the type (all its capabilities; for an export also the export ones).
     *
     * @return Collection<int, object{id: int, code: string, name_ar: string, name_en: string}>
     */
    public function cities(WebAccount $account, SearchMode $mode, bool $export = false, ?array $held = null): Collection
    {
        $held ??= $this->held($account);
        $needed = $export ? [...$mode->capabilities(), ...$mode->exportCapabilities()] : $mode->capabilities();

        return ($this->cityRows ??= DB::table('cities')->orderBy('id')->get(['id', 'code', 'name_ar', 'name_en']))
            ->filter(fn ($c) => count(array_diff($needed, array_keys($held[(int) $c->id] ?? []))) === 0 && $mode->for($account)->cityAllowed($account, (int) $c->id))->values();
    }

    /** @return array<string, SearchMode> the types the account may use somewhere, by key, in select order */
    public function available(WebAccount $account): array
    {
        $held = $this->held($account);
        $out = [];
        foreach (SearchModes::keys() as $key) {
            $mode = SearchModes::make($key)->for($account);
            if ($this->cities($account, $mode, false, $held)->isNotEmpty()) {
                $out[$key] = $mode;
            }
        }

        return $out;
    }

    /** The page was opened or a type was chosen: refused (403, audited) without any type, else audited once. Returns the available types. */
    public function opened(WebAccount $account, ?string $key = null): array
    {
        $available = $this->available($account);
        if ($available === [] || ($key !== null && ! isset($available[$key]))) {
            $this->audit->record($account->id, 'access.denied', 'denied');
            abort(403);
        }
        $this->audit->record($account->id, 'reports.search', 'succeeded', decision: 'mode:'.($key ?? array_key_first($available)));

        return $available;
    }

    /** City ids searched: the chosen ones among the permitted, else all permitted. @return list<int> */
    private function ids(Collection $permitted, array $filters): array
    {
        $all = $permitted->pluck('id')->map(fn ($id) => (int) $id)->all();
        $chosen = array_values(array_intersect($filters['cities'] ?? [], $all));

        return $chosen !== [] ? $chosen : $all;
    }

    /**
     * One page of a type.
     *
     * @return array{rows: LengthAwarePaginator, cells: list<array{cells: list<mixed>, url: ?string, flag: bool}>, total: int, summary: ?array, cities: Collection, mode: SearchMode}
     */
    public function page(WebAccount $account, string $key, array $filters, int $page = 1): array
    {
        $mode = SearchModes::make($key)->for($account);
        $held = $this->held($account);
        $permitted = $this->cities($account, $mode, false, $held);
        if ($permitted->isEmpty()) {
            $this->audit->record($account->id, 'access.denied', 'denied');
            abort(403);
        }
        $filters = self::filters($filters);
        $query = $mode->query($this->ids($permitted, $filters), $filters);
        $paginator = $mode->order(clone $query)->paginate(SearchMode::PER_PAGE, ['*'], 'page', max(1, $page));

        return ['rows' => $paginator, 'cells' => $this->assemble($mode, $paginator->getCollection(), $held), 'total' => $mode->counter($query), 'summary' => $mode->summary($query),
            'cities' => $permitted, 'mode' => $mode];
    }

    /** @return bool whether the account may export the type somewhere */
    public function canExport(WebAccount $account, string $key, ?array $held = null): bool
    {
        return $this->cities($account, SearchModes::make($key)->for($account), true, $held)->isNotEmpty();
    }

    /**
     * The rows of an export: every matching row, authorised and audited before this returns (so before the first byte).
     *
     * @return array{0: list<string>, 1: iterable<list<mixed>>, 2: list<int>, 3: SearchMode}
     */
    public function export(WebAccount $account, string $key, array $filters): array
    {
        $mode = SearchModes::make($key)->for($account);
        $held = $this->held($account);
        $permitted = $this->cities($account, $mode, true, $held);
        if ($permitted->isEmpty()) {
            $this->audit->record($account->id, 'access.denied', 'denied');
            abort(403);
        }
        $filters = self::filters($filters);
        $query = $mode->order($mode->query($this->ids($permitted, $filters), $filters));
        $count = $mode->rows($query);
        $this->audit->record($account->id, 'reports.exported', 'succeeded', decision: 'search:'.$key.'/rows:'.$count);

        $rows = (function () use ($query, $mode, $held) {
            foreach ($query->lazy(500)->chunk(500) as $chunk) {
                foreach ($this->assemble($mode, collect($chunk->all()), $held) as $line) {
                    yield $line['cells'];
                }
            }
        })();

        return [$mode->headings(), $rows, $mode->numeric(), $mode];
    }

    /**
     * Cells (city first), the link and the duplicate flag of each row; sensitive cells are blank where the account lacks `people.view`.
     *
     * @param  Collection<int, object>  $rows
     * @return list<array{cells: list<mixed>, url: ?string, flag: bool}>
     */
    public function assemble(SearchMode $mode, Collection $rows, array $held): array
    {
        if ($rows->isEmpty()) {
            return [];
        }
        $lines = $mode->lines($rows);
        $flags = $mode->flags($rows);
        $sensitive = $mode->sensitive();
        $out = [];
        foreach ($rows->values() as $i => $row) {
            $city = (int) $row->city_id;
            $cells = [$mode->cityName($row), ...$lines[$i]];
            if ($sensitive !== [] && ! isset($held[$city]['people.view'])) {
                foreach ($sensitive as $index) {
                    $cells[$index] = '';
                }
            }
            $out[] = ['cells' => $cells, 'url' => $this->url($mode->target($row), $city, $held), 'flag' => (bool) ($flags[$i] ?? false)];
        }

        return $out;
    }

    /** The record's address when the reader may open it in the row's city. */
    private function url(?array $target, int $city, array $held): ?string
    {
        if ($target === null || ! isset(SearchMode::LINKS[$target[0]])) {
            return null;
        }
        [$route, $capabilities, $parameter] = SearchMode::LINKS[$target[0]];
        if (count(array_diff($capabilities, array_keys($held[$city] ?? []))) > 0) {
            return null;
        }

        return route($route, ['locale' => app()->getLocale(), $parameter => $target[1]]);
    }
}
