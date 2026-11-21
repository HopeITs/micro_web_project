<?php

namespace App\Livewire;

use App\Services\Field\VisitService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/** The visits page of a project (M4.12): opening / closing / rehabilitation cards, the M&E visits per person and the add buttons. */
class ProjectVisits extends Component
{
    #[Locked]
    public int $projectId;

    public function mount(int $projectId): void
    {
        $this->projectId = $projectId;
        app(VisitService::class)->hub(auth()->user(), $projectId);
    }

    public function render(VisitService $visits): View
    {
        return view('livewire.project-visits', ['hub' => $visits->hub(auth()->user(), $this->projectId)]);
    }
}
