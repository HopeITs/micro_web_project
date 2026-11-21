<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Models\WebAccount;
use App\Services\AccountAccess;
use App\Services\AccountAuditTrail;
use App\Support\CityContext;
use App\Support\Datasets\InvalidQuerySpec;
use App\Support\Datasets\QuerySpec;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The Excel/CSV export of a dataset list (smart-search plan, phase 2), from the single-use token that
 * HasDatasetColumns::exportTable() left server-side: exactly the shown columns in their order, the list's filters
 * and search, its sort, every row (chunked). Rows are limited to the request's permitted cities (CityScope) and,
 * among them, to the cities where the account holds the dataset's export capability. Audited before the first
 * byte as "<dataset>.exported" with the format, the row count, the columns and which of them are personal data.
 */
final class DatasetExport
{
    public function __construct(private AccountAccess $access, private AccountAuditTrail $audit, private TableExport $export) {}

    /** @param  class-string  $component  the Livewire list (using HasDatasetColumns) the token must come from */
    public function fromToken(WebAccount $account, string $token, string $component): StreamedResponse
    {
        $payload = preg_match('/\A[A-Za-z0-9]{40}\z/', $token) === 1 ? Cache::pull('dataset-export:'.$token) : null;
        abort_if(! is_array($payload) || ($payload['account'] ?? null) !== $account->id || ($payload['component'] ?? null) !== $component, 404);

        $list = new $component;
        $list->restoreExportState((array) ($payload['state'] ?? []));
        $dataset = $list->exportDataset();
        try {
            $columns = $dataset->validColumns($payload['columns'] ?? null);
            $spec = QuerySpec::fromArray(['sort' => $payload['sort'] ?? []])->validate($dataset);
        } catch (InvalidQuerySpec) {
            abort(422);
        }

        $allowed = $this->access->cities($account, $dataset->exportCapability())->pluck('id')->map(fn ($id) => (int) $id)->all();
        $cities = array_values(array_intersect($allowed, app(CityContext::class)->cityIds()));
        if ($cities === []) {
            $this->audit->record($account->id, 'access.denied', 'denied');
            abort(403);
        }

        $query = $list->exportQuery()->whereIn($dataset->cityColumn(), $cities);
        $rows = (clone $query)->toBase()->count();
        $fields = array_map(fn ($key) => $dataset->field($key), $columns);
        $sensitive = array_values(array_map(fn ($f) => $f->key, array_filter($fields, fn ($f) => $f->sensitive)));
        $format = TableExport::format($payload['format'] ?? null);
        $this->audit->record($account->id, $dataset->key().'.exported', 'succeeded', decision: implode('/', array_filter([
            'export:'.$dataset->key(), 'format:'.$format, 'rows:'.$rows, 'cols:'.implode('.', $columns),
            $sensitive !== [] ? 'sensitive:'.implode('.', $sensitive) : null,
        ])));

        $query = $dataset->order($dataset->selectColumns($query, $columns), $spec)->toBase();
        $numeric = array_keys(array_filter($fields, fn ($f) => $f->exportsNumber()));
        $generator = (function () use ($query, $fields) {
            foreach ($query->lazy(500) as $row) {
                yield array_map(fn ($f) => $f->cell($row)['export'], $fields);
            }
        })();

        return $this->export->stream($format, $dataset->key().'-'.now()->format('Ymd-His'), array_map(fn ($f) => $f->label(), $fields),
            $generator, array_values($numeric), app()->isLocale('ar'));
    }
}
