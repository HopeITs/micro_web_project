<?php

namespace App\Livewire\Concerns;

use App\Models\SavedView;
use App\Models\WebAccount;
use App\Services\AccountAccess;
use App\Services\AccountAuditTrail;
use App\Services\Ask\AskService;
use App\Support\Datasets\Dataset;
use App\Support\Datasets\Field;
use App\Support\Datasets\QuerySpec;
use App\Support\Datasets\SearchTerms;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;

/**
 * Smart filter bar for a Livewire list (smart-search plan 2026-11, phase 1), drawn by <x-hc.filter-bar>.
 * One filter per field, kept as field => {op, values}. Filters of option, number, money and date fields hold
 * only ids, codes, numbers and dates and live in the URL; text filters (names, national numbers) never do
 * (F3 security rule) and stay in component state only.
 *
 * The component provides dataset() and filteredQuery($except): its list query with every filter applied except
 * the given field's, which the facet counts use so each value shows how many rows it would add.
 */
trait HasSmartFilters
{
    /** @var array<string, array{op: string, values: list<string|int|null>}> */
    #[Url(as: 'f', except: [])]
    public array $filters = [];

    /** @var array<string, array{op: string, values: list<string>}> */
    public array $textFilters = [];

    public ?string $editingFilter = null;

    abstract protected function dataset(): Dataset;

    abstract protected function filteredQuery(?string $exceptField = null): Builder;

    /** Fields the bar does not offer (e.g. cities, which have their own chips). */
    protected function smartFilterHidden(): array
    {
        return [];
    }

    public function mountHasSmartFilters(): void
    {
        // Whatever came in through the URL is checked against the registry; anything else is dropped.
        $this->filters = $this->sanitizeFilters($this->filters, false);
        if (! $this->filtersCameFromUrl()) {
            $this->applyDefaultView();
        }
    }

    /**
     * The ask box (X1) is offered above the bar on lists that have a search box (projects, people, payments), not on the report builder.
     * Returns the dataset key the box asks about, or null.
     */
    protected function askDataset(): ?string
    {
        return property_exists($this, 'search') ? $this->dataset()->key() : null;
    }

    /**
     * `ask-applied` {dataset, spec} from <livewire:ask-box>: replaces this list's filters with what the question stood for. The spec is
     * checked again like a stored view (unknown fields, text filters, values of cities the viewer may not read are dropped), and nothing
     * happens unless the account may read the dataset. The optional `search` (project numbers) goes to the search box, never to an address.
     */
    #[On('ask-applied')]
    public function applyAsk(string $dataset = '', array $spec = []): void
    {
        $key = $this->askDataset();
        $account = auth()->user();
        if ($key === null || $dataset !== $key || ! $account instanceof WebAccount || ! app(AskService::class)->allowed($account, $key)) {
            return;
        }
        [$filters] = $this->checkedViewFilters($spec);
        $this->textFilters = [];
        $this->editingFilter = null;
        $this->activeViewId = null;
        $this->resetForView();
        $this->loadViewFilters($filters);
        $terms = array_values(array_filter(SearchTerms::split((string) ($spec['search'] ?? '')), fn ($t) => preg_match('/\d{10,}/', $t) !== 1));
        if ($terms !== [] && property_exists($this, 'search')) {
            $this->search = implode(', ', $terms);
        }
        $this->smartFiltersChanged();
    }

    /** The current filters as a validated spec (invalid entries are left out). */
    public function querySpec(): QuerySpec
    {
        $filters = [];
        foreach ([...$this->sanitizeFilters($this->filters, false), ...$this->sanitizeFilters($this->textFilters, true)] as $key => $filter) {
            $filters[] = ['field' => $key, 'op' => $filter['op'], 'values' => $filter['values']];
        }

        return new QuerySpec($filters);
    }

    /** Applies the bar's filters to a query built from the dataset. */
    protected function applySmartFilters(Builder $query, ?string $exceptField = null): Builder
    {
        return $this->querySpec()->apply($query, $this->dataset(), $exceptField !== null ? [$exceptField] : [], false);
    }

    public function hasSmartFilters(): bool
    {
        return $this->querySpec()->filters !== [];
    }

    public function openFilter(string $field): void
    {
        $this->editingFilter = $this->offeredField($field)?->key;
    }

    public function closeFilter(): void
    {
        $this->editingFilter = null;
    }

