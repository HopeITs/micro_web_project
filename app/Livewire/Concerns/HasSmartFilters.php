<?php

namespace App\Livewire\Concerns;

use App\Support\Datasets\Dataset;
use App\Support\Datasets\Field;
use App\Support\Datasets\QuerySpec;
use App\Support\Datasets\SearchTerms;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
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
        $this->smartFiltersChanged();
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
        ];
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
