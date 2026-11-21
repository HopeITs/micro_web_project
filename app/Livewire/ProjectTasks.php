<?php

namespace App\Livewire;

use App\Services\Lifecycle\ProjectTaskService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/** The tasks card of the project page (M4.8): the project's study and follow-up tasks, ticked by whoever is responsible for them. */
class ProjectTasks extends Component
{
    #[Locked]
    public int $projectId;

    public function mount(int $projectId): void
    {
        $this->projectId = $projectId;
    }

    public function toggle(int $taskId, bool $done): void
    {
        app(ProjectTaskService::class)->set(auth()->user(), $this->projectId, $taskId, $done);
        // The next-step box reads the tasks too.
        $this->dispatch('project-progress');
    }

    public function render(ProjectTaskService $service): View
    {
        return view('livewire.project-tasks', ['card' => $service->card(auth()->user(), $this->projectId)]);
    }
}
