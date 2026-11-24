<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Papers\PaperData;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** M4.20: the details of the disbursement request and the partnership contract of a project (PaperData checks finance.view / finance.manage per city). */
final class PaperDataController
{
    public function show(Request $request, string $locale, int $project, PaperData $data): View
    {
        return view('papers.data', $data->page($request->user(), $project) + ['projectId' => $project]);
    }

    public function save(Request $request, string $locale, int $project, string $form, PaperData $data): RedirectResponse
    {
        $data->save($request->user(), $project, $form, $request->all());
        $paper = $form === 'disbursement' ? 'disbursement-request' : 'partnership-contract';
        if ($request->has('print')) {
            return redirect()->route('applications.finance-paper', ['locale' => $locale, 'project' => $project, 'paper' => $paper]);
        }

        return redirect()->route('applications.paper-data', ['locale' => $locale, 'project' => $project])->with('status', __('papers.data.saved'));
    }
}
