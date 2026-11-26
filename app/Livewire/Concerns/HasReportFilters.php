<?php

namespace App\Livewire\Concerns;

use App\Services\Reports\ProjectReportFilters;
use App\Services\Reports\ReportFacets;
use App\Services\Reports\ReportFilterFields;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;

/**
 * The filter bar of the search and statistics pages (<x-hc.report-filters>): a chip per filter in use, a list to add more, and one open
 * editor at a time (a searchable checklist with the records each value would give, "is one of" / "is none of", dates with ready periods,
 * amount ranges). The filters and cities live in the address as lookup ids, years, dates and numbers only; every value set from the page
 * is checked here again, and free text never comes near them.
 *
 * The component says which kind of filters it takes, which cities the account may read, and how to count one filter's values.
 */
trait HasReportFilters
{
    /** @var list<int> */
    #[Url(as: 'city', except: [])]
    public array $cities = [];

    /** @var array<string, mixed> */
    #[Url(as: 'f', except: [])]
    public array $filters = [];

    /** The field whose editor is open. */
    public ?string $editing = null;

    /** The open list editor is in "is none of" mode (kept before any value is ticked). */
    public bool $excluding = false;

    public ?string $filterNotice = null;

    /** @var list<int>|null */
    private ?array $permittedMemo = null;

    /** project | meeting | none */
    abstract protected function reportFilterKind(): string;

    /** @return list<int> the ids of the cities the account may read on this page */
    abstract protected function permittedCityIds(): array;

    /** @return array<int|string, int> records per value of one filter under all the others */
    abstract protected function facetCountsFor(string $field): array;

    /** Runs after any filter or city changes (a list goes back to its first page). */
    protected function filtersChanged(): void {}

    private function permittedCities(): array
    {
        return $this->permittedMemo ??= $this->permittedCityIds();
    }

    /** The cities the page reads: the chosen ones among the permitted, else all permitted. @return list<int> */
    protected function scopeCityIds(): array
    {
        $chosen = array_values(array_intersect(array_map('intval', array_filter($this->cities, 'is_scalar')), $this->permittedCities()));

        return $chosen !== [] ? $chosen : $this->permittedCities();
    }

    /** @return array<string, mixed> the filters worth keeping (cleaned, nothing empty, no text) */
    protected function keptFilters(): array
    {
        return array_filter(ProjectReportFilters::clean($this->filters), fn ($v, $k) => $k !== 'q' && $v !== [] && $v !== '', ARRAY_FILTER_USE_BOTH);
    }

    private function offered(string $field, ?string $type = null): bool
    {
        $found = ReportFilterFields::for($this->reportFilterKind())[$field] ?? null;

        return $found !== null && ($type === null || $found[0] === $type);
    }

    private function changed(array $filters): void
    {
        $this->filters = $filters;
        $this->filters = $this->keptFilters();
        $this->filterNotice = null;
        $this->filtersChanged();
    }

    public function openFilter(string $field): void
    {
        if (! $this->offered($field)) {
            return;
        }
        $this->editing = $field;
        $this->excluding = in_array($field, $this->keptFilters()['not'] ?? [], true);
        $this->filterNotice = null;
    }

    public function closeFilter(): void
    {
        $this->editing = null;
        $this->filterNotice = null;
    }

    public function toggleValue(string $field, string $value): void
    {
        if (! $this->offered($field, 'list')) {
            return;
        }
        $current = array_map('strval', $this->keptFilters()[$field] ?? []);
        $this->setValues($field, in_array($value, $current, true) ? array_values(array_diff($current, [$value])) : [...$current, $value]);
    }

