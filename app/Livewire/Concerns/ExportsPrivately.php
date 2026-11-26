<?php

namespace App\Livewire\Concerns;

use App\Services\Reports\TableExport;
use App\Support\TableTools\ExportSnapshot;

/**
 * The Excel/CSV export of a list while its search box holds text: the filters travel through a single-use server-side token
 * (ExportSnapshot) instead of the address. Without a search the views keep their ordinary export links.
 */
trait ExportsPrivately
{
    /** @return array{0: string, 1: string} the list's key and the name of its export route */
    abstract protected function exportTarget(): array;

    /** @return array<string, mixed> the filters as the screen uses them */
    abstract protected function exportFilters(): array;

    public function exportList(string $format): void
    {
        abort_unless(in_array($format, TableExport::FORMATS, true), 422);
        [$list, $route] = $this->exportTarget();
        $token = ExportSnapshot::put((int) auth()->id(), $list, $this->exportFilters());
        $this->redirect(route($route, ['locale' => app()->getLocale(), ExportSnapshot::PARAMETER => $token, 'format' => $format]));
    }
}
