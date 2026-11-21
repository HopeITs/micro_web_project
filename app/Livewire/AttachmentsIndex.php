<?php

namespace App\Livewire;

use App\Services\Attachments\AttachmentService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** The attachments page (M4.11): every file the account may see across its cities, newest first, with search and filters. */
class AttachmentsIndex extends Component
{
    use WithPagination;

    public string $search = '';

    /** @var list<int> */
    #[Url(as: 'city', except: [])]
    public array $cities = [];

    #[Url(except: '')]
    public string $category = '';

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
        $this->reset('search', 'cities', 'category');
        $this->resetPage();
    }

    public function render(AttachmentService $service): View
    {
        $account = auth()->user();
        $categories = $service->categoryOptions($account);
        $category = in_array($this->category, $categories, true) ? $this->category : '';

        return view('livewire.attachments-index', [
            'rows' => $service->search($account, ['q' => $this->search, 'cities' => array_map('intval', $this->cities), 'category' => $category]),
            'cityOptions' => $service->cityOptions($account), 'categoryOptions' => $categories,
        ]);
    }
}
