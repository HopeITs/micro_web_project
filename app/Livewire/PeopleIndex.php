<?php

namespace App\Livewire;

use App\Services\People\PeopleService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** People of the permitted cities (M4.3): instant search by name, national number, mobile or family book number; filters in the URL. */
class PeopleIndex extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    /** @var list<int> */
    #[Url(as: 'city', except: [])]
    public array $cities = [];

    #[Url(except: '')]
    public string $projects = '';

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
        $this->reset('search', 'cities', 'projects');
        $this->resetPage();
    }

    public function render(PeopleService $people): View
    {
        $account = auth()->user();

        return view('livewire.people-index', [
            'people' => $people->search($account, ['q' => $this->search, 'cities' => $this->cities, 'projects' => $this->projects]),
            'cityOptions' => $people->cities($account),
            'canCreate' => $people->cityIds($account, 'people.manage') !== [],
        ]);
    }
}
