<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Livewire\PeopleIndex;
use App\Services\Reports\DatasetExport;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Excel/CSV of the people list as shown (columns, filters, search, sort), from the list's single-use export token. */
final class PeopleExportController
{
    public function __invoke(Request $request, string $locale, string $token, DatasetExport $export): StreamedResponse
    {
        return $export->fromToken($request->user(), $token, PeopleIndex::class);
    }
}
