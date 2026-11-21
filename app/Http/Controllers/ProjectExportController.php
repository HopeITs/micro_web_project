<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Livewire\ProjectsIndex;
use App\Services\Reports\DatasetExport;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Excel/CSV of the projects list as shown (columns, filters, search, sort), from the list's single-use export token. */
final class ProjectExportController
{
    public function __invoke(Request $request, string $locale, string $token, DatasetExport $export): StreamedResponse
    {
        return $export->fromToken($request->user(), $token, ProjectsIndex::class);
    }
}