    public function removeFilter(string $field): void
    {
        unset($this->filters[$field], $this->textFilters[$field]);
        if ($this->editingFilter === $field) {
            $this->editingFilter = null;
        }
        $this->smartFiltersChanged();
    }

    public function clearSmartFilters(): void
    {
        $this->filters = [];
        $this->textFilters = [];
        $this->editingFilter = null;
        $this->activeViewId = null;
        $this->viewNotice = null;
        $this->smartFiltersChanged();
        if ($this->askDataset() !== null) {
            $this->dispatch('ask-cleared', dataset: $this->askDataset());
        }
    }

    /** Ticks or unticks one value of an option field (keeps "in" / "not in" as chosen). */
    public function toggleFilterValue(string $field, string $value): void
    {
        $f = $this->offeredField($field);
        if ($f === null || ! $f->hasOptions() || ! array_key_exists($value, $f->options())) {
            return;
        }
        $current = $this->filters[$f->key] ?? ['op' => 'in', 'values' => []];
        $op = in_array($current['op'], ['in', 'not_in'], true) ? $current['op'] : 'in';
        $values = array_map('strval', $current['values']);
        $values = in_array($value, $values, true) ? array_values(array_diff($values, [$value])) : [...$values, $value];
        $this->put($f, $op, $values);
    }

    /** Option fields: in / not_in keep the ticked values; empty / not_empty need none. */
    public function setFilterOp(string $field, string $op): void
    {
        $f = $this->offeredField($field);
        if ($f === null || ! in_array($op, $f->ops(), true)) {
            return;
        }
        $values = in_array($op, ['empty', 'not_empty'], true) ? [] : array_map('strval', $this->filters[$f->key]['values'] ?? []);
        if (in_array($op, ['in', 'not_in'], true) && $values === []) {
            $this->filters[$f->key] = ['op' => $op, 'values' => []]; // waiting for a value; ignored until one is ticked
            $this->smartFiltersChanged();

            return;
        }
        $this->put($f, $op, $values);
    }

    /** Date, money and number ranges; either bound may be left empty. */
    public function setFilterRange(string $field, ?string $from = null, ?string $to = null): void
    {
        $f = $this->offeredField($field);
        if ($f === null || ! in_array('between', $f->ops(), true)) {
            return;
        }
        $from = trim((string) $from);
        $to = trim((string) $to);
        if ($from === '' && $to === '') {
            $this->removeFilter($f->key);

            return;
        }
        $this->put($f, 'between', [$from, $to]);
    }

    public function setFilterPreset(string $field, string $preset): void
    {
        $f = $this->offeredField($field);
        if ($f !== null && $f->type === 'date' && in_array($preset, QuerySpec::DATE_PRESETS, true)) {
            $this->put($f, 'between', [$preset]);
        }
    }

    /** Text fields: words to look for (Arabic letters folded); a comma or new line separates alternatives. */
    public function setFilterText(string $field, ?string $text = null): void
    {
        $f = $this->offeredField($field);
        if ($f === null || $f->type !== 'text') {
            return;
        }
        $terms = SearchTerms::split((string) $text);
        $terms === [] ? $this->removeFilter($f->key) : $this->put($f, 'contains', $terms);
    }

    /**
     * Rows per value of an option field under every other filter and the search: one GROUP BY on the field's
     * expression, selected and grouped identically (valid under ONLY_FULL_GROUP_BY), on the city-scoped query.
     *
     * @return array<string, int>
     */
    public function facetCounts(string $field): array
    {
        $f = $this->dataset()->field($field);
        if ($f === null || ! $f->hasOptions() || $f->sensitive) {
            return [];
        }
        $expression = DB::raw($f->sql);
        $rows = $this->filteredQuery($f->key)->toBase()->reorder()
            ->select(DB::raw("{$f->sql} as facet_value"))->selectRaw('COUNT(*) as facet_count')
            ->groupBy($expression)->get();

        $counts = [];
        foreach ($rows as $row) {
            if ($row->facet_value !== null) {
                $counts[(string) $row->facet_value] = (int) $row->facet_count;
            }
        }

        return $counts;
    }

