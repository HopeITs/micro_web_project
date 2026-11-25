<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Papers\PurchasePaper;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** M4.20 wave 3: the purchasing papers and their entry page (PurchasePaper checks purchasing.view / purchasing.manage per city). */
final class PurchasePaperController
{
    public function print(Request $request, string $locale, int $project, string $paper, PurchasePaper $papers): View
    {
        return view('papers.'.$paper, ['d' => $papers->forPrint($request->user(), $project, $paper)]);
    }

    public function show(Request $request, string $locale, int $project, PurchasePaper $papers): View
    {
        return view('papers.purchase-data', $papers->page($request->user(), $project) + ['projectId' => $project, 'specs' => PurchasePaper::FORMS]);
    }

    public function save(Request $request, string $locale, int $project, string $form, PurchasePaper $papers): RedirectResponse
    {
        $papers->save($request->user(), $project, $form, $request->all());
        if ($request->has('print')) {
            return redirect()->route('applications.purchase-paper', ['locale' => $locale, 'project' => $project, 'paper' => PurchasePaper::FORMS[$form]['paper']]);
        }

        return redirect()->route('applications.purchase-data', ['locale' => $locale, 'project' => $project])->with('status', __('purchase_data.saved'));
    }
}
