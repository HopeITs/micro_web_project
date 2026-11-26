<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Support\ProjectWorkspace;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Projects list (Livewire) and the project page (ProjectWorkspace). Other cities are invisible through CityScope (404). */
class ProjectController
{
    public function index(): View
    {
        return view('projects.index');
    }

    public function show(Request $request, string $locale, int $project, ProjectWorkspace $workspace): View
    {
        $project = Project::with([
            'city', 'career', 'projectCategory', 'projectType', 'donor',
            'projectApplicants' => fn ($q) => $q->orderBy('id'), 'projectApplicants.person',
            'projectStateHistory' => fn ($q) => $q->with('projectState')->orderBy('changed_on')->orderBy('id'),
            'loans' => fn ($q) => $q->orderBy('id'), 'loans.loanInstallments' => fn ($q) => $q->orderBy('due_on')->orderBy('id'),
        ])->findOrFail($project);

        return view('projects.show', ['project' => $project, 'page' => $workspace->build($request->user(), $project)]);
    }
}