    /** The ticked values of a list filter, replaced as a whole (select all shown, invert, clear). */
    public function setValues(string $field, array $values): void
    {
        if (! $this->offered($field, 'list')) {
            return;
        }
        $filters = $this->keptFilters();
        $values = array_values(array_unique(array_map('strval', array_filter($values, 'is_scalar'))));
        // Only ids (and years) that exist may be added; one already ticked may always stay.
        $allowed = [...array_map('strval', $filters[$field] ?? []), ...$this->knownValues($field, $values)];
        $values = array_values(array_intersect($values, $allowed));
        if (count($values) > ProjectReportFilters::MAX_VALUES) {
            $this->filterNotice = __('search.ui.too_many', ['max' => ProjectReportFilters::MAX_VALUES]);

            return;
        }
        // The open editor's mode applies to its own field; any other field keeps the way it was matched.
        $exclude = $this->editing === $field ? $this->excluding : in_array($field, $filters['not'] ?? [], true);
        $filters[$field] = $values;
        $not = array_values(array_diff($filters['not'] ?? [], [$field]));
        $filters['not'] = $exclude && $values !== [] ? [...$not, $field] : $not;
        $this->changed($filters);
    }

    public function setExclude(string $field, bool $exclude): void
    {
        if (! $this->offered($field, 'list')) {
            return;
        }
        if ($this->editing === $field) {
            $this->excluding = $exclude;
        }
        $filters = $this->keptFilters();
        if (($filters[$field] ?? []) === []) {
            return;
        }
        $not = array_values(array_diff($filters['not'] ?? [], [$field]));
        $filters['not'] = $exclude ? [...$not, $field] : $not;
        $this->changed($filters);
    }

    /** A date or amount range; either end may be left empty. */
    public function setRange(string $field, ?string $from = null, ?string $to = null): void
    {
        if (! $this->offered($field, 'date') && ! $this->offered($field, 'amount')) {
            return;
        }
        $filters = $this->keptFilters();
        $filters[$field.'_from'] = trim((string) $from);
        $filters[$field.'_to'] = trim((string) $to);
        $this->changed($filters);
        $kept = $this->filters;
        $refused = (trim((string) $from) !== '' && ! isset($kept[$field.'_from'])) || (trim((string) $to) !== '' && ! isset($kept[$field.'_to']));
        $backwards = isset($kept[$field.'_from'], $kept[$field.'_to']) && ($this->offered($field, 'date')
            ? $kept[$field.'_from'] > $kept[$field.'_to'] : (float) $kept[$field.'_from'] > (float) $kept[$field.'_to']);
        $this->filterNotice = $refused ? __('search.ui.bad_value') : ($backwards ? __('search.ui.backwards') : null);
    }

    public function setPreset(string $field, string $preset): void
    {
        $range = $this->offered($field, 'date') ? ReportFilterFields::preset($preset) : null;
        if ($range !== null) {
            $this->setRange($field, $range[0], $range[1]);
        }
    }

    public function setChoice(string $field, string $value): void
    {
        if (! $this->offered($field, 'choice')) {
            return;
        }
        $this->changed([$field => $value] + $this->keptFilters());
    }

    public function removeFilter(string $field): void
    {
        if (! $this->offered($field)) {
            return;
        }
        $filters = $this->keptFilters();
        foreach (ReportFilterFields::keys($field) as $key) {
            unset($filters[$key]);
        }
        $filters['not'] = array_values(array_diff($filters['not'] ?? [], [$field]));
        if ($this->editing === $field) {
            $this->editing = null;
        }
        $this->changed($filters);
    }

    public function clearFilters(): void
    {
        $this->editing = null;
        $this->changed([]);
    }

    public function toggleCity(int $city): void
    {
        if (! in_array($city, $this->permittedCities(), true)) {
            return;
        }
        $this->cities = in_array($city, $this->cities, true) ? array_values(array_diff($this->cities, [$city])) : [...$this->cities, $city];
        $this->filtersChanged();
    }

    /** The values among these that exist for the field (lookup ids, per city for streets; years; the two sexes). @return list<string> */
    protected function knownValues(string $field, array $values): array
    {
        if ($values === []) {
            return [];
        }
        if ($field === 'sex') {
            return array_values(array_intersect($values, ['male', 'female']));
        }
        if (in_array($field, ProjectReportFilters::YEARS, true)) {
            return array_values(array_intersect($values, array_map('strval', app(ReportFacets::class)->years($this->permittedCities())[$field])));
        }
        $ids = array_values(array_filter($values, fn ($v) => ctype_digit($v)));

        return array_map(fn ($row) => (string) $row['id'], $this->lookupRows($field, $ids));
    }

