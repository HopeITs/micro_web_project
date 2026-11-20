<?php

namespace App\Livewire;

use App\Services\Drafts\ApplicationDraftStore;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

/** "My drafts" on the new-application page (M4.5): resume or discard the account's own unsaved application forms. */
class ApplicationDrafts extends Component
{
    public function discard(string $id): void
    {
        app(ApplicationDraftStore::class)->discard(auth()->user(), $id);
    }

    public function render(): View
    {
        return view('livewire.application-drafts', [
            'drafts' => app(ApplicationDraftStore::class)->listing(auth()->user()),
            'codes' => DB::table('cities')->pluck('code', 'id'),
        ]);
    }
}
