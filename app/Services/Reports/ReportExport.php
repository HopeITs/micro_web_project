<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Models\WebAccount;
use App\Services\AccountAccess;
use App\Services\AccountAuditTrail;
use App\Support\Datasets\DatasetRegistry;
use App\Support\Datasets\InvalidQuerySpec;
use App\Support\Datasets\ReportSpec;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The Excel/CSV of a pivot report, from the single-use token ReportBuilder::exportReport() left server-side for five minutes
 * (the filters never travel in an address). The report is run again, covering only the cities where the account reads the
 * dataset AND holds its export capability (reports.export; finance.export for instalments), and audited "reports.exported"
 * with the dataset, format and number of rows (never values) before the first byte.
 *
 * One sheet: [currency,] row label, one column per column value, Total; a Total row closes each currency's block.
 */
final class ReportExport
{
    public const TOKEN_PREFIX = 'report-export:';

    public function __construct(private AccountAccess $access, private AccountAuditTrail $audit, private ReportQuery $query, private TableExport $export) {}

    /** Whether the account may export a report of the dataset (some city holds both the view and the export capabilities). */
    public function allowed(WebAccount $account, string $datasetKey): bool
    {
        $dataset = DatasetRegistry::find($datasetKey);

        return $dataset !== null && $this->query->cityIds($account, $dataset, [$dataset->exportCapability()]) !== [];
    }

    public function fromToken(WebAccount $account, string $token): StreamedResponse
    {
        $payload = preg_match('/\A[A-Za-z0-9]{40}\z/', $token) === 1 ? Cache::pull(self::TOKEN_PREFIX.$token) : null;
        abort_if(! is_array($payload) || ($payload['account'] ?? null) !== $account->id, 404);

        $dataset = DatasetRegistry::find((string) ($payload['dataset'] ?? ''));
        abort_if($dataset === null, 404);
        try {
            $spec = ReportSpec::fromArray((array) ($payload['spec'] ?? []))->validate($dataset);
        } catch (InvalidQuerySpec) {
            abort(422);
        }
        if (! $this->allowed($account, $dataset->key())) {
            $this->audit->record($account->id, 'access.denied', 'denied');
            abort(403);
        }

        $result = $this->query->run($account, $dataset, $spec, [$dataset->exportCapability()]);
        [$headings, $rows, $numeric] = $this->table($result);
        $format = TableExport::format($payload['format'] ?? null);
        $this->audit->record($account->id, 'reports.exported', 'succeeded', decision: implode('/', [
            'export:report', 'ds:'.$dataset->key(), 'format:'.$format, 'rows:'.count($rows),
        ]));

        return $this->export->stream($format, 'report-'.$dataset->key().'-'.now()->format('Ymd-His'), $headings, $rows, $numeric, app()->isLocale('ar'));
    }

    /** @return array{0: list<string>, 1: list<list<string|float|null>>, 2: list<int>} headings, rows, zero-based numeric columns */
    public function table(ReportResult $result): array
    {
        $spec = $result->spec;
        $byCurrency = $result->byCurrency();
        $colKeys = [];
        foreach (array_keys($result->colLabels) as $key) {
            $colKeys[] = (string) $key;
        }
        $headings = [];
        if ($byCurrency) {
            $headings[] = $result->dataset->field((string) $result->dataset->currencyField())->label();
        }
        $headings[] = $result->dataset->field($spec->rows)->label();
        if ($result->hasColumns) {
            foreach ($colKeys as $key) {
                $headings[] = (string) $result->colLabels[$key];
            }
            $headings[] = __('report_builder.total');
        } else {
            $headings[] = $result->measureLabel();
        }
        $lead = $byCurrency ? 1 : 0;
        $numeric = range($lead + 1, count($headings) - 1);

        $rows = [];
        foreach (array_keys($result->slices) as $i) {
            $pivot = $result->pivot($i, true);
            $prefix = $byCurrency ? [$pivot['currency']] : [];
            foreach ($pivot['rows'] as $row) {
                $values = $result->hasColumns ? [...array_map(fn ($c) => self::number($row['cells'][$c] ?? null), $colKeys), self::number($row['total'])] : [self::number($row['total'])];
                $rows[] = [...$prefix, $row['label'], ...$values];
            }
            $values = $result->hasColumns ? [...array_map(fn ($c) => self::number($pivot['colTotals'][$c] ?? null), $colKeys), self::number($pivot['grand'])] : [self::number($pivot['grand'])];
            $rows[] = [...$prefix, __('report_builder.total'), ...$values];
        }

        return [$headings, $rows, $numeric];
    }

    private static function number(?float $value): ?string
    {
        return $value === null ? null : (string) round($value, 2);
    }
}
