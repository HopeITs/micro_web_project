<?php

namespace App\Http\Controllers;

use App\Models\Project;
use Illuminate\View\View;

/** Projects list (Livewire) and a read-only project page. Other cities are invisible through CityScope (404). */
class ProjectController
{
    public function index(): View
    {
        return view('projects.index');
    }

    public function show(string $locale, int $project): View
    {
        $project = Project::with([
            'city', 'career', 'projectCategory', 'projectType', 'donor', 'street',
            'projectApplicants.person.maritalStatus',
            'projectStateHistory' => fn ($q) => $q->with(['projectState', 'stateReason'])->orderBy('changed_on')->orderBy('id'),
            'loans.loanInstallments' => fn ($q) => $q->orderBy('due_on'),
        ])->findOrFail($project);

        return view('projects.show', ['project' => $project]);
    }
}