    /** Everything <x-hc.filter-bar> draws. */
    public function filterBar(): array
    {
        $offered = $this->offeredFields();
        $active = [...$this->sanitizeFilters($this->filters, false), ...$this->sanitizeFilters($this->textFilters, true)];
        $chips = [];
        foreach ($active as $key => $filter) {
            if (isset($offered[$key])) {
                $chips[] = ['key' => $key, 'label' => $offered[$key]->label(), 'summary' => $this->summary($offered[$key], $filter)];
            }
        }

        $editor = null;
        if ($this->editingFilter !== null && isset($offered[$this->editingFilter])) {
            $f = $offered[$this->editingFilter];
            $current = $this->filters[$f->key] ?? $this->textFilters[$f->key] ?? ['op' => $f->hasOptions() ? 'in' : 'between', 'values' => []];
            $editor = ['key' => $f->key, 'label' => $f->label(), 'type' => $f->type, 'op' => $current['op'], 'values' => array_map('strval', $current['values'])];
            if ($f->hasOptions()) {
                $counts = $this->facetCounts($f->key);
                $editor['options'] = [];
                foreach ($f->options() as $value => $label) {
                    $selected = in_array((string) $value, $editor['values'], true);
                    if (($counts[$value] ?? 0) > 0 || $selected) {
                        $editor['options'][] = ['value' => (string) $value, 'label' => $label, 'count' => $counts[$value] ?? 0, 'selected' => $selected];
                    }
                }
            }
        }

        return [
            'menu' => array_map(fn (Field $f) => ['key' => $f->key, 'label' => $f->label(), 'active' => isset($active[$f->key])], array_values($offered)),
            'chips' => $chips,
            'editor' => $editor,
            'presets' => array_combine(QuerySpec::DATE_PRESETS, array_map(fn ($p) => __('hc.filter_preset_'.$p), QuerySpec::DATE_PRESETS)),
            'views' => $this->savedViewsBar(),
            'ask' => $this->askDataset(),
        ];
    }

    // ---------------------------------------------------------------- saved views (schema v1.5 `saved_views`)

    /**
     * A view keeps only the filters that may sit in the address (field keys, lookup ids, codes, numbers, dates):
     * never the search box, never name or national-number filters. Applying one re-checks every filter against the
     * dataset as the viewer sees it, so a shared view never reaches a city the viewer may not read.
     */
    public ?int $activeViewId = null;

    public ?string $viewNotice = null;

    public ?string $viewError = null;

    protected function savedViewKind(): string
    {
        return 'view';
    }

    /** The capability that lets an account share its views with everyone who opens the same list. */
    protected function savedViewShareCapability(): string
    {
        return 'projects.view';
    }

    /** Whether the address brought filters; when it did not, the account's default view is applied on opening. */
    protected function filtersCameFromUrl(): bool
    {
        return $this->filters !== [];
    }

    /**
     * The current filters a view may store, as QuerySpec filters.
     *
     * @return list<array{field: string, op: string, values: list<mixed>}>
     */
    protected function savableViewFilters(): array
    {
        $out = [];
        foreach ($this->sanitizeFilters($this->filters, false) as $key => $filter) {
            if (! in_array($filter['op'], ['in', 'not_in'], true) || $filter['values'] !== []) {
                $out[] = ['field' => $key, 'op' => $filter['op'], 'values' => $filter['values']];
            }
        }

        return $out;
    }

    /**
     * What else a view stores in its spec besides filters and sort (the report builder keeps its rows, columns and measure here);
     * restoreViewExtra() puts it back, checking it as the viewer sees it now.
     */
    protected function savedViewExtra(): array
    {
        return [];
    }

    protected function restoreViewExtra(array $spec): void {}

    /**
     * Puts a view's checked filters on the component (a list may move some of them, e.g. the city, to its own controls).
     *
     * @param  array<string, array{op: string, values: list<mixed>}>  $filters
     */
    protected function loadViewFilters(array $filters): void
    {
        $this->filters = $filters;
    }

    /** Clears whatever else narrows the list before a view is applied (the search box, other controls). */
    protected function resetForView(): void
    {
        if (property_exists($this, 'search')) {
            $this->search = '';
        }
    }

    public function applyView(int $id): void
    {
        $view = $this->visibleViews()->firstWhere('id', $id);
        abort_if($view === null, 404);
        $this->putView($view);
    }

