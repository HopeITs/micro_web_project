<?php

namespace App\Livewire\Concerns;

use App\Models\WebAccount;
use App\Services\AccountAccess;
use App\Services\Reports\TableExport;
use App\Support\CityContext;
use App\Support\Datasets\Dataset;
use App\Support\Datasets\QuerySpec;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Livewire\Attributes\Url;

/**
 * Dataset table for a Livewire list (smart-search plan 2026-11, phase 2): chosen columns, header sort, rows per
 * page and the Excel/CSV export of exactly what is shown. Use it next to HasSmartFilters (which stores the columns
 * and sort in saved views through datasetTableState() / restoreDatasetTable()).
 *
 * Adopting it on another list (people, payments):
 *   1. `use HasSmartFilters, HasDatasetColumns;` and give the Dataset defaultColumns() / defaultOrder() / cell closures.
 *   2. Build rows with $this->tableQuery() and paginate with $this->perPage(); pass $this->dataTable() and
 *      $this->columnPicker() to the view and draw <x-hc.column-picker :picker="..."/> and <x-hc.data-table :rows :table/>.
 *   3. List the public properties filteredQuery() reads in exportStateProperties() (never put them in a URL),
 *      add an export route to DatasetExportController-style code (DatasetExport::fromToken) and name it in exportRoute(),
 *      and register the audit event "<dataset>.exported" in AccountAuditTrail.
 *
 * URL: `cols=number,name,state` (keys only), `sort=request_date,-number` (a leading "-" is descending), `per=50`.
 */
trait HasDatasetColumns
{
    public const PER_PAGE_CHOICES = [15, 50, 100];

    #[Url(as: 'cols', except: '')]
    public string $tableColumns = '';

    #[Url(as: 'sort', except: '')]
    public string $tableSort = '';

    #[Url(as: 'per', except: 15)]
    public int $perPage = 15;

    public ?string $columnError = null;

    /** Route name of the list's export (GET, takes `token`). */
    abstract protected function exportRoute(): string;

    /** Public properties filteredQuery() depends on; they travel to the export only through a short-lived server-side token. */
    protected function exportStateProperties(): array
    {
        return array_values(array_filter(['filters', 'textFilters', 'search'], fn ($p) => property_exists($this, $p)));
    }

    public function mountHasDatasetColumns(): void
    {
        // Whatever came in through the URL is checked against the registry; anything else is dropped.
        $this->tableColumns = $this->columnsParam($this->dataset()->cleanColumns($this->splitParam($this->tableColumns)));
        $this->tableSort = $this->sortParam($this->sortList());
        $this->perPage = $this->perPage();
    }

    /** @return list<string> the shown columns in order */
    public function visibleColumns(): array
    {
        return $this->dataset()->cleanColumns($this->splitParam($this->tableColumns));
    }

    /** Shows exactly these columns in this order; an unknown key leaves the columns unchanged. */
    public function setColumns(array $keys): void
    {
        $errors = $this->dataset()->columnErrors($keys);
        if ($errors !== []) {
            $this->columnError = __('hc.columns_invalid');

            return;
        }
        $this->columnError = null;
        $this->tableColumns = $this->columnsParam($keys);
    }

    public function resetColumns(): void
    {
        $this->columnError = null;
        $this->tableColumns = '';
    }

    /**
     * Header click: none → ascending → descending → none. Without $add the column becomes the only sort;
     * with $add (Shift+click) it is added after, or cycled in place among, the current sorts.
     */
    public function sortBy(string $key, bool $add = false): void
    {
        $field = $this->dataset()->field($key);
        if ($field === null || ! $field->sortable || ! in_array($key, $this->visibleColumns(), true)) {
            return;
        }
        $sort = $this->sortList();
        $current = collect($sort)->firstWhere('field', $key)['dir'] ?? null;
        $next = match ($current) {
            null => 'asc', 'asc' => 'desc', default => null
        };
        if ($add) {
            $sort = array_values(array_filter($sort, fn ($s) => $s['field'] !== $key));
            if ($next !== null) {
                $position = array_search($key, array_column($this->sortList(), 'field'), true);
                $position === false ? $sort[] = ['field' => $key, 'dir' => $next] : array_splice($sort, $position, 0, [['field' => $key, 'dir' => $next]]);
            }
        } else {
            $sort = $next === null ? [] : [['field' => $key, 'dir' => $next]];
        }
        $this->tableSort = $this->sortParam($sort);
        $this->resetTablePage();
    }

    public function updatedPerPage(): void
    {
        $this->perPage = $this->perPage();
        $this->resetTablePage();
    }

    public function perPage(): int
    {
        return in_array($this->perPage, self::PER_PAGE_CHOICES, true) ? $this->perPage : self::PER_PAGE_CHOICES[0];
    }

    /** @return list<array{field: string, dir: string}> the current sort, valid sortable fields only */
    public function sortList(): array
    {
        $out = [];
        foreach ($this->splitParam($this->tableSort) as $item) {
            $dir = str_starts_with($item, '-') ? 'desc' : 'asc';
            $key = ltrim($item, '-');
            $field = $this->dataset()->field($key);
            if ($field !== null && $field->sortable && ! in_array($key, array_column($out, 'field'), true) && count($out) < 3) {
                $out[] = ['field' => $key, 'dir' => $dir];
            }
        }

        return $out;
    }

    /** The bar's filters plus the header sort, as one validated spec. */
    public function tableSpec(): QuerySpec
    {
        return new QuerySpec($this->querySpec()->filters, $this->sortList());
    }

