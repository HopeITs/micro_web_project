<?php

namespace App\Livewire;

use App\Services\Search\GlobalSearch;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Route;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * "Add new" from a general list page (owner request, 22 Nov 2026): a button that opens a search by project number or beneficiary name,
 * limited to the cities where the account holds the page's write capability; each result opens the target page of that project
 * (its checklist, its special cases), where the service checks the capability again. The term travels in the Livewire request, not a URL.
 */
class ProjectPicker extends Component
{
    /** Route opened for the chosen project; it takes `project` (and `locale`). */
    #[Locked]
    public string $target = '';

    /** Capability the account must hold in the project's city to see it here. */
    #[Locked]
    public string $capability = '';

    #[Locked]
    public string $label = '';

    /** Extra query added to the target link, e.g. ['new' => 1]. */
    #[Locked]
    public array $query = [];

    public string $term = '';

    public const LIMIT = 12;

    public function mount(string $target, string $capability, string $label, array $query = []): void
    {
        abort_unless(Route::has($target), 500);
        $this->target = $target;
        $this->capability = $capability;
        $this->label = $label;
        $this->query = $query;
    }

    public function render(GlobalSearch $search): View
    {
        $results = $this->term === '' ? [] : $search->projectsFor(auth()->user(), $this->term, $this->capability, self::LIMIT);
        $locale = app()->getLocale();
        foreach ($results as &$item) {
            $item['url'] = route($this->target, ['locale' => $locale, 'project' => $item['project']] + $this->query);
        }

        return view('livewire.project-picker', ['results' => $results]);
    }
}