    public function saveView(?string $name = null): void
    {
        $account = $this->viewAccount();
        $name = $this->viewName($name);
        if ($name === null) {
            return;
        }
        $filters = $this->savableViewFilters();
        $table = $this->viewTable();
        if ($filters === [] && $table['columns'] === null && $table['sort'] === [] && $this->savedViewExtra() === []) {
            $this->viewError = __('hc.view_nothing_to_save');

            return;
        }
        if ($this->ownViews()->where('name', $name)->exists()) {
            $this->viewError = __('hc.view_name_taken');

            return;
        }
        $view = SavedView::create([
            'account_id' => $account->id, 'dataset' => $this->dataset()->key(), 'kind' => $this->savedViewKind(), 'name' => $name,
            'spec' => [...(new QuerySpec($filters, $table['sort']))->validate($this->dataset())->toArray(), ...$this->savedViewExtra()], 'columns' => $table['columns'], 'is_shared' => false, 'is_default' => false,
        ]);
        app(AccountAuditTrail::class)->record($account->id, 'saved_views.created', 'succeeded', decision: 'saved_view:'.$view->id,
            details: [['field' => 'name', 'after' => $name], ['field' => 'dataset', 'after' => $view->dataset]]);
        $this->activeViewId = $view->id;
        $this->viewError = null;
        $this->viewNotice = __('hc.view_saved', ['name' => $name]);
    }

    /** Replaces an own view's filters with the current ones. */
    public function overwriteView(int $id): void
    {
        $view = $this->ownView($id);
        $filters = $this->savableViewFilters();
        $table = $this->viewTable();
        if ($filters === [] && $table['columns'] === null && $table['sort'] === [] && $this->savedViewExtra() === []) {
            $this->viewError = __('hc.view_nothing_to_save');

            return;
        }
        $view->update(['spec' => [...(new QuerySpec($filters, $table['sort']))->validate($this->dataset())->toArray(), ...$this->savedViewExtra()], 'columns' => $table['columns']]);
        $this->activeViewId = $view->id;
        $this->viewError = null;
        $this->viewNotice = __('hc.view_updated', ['name' => $view->name]);
    }

    public function renameView(int $id, ?string $name = null): void
    {
        $view = $this->ownView($id);
        $name = $this->viewName($name);
        if ($name === null) {
            return;
        }
        if ($this->ownViews()->where('name', $name)->whereKeyNot($view->id)->exists()) {
            $this->viewError = __('hc.view_name_taken');

            return;
        }
        $view->update(['name' => $name]);
        $this->viewError = null;
    }

    public function deleteView(int $id): void
    {
        $view = $this->ownView($id);
        $account = $this->viewAccount();
        $name = $view->name;
        $view->delete();
        app(AccountAuditTrail::class)->record($account->id, 'saved_views.deleted', 'succeeded', decision: 'saved_view:'.$id,
            details: [['field' => 'name', 'before' => $name], ['field' => 'dataset', 'before' => $this->dataset()->key()]]);
        if ($this->activeViewId === $id) {
            $this->activeViewId = null;
        }
        $this->viewNotice = __('hc.view_deleted', ['name' => $name]);
    }

    /** Makes an own view the one applied when the list opens without filters, or stops it being that (one per list). */
    public function toggleDefaultView(int $id): void
    {
        $view = $this->ownView($id);
        DB::transaction(function () use ($view) {
            $makeDefault = ! $view->is_default;
            $this->ownViews()->where('is_default', true)->update(['is_default' => false]);
            if ($makeDefault) {
                $view->update(['is_default' => true]);
            }
        });
    }

    public function toggleShareView(int $id): void
    {
        $view = $this->ownView($id);
        abort_unless($view->is_shared || $this->canShareViews(), 403); // un-sharing is always allowed
        $view->update(['is_shared' => ! $view->is_shared]);
    }

    public function canShareViews(): bool
    {
        $account = auth()->user();

        return $account instanceof WebAccount && app(AccountAccess::class)->cities($account, $this->savedViewShareCapability())->isNotEmpty();
    }

    /** @return Collection<int, SavedView> own views first, then the ones others shared */
    protected function visibleViews(): Collection
    {
        $account = auth()->user();
        if (! $account instanceof WebAccount) {
            return new Collection;
        }

        return SavedView::visibleTo($account->id, $this->dataset()->key(), $this->savedViewKind())->with('account:id,username')
            ->orderByRaw('CASE WHEN account_id = ? THEN 0 ELSE 1 END', [$account->id])->orderBy('name')->get();
    }