    /** The list query with the shown columns selected and the sort (or the dataset's default order) applied. */
    protected function tableQuery(?Builder $query = null): Builder
    {
        $dataset = $this->dataset();
        $query = $dataset->selectColumns($query ?? $this->filteredQuery(), $this->visibleColumns());

        return $dataset->order($query, new QuerySpec([], $this->sortList()));
    }

    /** What <x-hc.data-table> draws: the shown fields with their sort state. */
    public function dataTable(): array
    {
        $sort = $this->sortList();
        $columns = [];
        foreach ($this->visibleColumns() as $key) {
            $field = $this->dataset()->field($key);
            $index = array_search($key, array_column($sort, 'field'), true);
            $columns[] = ['key' => $key, 'label' => $field->label(), 'field' => $field, 'sortable' => $field->sortable,
                'dir' => $index === false ? null : $sort[$index]['dir'], 'priority' => $index === false || count($sort) < 2 ? null : $index + 1];
        }

        return ['columns' => $columns, 'per_page' => $this->perPage(), 'per_page_choices' => self::PER_PAGE_CHOICES];
    }

    /** What <x-hc.column-picker> draws: the shown columns in order, then the others in registry order. */
    public function columnPicker(): array
    {
        $visible = $this->visibleColumns();
        $items = [];
        foreach ([...$visible, ...array_diff(array_keys($this->dataset()->columns()), $visible)] as $key) {
            $field = $this->dataset()->field($key);
            $items[] = ['key' => $key, 'label' => $field->label(), 'visible' => in_array($key, $visible, true), 'sensitive' => $field->sensitive];
        }

        return ['items' => $items, 'is_default' => $visible === $this->dataset()->defaultColumns(), 'error' => $this->columnError,
            'can_export' => $this->canExportTable()];
    }

    // ---------------------------------------------------------------- saved views (called by HasSmartFilters)

    /** @return array{columns: ?list<string>, sort: list<array{field: string, dir: string}>} columns only when they differ from the defaults */
    public function datasetTableState(): array
    {
        $visible = $this->visibleColumns();

        return ['columns' => $visible === $this->dataset()->defaultColumns() ? null : $visible, 'sort' => $this->sortList()];
    }

    /** Puts a saved view's columns and sort back; returns whether anything stored was left out (unknown now). */
    public function restoreDatasetTable(mixed $columns, mixed $sort): bool
    {
        $dropped = false;
        $keys = is_array($columns) ? $columns : [];
        $clean = $keys === [] ? [] : array_values(array_filter($keys, fn ($k) => is_string($k) && $this->dataset()->columnErrors([$k]) === []));
        $dropped = count($clean) !== count($keys);
        $this->tableColumns = $clean === [] ? '' : $this->columnsParam(array_values(array_unique($clean)));
        $items = [];
        foreach (is_array($sort) ? $sort : [] as $item) {
            $field = is_array($item) && is_string($item['field'] ?? null) ? $this->dataset()->field($item['field']) : null;
            if ($field === null || ! $field->sortable || ! in_array($item['dir'] ?? null, ['asc', 'desc'], true)) {
                $dropped = true;

                continue;
            }
            $items[] = ['field' => $field->key, 'dir' => $item['dir']];
        }
        $this->tableSort = $this->sortParam($items);
        $this->columnError = null;

        return $dropped;
    }

    // ---------------------------------------------------------------- export

    public function canExportTable(): bool
    {
        $account = auth()->user();
        if (! $account instanceof WebAccount) {
            return false;
        }
        $allowed = app(AccountAccess::class)->cities($account, $this->dataset()->exportCapability())->pluck('id')->map(fn ($id) => (int) $id)->all();

        return array_intersect($allowed, app(CityContext::class)->cityIds()) !== [];
    }

    /**
     * Hands the current list (filters, search, columns, sort) to the export route through a single-use token kept
     * server-side for five minutes, so the search term (a name, a national number) never appears in an address.
     */
    public function exportTable(string $format): void
    {
        abort_unless(in_array($format, TableExport::FORMATS, true), 422);
        $account = auth()->user();
        abort_unless($account instanceof WebAccount && $this->canExportTable(), 403);
        $state = [];
        foreach ($this->exportStateProperties() as $property) {
            $state[$property] = $this->{$property};
        }
        $token = Str::random(40);
        Cache::put('dataset-export:'.$token, [
            'account' => $account->id, 'component' => static::class, 'format' => $format,
            'state' => $state, 'columns' => $this->visibleColumns(), 'sort' => $this->sortList(),
        ], now()->addMinutes(5));
        $this->redirect(route($this->exportRoute(), ['locale' => app()->getLocale(), 'token' => $token]));
    }

    /** Export side: puts the list state from a token back on a fresh component (only the listed properties). */
    public function restoreExportState(array $state): void
    {
        foreach ($this->exportStateProperties() as $property) {
            if (array_key_exists($property, $state)) {
                $this->{$property} = $state[$property];
            }
        }
    }

    /** Export side: the filtered list query (no select, no order), as the screen builds it. */
    public function exportQuery(): Builder
    {
        return $this->filteredQuery();
    }

    public function exportDataset(): Dataset
    {
        return $this->dataset();
    }

    // ----------------------------------------------------------------

    private function resetTablePage(): void
    {
        if (method_exists($this, 'resetPage')) {
            $this->resetPage();
        }
    }

    /** @return list<string> */
    private function splitParam(string $value): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $value)), fn ($v) => $v !== ''));
    }

    /** '' when the columns are the defaults, so a plain list keeps a clean address. */
    private function columnsParam(array $keys): string
    {
        return $keys === $this->dataset()->defaultColumns() ? '' : implode(',', $keys);
    }

    private function sortParam(array $sort): string
    {
        return implode(',', array_map(fn ($s) => ($s['dir'] === 'desc' ? '-' : '').$s['field'], $sort));
    }
}
