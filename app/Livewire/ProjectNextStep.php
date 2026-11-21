<?php

namespace App\Livewire;

use App\Services\Lifecycle\LifecycleService;
use App\Services\Lifecycle\NextStep;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/** The "next step" box under the stage bar (UX F2); refreshed when a task is ticked. */
class ProjectNextStep extends Component
{
    #[Locked]
    public int $projectId;

    public function mount(int $projectId): void
    {
        $this->projectId = $projectId;
    }

    #[On('project-progress')]
    public function refresh(): void {}

    public function render(LifecycleService $lifecycle, NextStep $next): View
    {
        $project = $lifecycle->project(auth()->user(), $this->projectId);

        return view('livewire.project-next-step', ['step' => $next->for(auth()->user(), $project, $lifecycle->currentState($this->projectId))]);
    }
}
