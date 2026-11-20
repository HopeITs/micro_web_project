<?php

namespace App\Livewire;

use App\Services\Search\GlobalSearch;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * F3 "Ctrl+K" search box in the header: people, projects, pages and commands the account may open.
 * The term is component state sent in the Livewire request body, so a name or national number never
 * lands in a URL, the history or a server access log.
 */
class CommandPalette extends Component
{
    public string $term = '';

    #[Locked]
    public string $locale = 'ar';

    public function mount(): void
    {
        $this->locale = app()->getLocale();
    }

    public function updatedTerm(): void
    {
        $this->term = mb_substr($this->term, 0, 100);
    }

    public function render(GlobalSearch $search): View
    {
        app()->setLocale($this->locale);
        $account = auth()->user();
        $results = $account ? $search->search($account, $this->term) : ['people' => [], 'projects' => [], 'pages' => [], 'commands' => []];

        return view('livewire.command-palette', ['results' => $results, 'total' => array_sum(array_map('count', $results))]);
    }
}
