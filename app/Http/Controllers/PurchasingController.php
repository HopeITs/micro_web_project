<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Finance\PurchasingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/** M4.10: a project's purchases page (Livewire ProjectPurchases); the service rechecks the capabilities on every action. */
final class PurchasingController
{
    public function show(Request $request, string $locale, int $project, PurchasingService $purchasing): View
    {
        $row = $purchasing->opened($request->user(), $project);

        return view('finance.purchasing', ['project' => $row, 'code' => DB::table('cities')->where('id', $row->city_id)->value('code')]);
    }
}
