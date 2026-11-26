<?php

namespace App\Livewire;

use App\Models\WebAccount;
use App\Services\Desk\DeskSummary;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * "My desk" (UX F4, X3): the home page of one account. "Waiting on me" tiles and quick actions are drawn at once (a few aggregate
 * queries); the saved views and reports load right after the first paint (`wire:init`), so a slow report never holds the page. The
 * recently opened projects are read from this browser's local storage by the page itself (project ids and numbers only).
 */
class MyDesk extends Component
{
    public bool $cardsLoaded = false;

    public function loadCards(): void
    {
        $this->cardsLoaded = true;
    }

    public function render(DeskSummary $desk): View
    {
        $account = auth()->user();
        abort_unless($account instanceof WebAccount, 403);

        return view('livewire.my-desk', [
            'tiles' => $desk->tiles($account),
            'actions' => $desk->quickActions($account),
            'cards' => $this->cardsLoaded ? $desk->savedCards($account) : null,
            'savedTotal' => $this->cardsLoaded ? $desk->savedTotal($account) : 0,
        ]);
    }
}