    private function savedViewsBar(): array
    {
        $account = auth()->user();
        if (! $account instanceof WebAccount) {
            return ['list' => [], 'active' => null, 'can_save' => false, 'can_share' => false, 'notice' => null, 'error' => null];
        }
        $views = $this->visibleViews();
        $current = $this->viewKeyed($this->savableViewFilters());
        $table = $this->viewTable();
        $active = null;
        $list = $views->map(function (SavedView $v) use ($account, $current, $table, &$active) {
            $item = ['id' => $v->id, 'name' => $v->name, 'own' => $v->account_id === $account->id, 'shared' => $v->is_shared,
                'default' => $v->is_default, 'owner' => (string) $v->account?->username, 'active' => $v->id === $this->activeViewId];
            if ($item['active']) {
                $stored = ['columns' => $v->columns ?: null, 'sort' => is_array($v->spec['sort'] ?? null) ? $v->spec['sort'] : []];
                $extra = $this->savedViewExtra();
                $active = $item + ['changed' => $this->checkedViewFilters($v->spec)[0] !== $current || $stored != $table
                    || ($extra !== [] && array_intersect_key((array) $v->spec, $extra) != $extra)];
            }

            return $item;
        })->all();

        $canSave = $current !== [] || $table['columns'] !== null || $table['sort'] !== [] || $this->savedViewExtra() !== [];

        return ['list' => $list, 'active' => $active, 'can_save' => $canSave, 'can_share' => $this->canShareViews(),
            'notice' => $this->viewNotice, 'error' => $this->viewError];
    }

    private function applyDefaultView(): void
    {
        $account = auth()->user();
        if (! $account instanceof WebAccount) {
            return;
        }
        $view = $this->ownViews()->where('is_default', true)->first();
        if ($view !== null) {
            $this->putView($view);
        }
    }

    private function putView(SavedView $view): void
    {
        [$filters, $dropped] = $this->checkedViewFilters($view->spec);
        $this->textFilters = [];
        $this->editingFilter = null;
        $this->resetForView();
        $this->restoreViewExtra(is_array($view->spec) ? $view->spec : []);
        $this->loadViewFilters($filters);
        if (method_exists($this, 'restoreDatasetTable')) {
            // Lists with HasDatasetColumns: the view's columns and sort come back too (unknown keys dropped).
            $dropped = $this->restoreDatasetTable($view->columns, $view->spec['sort'] ?? []) || $dropped;
        }
        $this->smartFiltersChanged();
        $this->activeViewId = $view->id;
        $this->viewNotice = $dropped ? __('hc.view_filters_dropped') : null;
    }

    /**
     * A stored spec checked again against the dataset as the viewer sees it now: unknown or no-longer-valid
     * filters, text filters, and option values the viewer cannot see (another city) are left out.
     *
     * @return array{0: array<string, array{op: string, values: list<mixed>}>, 1: bool} the filters, and whether any were left out
     */
    private function checkedViewFilters(mixed $spec): array
    {
        $items = is_array($spec) && is_array($spec['filters'] ?? null) ? $spec['filters'] : null;
        if ($items === null) {
            return [[], true];
        }
        $dataset = $this->dataset();
        $clean = [];
        $dropped = false;
        foreach ($items as $item) {
            $field = is_array($item) && is_string($item['field'] ?? null) ? $dataset->field($item['field']) : null;
            if ($field === null || $field->type === 'text' || $field->sensitive || isset($clean[$field->key])) {
                $dropped = true;

                continue;
            }
            $op = (string) ($item['op'] ?? '');
            $values = is_array($item['values'] ?? null) ? array_values($item['values']) : [];
            if ($field->hasOptions() && in_array($op, ['in', 'not_in'], true)) {
                $options = $field->options();
                $kept = array_values(array_filter($values, fn ($v) => is_scalar($v) && array_key_exists((string) $v, $options)));
                $dropped = $dropped || count($kept) !== count($values);
                $values = $kept;
                if ($values === []) {
                    continue;
                }
            }
            if (QuerySpec::filterErrors($dataset, ['field' => $field->key, 'op' => $op, 'values' => $values]) !== []) {
                $dropped = true;

                continue;
            }
            $clean[$field->key] = ['op' => $op, 'values' => $values];
        }

        return [$clean, $dropped];
    }

    /**
     * Columns and sort a view stores besides its filters (lists with HasDatasetColumns); none otherwise.
     *
     * @return array{columns: ?list<string>, sort: list<array{field: string, dir: string}>}
     */
    private function viewTable(): array
    {
        return method_exists($this, 'datasetTableState') ? $this->datasetTableState() : ['columns' => null, 'sort' => []];
    }

