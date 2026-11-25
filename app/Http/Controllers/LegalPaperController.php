<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Papers\DeedPaper;
use App\Services\Papers\SaleContractPaper;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** M4.20 wave 4: the pledge deeds and the sale contracts with their entry pages (the services check the city rights). */
final class LegalPaperController
{
    public function deeds(Request $request, string $locale, int $project, DeedPaper $papers): View
    {
        $page = $papers->page($request->user(), $project);
        $cards = [];
        foreach ($page['deeds'] as $kind => $fields) {
            $route = ['locale' => $locale, 'project' => $project, 'kind' => $kind];
            $cards[] = ['key' => $kind, 'title' => __('paper_entry.deed.'.$kind), 'note' => null, 'fields' => $fields,
                'action' => route('applications.deeds.save', $route), 'links' => [[__('paper_entry.print'), route('applications.deeds.print', $route)]]];
        }

        return view('papers.entry-cards', ['title' => __('paper_entry.deeds_title'), 'intro' => __('paper_entry.deeds_intro'), 'readOnly' => __('paper_entry.read_only_deeds'),
            'number' => $page['number'], 'canSave' => $page['canSave'], 'projectId' => $project, 'cards' => $cards]);
    }

    public function saveDeed(Request $request, string $locale, int $project, string $kind, DeedPaper $papers): RedirectResponse
    {
        $papers->save($request->user(), $project, $kind, $request->all());

        return $request->has('print')
            ? redirect()->route('applications.deeds.print', ['locale' => $locale, 'project' => $project, 'kind' => $kind])
            : redirect()->route('applications.deeds', ['locale' => $locale, 'project' => $project])->with('status', __('paper_entry.saved'));
    }

    public function printDeed(Request $request, string $locale, int $project, string $kind, DeedPaper $papers): View
    {
        return view('papers.deed', ['d' => $papers->forPrint($request->user(), $project, $kind)]);
    }

    public function sales(Request $request, string $locale, int $project, SaleContractPaper $papers): View
    {
        $page = $papers->page($request->user(), $project);
        $cards = [];
        foreach ($page['contracts'] as $kind => $contract) {
            $route = ['locale' => $locale, 'project' => $project, 'kind' => $kind];
            $cards[] = ['key' => $kind, 'title' => __('paper_entry.sale.'.$kind), 'note' => $contract['saved'] ? null : __('paper_entry.suggested'), 'fields' => $contract['fields'],
                'action' => route('applications.sale-contracts.save', $route),
                'links' => array_map(fn ($buyer) => [__('paper_entry.print_'.$buyer), route('applications.sale-contracts.print', $route + ['buyer' => $buyer])], SaleContractPaper::BUYERS)];
        }

        return view('papers.entry-cards', ['title' => __('paper_entry.sales_title'), 'intro' => __('paper_entry.sales_intro'), 'readOnly' => __('paper_entry.read_only_sales'),
            'number' => $page['number'], 'canSave' => $page['canSave'], 'projectId' => $project, 'cards' => $cards]);
    }

    public function saveSale(Request $request, string $locale, int $project, string $kind, SaleContractPaper $papers): RedirectResponse
    {
        $papers->save($request->user(), $project, $kind, $request->all());

        return $request->has('print')
            ? redirect()->route('applications.sale-contracts.print', ['locale' => $locale, 'project' => $project, 'kind' => $kind])
            : redirect()->route('applications.sale-contracts', ['locale' => $locale, 'project' => $project])->with('status', __('paper_entry.saved'));
    }

    public function printSale(Request $request, string $locale, int $project, string $kind, SaleContractPaper $papers): View
    {
        $buyer = (string) $request->query('buyer', SaleContractPaper::BUYERS[0]);

        return view('papers.sale-'.$kind, ['d' => $papers->forPrint($request->user(), $project, $kind, $buyer)]);
    }
}