    /** @return list<array{id: int, label: string}> the named rows of a lookup (active or not), for ids that exist */
    private function lookupRows(string $field, array $ids): array
    {
        $table = match (true) {
            $field === 'status' => 'project_states',
            $field === 'age' => 'age_ranges',
            default => (ProjectReportFilters::PROJECT_LOOKUPS + ProjectReportFilters::PERSON_LOOKUPS)[$field][0] ?? null,
        };
        if ($table === null || $ids === []) {
            return [];
        }
        $en = app()->getLocale() === 'en';

        return DB::table($table)->whereIn('id', array_slice($ids, 0, 1000))->when($table === 'streets', fn ($q) => $q->whereIn('city_id', $this->permittedCities() ?: [0]))->get()
            ->map(fn ($r) => ['id' => (int) $r->id, 'label' => (string) ($en && ($r->name_en ?? '') !== '' ? $r->name_en : $r->name_ar)])->all();
    }

    /**
     * The choices of the given list fields; a ticked value that is no longer offered (a retired entry) keeps its name.
     *
     * @param  list<string>  $fields
     * @return array<string, list<array{id: int|string, label: string}>>
     */
    private function optionsFor(array $fields, array $filters): array
    {
        if ($fields === []) {
            return [];
        }
        $options = ProjectReportFilters::options($this->scopeCityIds(), $fields);
        if (array_intersect($fields, ProjectReportFilters::YEARS) !== []) {
            $years = app(ReportFacets::class)->years($this->scopeCityIds());
            foreach (array_intersect($fields, ProjectReportFilters::YEARS) as $field) {
                $all = array_unique([...$years[$field], ...($filters[$field] ?? [])]);
                rsort($all);
                $options[$field] = array_map(fn ($y) => ['id' => $y, 'label' => (string) $y], $all);
            }
        }
        foreach ($fields as $field) {
            $missing = array_diff(array_map('strval', $filters[$field] ?? []), array_map(fn ($o) => (string) $o['id'], $options[$field] ?? []));
            if ($missing !== []) {
                array_push($options[$field], ...$this->lookupRows($field, array_values($missing)));
            }
        }

        return $options;
    }

