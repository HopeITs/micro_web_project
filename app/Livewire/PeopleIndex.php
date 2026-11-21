<?php

namespace App\Livewire;

use App\Livewire\Concerns\HasSmartFilters;
use App\Services\People\PeopleService;
use App\Support\Datasets\Dataset;
use App\Support\Datasets\PeopleDataset;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * People of the permitted cities (M4.3): instant search by name, national number, mobile or family book number (several terms
 * separated by a comma or a new line), the smart filter bar (PeopleDataset fields, multi-select with counts, ranges, saved views)
 * and city chips. Search text may be a name or national number, so it stays out of the URL (UX plan F3); the URL keeps only
 * filter ids, codes, numbers and dates.
 */
class PeopleIndex extends Component
{
    use HasSmartFilters {
        savableViewFilters as smartSavableViewFilters;
        loadViewFilters as smartLoadViewFilters;
    }
    use WithPagination;

    /** Not kept in the URL: it may be a national number or a name (docs/planning/ux-borrowed-features-2026-11.md, F3 security). */
    public string $search = '';

    /** @var list<int> */
    #[Url(as: 'city', except: [])]
    public array $cities = [];

    private ?PeopleDataset $datasetInstance = null;

    protected function dataset(): Dataset
    {
        return $this->datasetInstance ??= new PeopleDataset;
    }

    protected function smartFilterHidden(): array
    {
        return ['city']; // the city chips below the bar
    }

    protected function savedViewShareCapability(): string
    {
        return 'people.view';
    }

    /** Saved views: the city chips are stored as a `city` filter (ids only); the search box is not stored. */
    protected function filtersCameFromUrl(): bool
    {
        return $this->filters !== [] || $this->cities !== [];
    }

    protected function savableViewFilters(): array
    {
        $filters = $this->smartSavableViewFilters();
        $chosen = array_values(array_intersect(array_map('intval', $this->cities), app(PeopleService::class)->cityIds(auth()->user())));
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

    public function updated(string $property): void
    {
        $this->resetPage();
    }

    public function toggleCity(int $id): void
    {
        $this->cities = in_array($id, $this->cities, true) ? array_values(array_diff($this->cities, [$id])) : [...$this->cities, $id];
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'cities');
        $this->clearSmartFilters();
    }

    /** The list query with the search, the city chips and the smart filters; no columns or order. */
    protected function filteredQuery(?string $exceptField = null): Builder
    {
        $dataset = $this->dataset();
        $query = app(PeopleService::class)->listQuery(auth()->user(), $dataset, $this->cities);
        $dataset->search($query, $this->search);

        return $this->applySmartFilters($query, $exceptField);
    }

    public function render(PeopleService $people): View
    {
        $account = auth()->user();

        return view('livewire.people-index', [
            'people' => $people->listPage($this->filteredQuery()),
            'cityOptions' => $people->cities($account),
            'canCreate' => $people->cityIds($account, 'people.manage') !== [],
            'filterBar' => $this->filterBar(),
        ]);
    }
}
