<?php

namespace App\Livewire;

use App\Livewire\Concerns\ExportsPrivately;
use App\Livewire\Concerns\HasTableSort;
use App\Services\Field\SpecialCaseListService;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The list of all special cases across the permitted cities (M4.13, owner decision 5), with filters, header sorting and the Excel/CSV
 * export. The search may hold a name, so it stays out of the address.
 */
class SpecialCaseList extends Component
{
    use ExportsPrivately;
    use HasTableSort;
    use WithPagination;

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    #[Url]
    public string $type = '';

    #[Url]
    public string $recorder = '';

    #[Url]
    public string $state = '';

    /** Not kept in the address: it may be a person's name. */
    public string $q = '';

    /** @var list<int> */
    #[Url]
    public array $cities = [];

    public function updated(): void
    {
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

    protected function sortableKeys(): array
    {
        return SpecialCaseListService::SORT_KEYS;
    }

    protected function exportTarget(): array
    {
        return ['special-cases', 'special-cases.export'];
    }

    /** @return array<string, mixed> what the screen filters and sorts by */
    private function input(): array
    {
        return ['from' => $this->from, 'to' => $this->to, 'type' => $this->type, 'recorder' => $this->recorder, 'state' => $this->state, 'q' => $this->q, 'cities' => $this->cities]
            + $this->sortFilters();
    }

    protected function exportFilters(): array
    {
        return $this->input();
    }

    public function render(SpecialCaseListService $list): View
    {
        $input = $this->input();
        $this->resetErrorBag();
        try {
            $filters = SpecialCaseListService::clean($input);
        } catch (ValidationException $e) {
            $this->addError('from', $e->errors()['from'][0]);
            // A start after the end shows nothing until it is corrected.
            $filters = SpecialCaseListService::clean(['cities' => $this->cities]) + ['invalid' => true];
        }

        return view('livewire.special-case-list', $list->report(auth()->user(), $filters) + ['filters' => $filters,
            'query' => array_filter($filters, fn ($v) => $v !== null && $v !== [] && $v !== '')]);
    }
}