    /** Everything <x-hc.report-filters> draws. */
    protected function reportFilterBar(): array
    {
        $kind = $this->reportFilterKind();
        $fields = ReportFilterFields::for($kind);
        $filters = $this->keptFilters();
        $not = $filters['not'] ?? [];
        $pinned = ReportFilterFields::pinned($kind);
        $editing = $this->editing !== null && isset($fields[$this->editing]) ? $this->editing : null;
        $active = array_keys(array_filter($fields, fn ($def, $key) => array_intersect_key($filters, array_flip(ReportFilterFields::keys($key))) !== [], ARRAY_FILTER_USE_BOTH));
        $lists = array_values(array_filter(array_keys($fields), fn ($key) => $fields[$key][0] === 'list' && ($key === $editing || in_array($key, $active, true))));
        $options = $this->optionsFor($lists, $filters);

        $chips = [];
        $menu = [];
        foreach ($fields as $key => [$type, $group]) {
            $on = in_array($key, $active, true);
            $label = __('search.f.'.$key);
            $menu[$group][] = ['key' => $key, 'label' => $label, 'active' => $on];
            if ($on || $key === $editing || in_array($key, $pinned, true)) {
                $chips[] = ['key' => $key, 'label' => $label, 'active' => $on, 'editing' => $key === $editing, 'excluded' => in_array($key, $not, true),
                    'summary' => $on ? $this->filterSummary($key, $type, $filters, $options[$key] ?? []) : ''];
            }
        }

        $editor = null;
        if ($editing !== null) {
            [$type] = $fields[$editing];
            $editor = ['key' => $editing, 'label' => __('search.f.'.$editing), 'type' => $type, 'active' => in_array($editing, $active, true)];
            if ($type === 'list') {
                $counts = $this->facetCountsFor($editing);
                $selected = array_map('strval', $filters[$editing] ?? []);
                $listed = $options[$editing] ?? [];
                $unlisted = array_diff(array_map('strval', array_keys($counts)), array_map(fn ($o) => (string) $o['id'], $listed));
                array_push($listed, ...$this->lookupRows($editing, array_values($unlisted)));
                $editor['exclude'] = $this->excluding;
                $editor['selected'] = $selected;
                $editor['options'] = array_map(fn ($o) => ['value' => (string) $o['id'], 'label' => $o['label'], 'count' => $counts[(string) $o['id']] ?? 0,
                    'selected' => in_array((string) $o['id'], $selected, true)], $listed);
            } elseif ($type === 'choice') {
                $counts = $this->facetCountsFor($editing);
                $value = $filters[$editing] ?? '';
                $editor['choices'] = [
                    ['value' => '', 'label' => __('search.all'), 'count' => array_sum($counts), 'selected' => $value === ''],
                    ['value' => 'individual', 'label' => __('search.f.individual'), 'count' => $counts['individual'] ?? 0, 'selected' => $value === 'individual'],
                    ['value' => 'partnership', 'label' => __('search.f.partnered'), 'count' => $counts['partnership'] ?? 0, 'selected' => $value === 'partnership'],
                ];
            } else {
                $editor['from'] = $filters[$editing.'_from'] ?? '';
                $editor['to'] = $filters[$editing.'_to'] ?? '';
                if ($type === 'date') {
                    $current = $editor['from'] !== '' && $editor['to'] !== '' ? ReportFilterFields::presetOf($editor['from'], $editor['to']) : null;
                    $editor['presets'] = array_map(fn ($p) => ['value' => $p, 'label' => __('search.ui.preset.'.$p), 'selected' => $p === $current], ReportFilterFields::PRESETS);
                }
            }
        }

        return ['chips' => $chips, 'menu' => $menu, 'editor' => $editor, 'count' => count($active), 'notice' => $this->filterNotice, 'pinned' => $pinned];
    }

    /** One line saying what a filter in use asks for. */
    private function filterSummary(string $key, string $type, array $filters, array $options): string
    {
        if ($type === 'choice') {
            return __('search.f.'.($filters[$key] === 'individual' ? 'individual' : 'partnered'));
        }
        if ($type === 'list') {
            $names = [];
            foreach ($options as $option) {
                $names[(string) $option['id']] = $option['label'];
            }
            $labels = array_map(fn ($v) => $names[(string) $v] ?? '#'.$v, $filters[$key]);
            $text = implode(__('search.ui.separator'), array_slice($labels, 0, 2)).(count($labels) > 2 ? ' +'.(count($labels) - 2) : '');

            return in_array($key, $filters['not'] ?? [], true) ? __('search.ui.except', ['values' => $text]) : $text;
        }
        $from = $filters[$key.'_from'] ?? '';
        $to = $filters[$key.'_to'] ?? '';
        if ($type === 'date' && $from !== '' && $to !== '' && ($preset = ReportFilterFields::presetOf($from, $to)) !== null) {
            return __('search.ui.preset.'.$preset);
        }
        if ($type === 'amount') {
            $format = fn (string $v) => $v === '' ? '' : rtrim(rtrim(number_format((float) $v, 2), '0'), '.');
            [$from, $to] = [$format($from), $format($to)];
        }

        return match (true) {
            $from !== '' && $to !== '' => __('search.ui.between', ['from' => $from, 'to' => $to]),
            $from !== '' => __('search.ui.from_only', ['from' => $from]),
            default => __('search.ui.to_only', ['to' => $to]),
        };
    }

    /** The filters in use as sentences, for the printed page. @return list<array{label: string, summary: string}> */
    protected function filterSentences(array $bar): array
    {
        return array_values(array_map(fn ($chip) => ['label' => $chip['label'], 'summary' => $chip['summary']], array_filter($bar['chips'], fn ($chip) => $chip['active'])));
    }
}
