<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Assessments\ChecklistListService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** The general checklist page: every recorded checklist of the permitted cities, and the "add new" picker for a project without one. */
final class ChecklistListController
{
    public function index(Request $request, string $locale, ChecklistListService $list): View
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'cities' => ['sometimes', 'array', 'max:20'], 'cities.*' => ['integer', 'min:1']]);
        $list->opened($request->user());

        return view('checklists.index', $list->page($request->user(), $filters) + ['filters' => $filters]);
    }
}