    /** @return array<string, array{op: string, values: list<mixed>}> */
    private function viewKeyed(array $filters): array
    {
        $out = [];
        foreach ($filters as $f) {
            $out[$f['field']] = ['op' => $f['op'], 'values' => $f['values']];
        }

        return $out;
    }

    private function viewName(?string $name): ?string
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', (string) $name));
        if ($name === '' || mb_strlen($name) > 120) {
            $this->viewError = __('hc.view_name_invalid');

            return null;
        }

        return $name;
    }

    private function viewAccount(): WebAccount
    {
        $account = auth()->user();
        abort_unless($account instanceof WebAccount, 403);

        return $account;
    }

    /** @return Builder<SavedView> */
    private function ownViews(): Builder
    {
        return SavedView::query()->where('account_id', $this->viewAccount()->id)
            ->where('dataset', $this->dataset()->key())->where('kind', $this->savedViewKind());
    }

    private function ownView(int $id): SavedView
    {
        $view = $this->ownViews()->whereKey($id)->first();
        abort_if($view === null, 403);

        return $view;
    }

    /** @return array<string, Field> */
    private function offeredFields(): array
    {
        return array_diff_key($this->dataset()->filterable(), array_flip($this->smartFilterHidden()));
    }

    private function offeredField(string $key): ?Field
    {
        return $this->offeredFields()[$key] ?? null;
    }

    private function put(Field $field, string $op, array $values): void
    {
        $filter = ['op' => $op, 'values' => array_values($values)];
        if ($field->type === 'text' || $field->sensitive) {
            unset($this->filters[$field->key]);
            $this->textFilters[$field->key] = $filter;
        } else {
            unset($this->textFilters[$field->key]);
            if (in_array($op, ['in', 'not_in'], true) && $values === []) {
                unset($this->filters[$field->key]);
            } else {
                $this->filters[$field->key] = $filter;
            }
        }
        $this->smartFiltersChanged();
    }

    private function smartFiltersChanged(): void
    {
        $this->viewNotice = null;
        $this->viewError = null;
        if (method_exists($this, 'resetPage')) {
            $this->resetPage();
        }
    }

    /**
     * Keeps only filters that pass QuerySpec validation and belong in this store: text fields only in the
     * non-URL store, every other type only in the URL store.
     *
     * @return array<string, array{op: string, values: list<mixed>}>
     */
    private function sanitizeFilters(array $filters, bool $text): array
    {
        $clean = [];
        foreach ($filters as $key => $filter) {
            $field = is_string($key) ? $this->dataset()->field($key) : null;
            $belongsHere = ($field?->type === 'text' || $field?->sensitive) === $text;
            if ($field === null || ! $belongsHere || ! is_array($filter)) {
                continue;
            }
            $candidate = ['field' => $key, 'op' => (string) ($filter['op'] ?? ''), 'values' => is_array($filter['values'] ?? null) ? array_values($filter['values']) : []];
            if (QuerySpec::filterErrors($this->dataset(), $candidate) === []) {
                $clean[$key] = ['op' => $candidate['op'], 'values' => $candidate['values']];
            }
        }

        return $clean;
    }

    private function summary(Field $field, array $filter): string
    {
        $values = $filter['values'];

        return match ($filter['op']) {
            'empty' => __('hc.filter_op_empty'),
            'not_empty' => __('hc.filter_op_not_empty'),
            'in', 'not_in' => ($filter['op'] === 'not_in' ? __('hc.filter_not').' ' : '').$this->listSummary($field, $values),
            'contains' => $this->listSummary($field, $values),
            'gt' => '> '.$values[0],
            'lt' => '< '.$values[0],
            'between' => count($values) === 1
                ? __('hc.filter_preset_'.$values[0])
                : (($values[0] ?? '') !== '' && ($values[1] ?? '') !== '' ? $values[0].' – '.$values[1] : (($values[0] ?? '') !== '' ? '≥ '.$values[0] : '≤ '.$values[1])),
            default => '',
        };
    }

    private function listSummary(Field $field, array $values): string
    {
        $options = $field->hasOptions() ? $field->options() : [];
        $labels = array_map(fn ($v) => $options[(string) $v] ?? (string) $v, $values);
        $shown = implode('، ', array_slice($labels, 0, 2));

        return count($labels) > 2 ? $shown.' +'.(count($labels) - 2) : $shown;
    }
}
