<?php

namespace App\Livewire;

use App\Models\WebAccount;
use App\Services\Ask\AskChips;
use App\Services\Ask\AskLinks;
use App\Services\Ask\AskService;
use App\Services\Search\GlobalSearch;
use App\Support\Datasets\DatasetRegistry;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * F3 "Ctrl+K" search box in the header: people, projects, pages and commands the account may open.
 * The term is component state sent in the Livewire request body, so a name or national number never
 * lands in a URL, the history or a server access log.
 *
 * X1: when the typed text reads as a question with at least one filter ("financed projects in Aleppo"), the first item offers the
 * matching list with those filters. The address holds ids only; the question is recorded (redacted, engine "local") only when the item is chosen.
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

    /** Opens the filtered list the typed question stands for (and records the question in ai_queries, redacted). */
    public function openAsk(AskService $ask): mixed
    {
        app()->setLocale($this->locale);
        $account = auth()->user();
        $found = $account instanceof WebAccount ? $ask->guess($account, $this->term) : null;
        $url = $found === null ? null : AskLinks::listUrl(DatasetRegistry::find($found[0]), $found[1]->filters);
        if ($url === null) {
            return null;
        }
        $ask->ask($account, $found[0], $this->term);

        return $this->redirect($url);
    }

    /** @return array{dataset: string, summary: string}|null */
    private function askOffer(AskService $ask, ?WebAccount $account): ?array
    {
        $term = trim($this->term);
        if ($account === null || mb_strlen($term) < 6 || ! str_contains($term, ' ')) {
            return null;
        }
        $found = $ask->guess($account, $term);
        if ($found === null || $found[1]->filters === []) {
            return null;
        }
        $dataset = DatasetRegistry::find($found[0]);
        if (AskLinks::listUrl($dataset, $found[1]->filters) === null) {
            return null;
        }
        $summary = implode(' · ', array_map(fn ($c) => $c['label'].': '.$c['summary'], AskChips::for($dataset, $found[1]->filters)));

        return ['dataset' => __('ask.list_'.$found[0]), 'summary' => $summary];
    }

    public function render(GlobalSearch $search, AskService $ask): View
    {
        app()->setLocale($this->locale);
        $account = auth()->user();
        $account = $account instanceof WebAccount ? $account : null;
        $results = $account ? $search->search($account, $this->term) : ['people' => [], 'projects' => [], 'pages' => [], 'commands' => []];
        $offer = $this->askOffer($ask, $account);

        return view('livewire.command-palette', ['results' => $results, 'total' => array_sum(array_map('count', $results)) + ($offer ? 1 : 0), 'askOffer' => $offer]);
    }
}
