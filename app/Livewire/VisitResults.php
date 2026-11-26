<?php

namespace App\Livewire;

use App\Livewire\Concerns\ExportsPrivately;
use App\Livewire\Concerns\HasTableSort;
use App\Services\Field\VisitResultsService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** The visit results report (M4.12, owner decision 8): answer counts per question, or the beneficiaries' answers (searched and sorted). */
class VisitResults extends Component
{
    use ExportsPrivately;
    use HasTableSort;
    use WithPagination;

    /** Not kept in the address: it may be a person's name. */
    public string $q = '';

    #[Url]
    public string $view = 'counts';

    #[Url]
    public string $visit = 'M';

    #[Url]
    public string $kind = 'o';

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    #[Url]
    public string $type = '';

    #[Url]
    public string $category = '';

    #[Url]
    public string $subcategory = '';

    #[Url]
    public string $career = '';

    #[Url]
    public bool $last = false;

    /** @var list<int> */
    #[Url]
    public array $cities = [];

    public function updated(string $property): void
    {
        if ($property === 'category') {
            $this->subcategory = '';
            $this->career = '';
        }
        if ($property === 'subcategory') {
            $this->career = '';
        }
        $this->resetPage();
    }

    public function toggleCity(int $city): void
    {
        $chosen = array_map('intval', $this->cities);
        $this->cities = in_array($city, $chosen, true) ? array_values(array_diff($chosen, [$city])) : [...$chosen, $city];
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset();
        $this->resetPage();
    }

    public function filters(): array
    {
        return VisitResultsService::clean(['view' => $this->view, 'visit' => $this->visit, 'kind' => $this->kind, 'from' => $this->from, 'to' => $this->to, 'type' => $this->type,
            'category' => $this->category, 'subcategory' => $this->subcategory, 'career' => $this->career, 'last' => $this->last, 'cities' => $this->cities,
            'q' => $this->q] + $this->sortFilters());
    }

    protected function sortableKeys(): array
    {
        return VisitResultsService::sortKeys($this->visit);
    }

    protected function exportTarget(): array
    {
        return ['visit-results', 'visits.results.export'];
    }

    protected function exportFilters(): array
    {
        return $this->filters();
    }

    public function render(VisitResultsService $results): View
    {
        $filters = $this->filters();

        return view('livewire.visit-results', $results->report(auth()->user(), $filters) + ['filters' => $filters,
            // The export link never carries the search text (a name must not land in an address or an access log).
            'query' => array_filter(array_diff_key($filters, ['q' => true]), fn ($v) => $v !== null && $v !== [] && $v !== false)]);
    }
}
