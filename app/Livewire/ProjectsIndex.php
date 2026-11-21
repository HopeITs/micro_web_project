<?php

namespace App\Livewire;

use App\Livewire\Concerns\HasSmartFilters;
use App\Models\City;
use App\Support\CityContext;
use App\Support\Datasets\Dataset;
use App\Support\Datasets\ProjectsDataset;
use App\Support\ProjectStatus;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Projects of the permitted cities: instant search (CITY-#### / number / names; several terms separated by a
 * comma or a new line), the smart filter bar (ProjectsDataset fields, multi-select with counts, ranges and
 * relative dates), city chips and count tiles per stage of the application path (F3). The search term can hold
 * a beneficiary's name or national number, so it stays out of the URL (UX plan F3, same rule as the people list);
 * the URL keeps only filter ids, codes, numbers and dates.
 */
class ProjectsIndex extends Component
{
    use HasSmartFilters {
        savableViewFilters as smartSavableViewFilters;
        loadViewFilters as smartLoadViewFilters;
    }
    use WithPagination;

    public string $search = '';

    #[Url(except: '')]
    public string $path = '';

    /** @var list<int> */
    #[Url(as: 'city', except: [])]
    public array $cities = [];

    private ?ProjectsDataset $datasetInstance = null;

    protected function dataset(): Dataset
    {
        return $this->datasetInstance ??= new ProjectsDataset;
    }

    protected function smartFilterHidden(): array
    {
        return ['city']; // the city chips below the bar
    }

    /** Saved views: the city chips are stored as a `city` filter (ids only); the search box and the path tile are not stored. */
    protected function filtersCameFromUrl(): bool
    {
        return $this->filters !== [] || $this->cities !== [] || $this->path !== '';
    }

    protected function savableViewFilters(): array
    {
        $filters = $this->smartSavableViewFilters();
        $chosen = array_values(array_intersect(array_map('intval', $this->cities), app(CityContext::class)->cityIds()));
        if ($chosen !== []) {
            $filters[] = ['field' => 'city', 'op' => 'in', 'values' => array_map('strval', $chosen)];
        }

        return $filters;
    }

    protected function loadViewFilters(array $filters): void
    {
        $city = $filters['city'] ?? null;
        unset($filters['city']);
        $this->cities = $city !== null && $city['op'] === 'in' ? array_map('intval', $city['values']) : [];
        $this->smartLoadViewFilters($filters);
    }

    protected function resetForView(): void
    {
        $this->search = '';
        $this->path = '';
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'path'], true) || str_starts_with($property, 'cities')) {
            $this->resetPage();
        }
    }

    public function togglePath(string $group): void
    {
        $this->path = $this->path === $group || ! array_key_exists($group, ProjectStatus::groups()) ? '' : $group;
        $this->resetPage();
    }

    public function toggleCity(int $id): void
    {
        $this->cities = in_array($id, $this->cities, true)
            ? array_values(array_diff($this->cities, [$id]))
            : [...$this->cities, $id];
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'cities', 'path');
        $this->clearSmartFilters();
    }

    /** The list query with the search, cities, smart filters and (optionally) the path tile; no columns or order. */
    protected function filteredQuery(?string $exceptField = null, bool $withPath = true): Builder
    {
        $dataset = $this->dataset();
        $query = $dataset->query();

        $chosen = array_values(array_intersect(array_map('intval', $this->cities), app(CityContext::class)->cityIds()));
        if ($chosen !== []) {
            $query->whereIn('projects.city_id', $chosen);
        }
        $groups = ProjectStatus::groups();
        if ($withPath && isset($groups[$this->path])) {
            $query->whereIn('st.name_en', $groups[$this->path]);
        }
        $dataset->search($query, $this->search);

        return $this->applySmartFilters($query, $exceptField);
    }

    private function query(): Builder
    {
        return $this->filteredQuery()
            ->select('projects.id', 'projects.project_number', 'projects.name', 'projects.application_stage', 'projects.requested_amount', 'projects.request_date',
                'c.code as city_code', 'c.name_ar as city_ar', 'c.name_en as city_en', 'p.first_name', 'p.father_name', 'p.last_name',
                'st.name_ar as state_ar', 'st.name_en as state_en')
            ->orderByDesc('projects.request_date')->orderBy('c.code')->orderByDesc('projects.project_number');
    }

    /** @return array<string, int> group => projects matching every other filter */
    private function pathCounts(): array
    {
        $byState = $this->filteredQuery(null, false)->toBase()->select('st.name_en')->selectRaw('COUNT(*) as n')->groupBy('st.name_en')->pluck('n', 'name_en');

        return array_map(fn (array $states) => (int) collect($states)->sum(fn ($state) => $byState[$state] ?? 0), ProjectStatus::groups());
    }

    public function render(): View
    {
        $permitted = app(CityContext::class)->cityIds();

        return view('livewire.projects-index', [
            'projects' => $this->query()->paginate(15),
            'pathCounts' => $this->pathCounts(),
            'cityOptions' => City::whereIn('id', $permitted)->orderBy('id')->get(['id', 'code', 'name_ar', 'name_en']),
            'filterBar' => $this->filterBar(),
        ]);
    }
}
