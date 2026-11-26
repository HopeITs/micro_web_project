<?php

namespace App\Livewire;

use App\Services\People\FamilyService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The families list: one row per household (beneficiary) of the permitted cities with live counts, searched on the server by any
 * member's name, national number or mobile and by the book number, filtered, sorted and paginated. The search text may be a name or a
 * national number, so it stays out of the URL (as on the people list); the URL keeps only city ids and filter codes.
 */
class FamiliesIndex extends Component
{
    use WithPagination;

    /** Never kept in the URL: it may be a national number or a name. */
    public string $search = '';

    /** @var list<int> */
    #[Url(as: 'city', except: [])]
    public array $cities = [];

    #[Url(except: '')]
    public string $quick = '';

    #[Url(except: '')]
    public string $size = '';

    #[Url(except: 'head')]
    public string $sort = 'head';

    #[Url(except: '')]
    public string $dir = '';

    public int $perPage = 20;

    public function updated(string $property): void
    {
        // Another column starts in its own direction.
        $property === 'sort' && $this->dir = '';
        $this->resetPage();
    }

    public function toggleCity(int $id): void
    {
        $this->cities = in_array($id, array_map('intval', $this->cities), true) ? array_values(array_diff(array_map('intval', $this->cities), [$id])) : [...array_map('intval', $this->cities), $id];
        $this->resetPage();
    }

    /** A quick filter is a switch: pressing the active one shows everything again. */
    public function setQuick(string $quick): void
    {
        $this->quick = $quick !== $this->quick && in_array($quick, FamilyService::QUICK, true) ? $quick : '';
        $this->resetPage();
    }

    public function sortBy(string $key): void
    {
        if (! isset(FamilyService::SORTS[$key])) {
            return;
        }
        $current = $this->dir ?: (FamilyService::SORTS[$this->sort] ?? 'asc');
        $this->dir = $this->sort === $key ? ($current === 'asc' ? 'desc' : 'asc') : '';
        $this->sort = $key;
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'cities', 'quick', 'size');
        $this->resetPage();
    }

    public function render(FamilyService $families): View
    {
        $account = auth()->user();
        $ids = $families->cityIds($account, $this->cities);
        $filters = ['search' => $this->search, 'quick' => $this->quick, 'size' => $this->size, 'sort' => $this->sort, 'dir' => $this->dir];

        $totals = $families->totals($ids, $this->search);

        return view('livewire.families-index', [
            'households' => $families->page($ids, $filters, $this->perPage, $totals),
            'totals' => $totals,
            'cityOptions' => DB::table('cities')->whereIn('id', $families->cityIds($account))->orderBy('id')->get(['id', 'code', 'name_ar', 'name_en']),
            'direction' => $this->dir ?: (FamilyService::SORTS[$this->sort] ?? 'asc'),
        ]);
    }
}
