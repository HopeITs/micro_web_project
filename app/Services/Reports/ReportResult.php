<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Support\Datasets\Dataset;
use App\Support\Datasets\ReportSpec;

/**
 * The answer of ReportQuery: one slice per currency (a single slice, currency null, when no money is measured), each holding
 * the aggregates of every (row, column) cell. Cell, row, column and grand values are all derived from the aggregates, so
 * totals are exact for every measure (an average is total sum / total count, not an average of averages).
 *
 * Keys are the raw group values as strings ("" = not set; a lookup id; an enum code; a year "2026" or month "2026-03").
 */
final class ReportResult
{
    /**
     * @param  list<array{currency: ?string, rows: list<string>, cols: list<string>, cells: array<string, array<string, array{n: int, d: int, s: float, c: int, min: ?float, max: ?float}>>}>  $slices
     * @param  array<string, string>  $rowLabels  every row key => label, in order
     * @param  array<string, string>  $colLabels  every column key => label, in order
     */
    public function __construct(
        public readonly ReportSpec $spec,
        public readonly Dataset $dataset,
        public readonly array $slices,
        public readonly array $rowLabels,
        public readonly array $colLabels,
        public readonly bool $hasColumns,
    ) {}

    public function isEmpty(): bool
    {
        return $this->slices === [];
    }

    public function byCurrency(): bool
    {
        return $this->slices !== [] && $this->slices[0]['currency'] !== null;
    }

    public function isPercent(): bool
    {
        return $this->spec->measure === 'share';
    }

    /** Money measures keep two decimals; counts, shares and plain numbers are shown by the view. */
    public function isMoney(): bool
    {
        return $this->spec->measureField !== null && $this->dataset->field($this->spec->measureField)?->type === 'money';
    }

    /**
     * The pivot of one slice: rows (each with its cells by column key and its total), the column totals and the grand total.
     * At most ReportQuery::MAX_ROWS rows and MAX_COLUMNS columns are listed (`truncated`), but totals always cover everything.
     *
     * @return array{currency: ?string, cols: list<array{key: string, label: string}>, rows: list<array{key: string, label: string, cells: array<string, ?float>, total: ?float}>, colTotals: array<string, ?float>, grand: ?float, truncated: bool}
     */
    public function pivot(int $slice = 0, bool $all = false): array
    {
        $data = $this->slices[$slice];
        $grandAgg = null;
        $colAgg = [];
        $rowAgg = [];
        foreach ($data['cells'] as $r => $cells) {
            foreach ($cells as $c => $agg) {
                $r = (string) $r;
                $c = (string) $c;
                $rowAgg[$r] = self::merge($rowAgg[$r] ?? null, $agg);
                $colAgg[$c] = self::merge($colAgg[$c] ?? null, $agg);
                $grandAgg = self::merge($grandAgg, $agg);
            }
        }
        $denominator = (float) ($grandAgg['n'] ?? 0);

        $rowKeys = $all ? $data['rows'] : array_slice($data['rows'], 0, ReportQuery::MAX_ROWS);
        $colKeys = $all ? $data['cols'] : array_slice($data['cols'], 0, ReportQuery::MAX_COLUMNS);
        $rows = [];
        foreach ($rowKeys as $r) {
            $cells = [];
            foreach ($colKeys as $c) {
                $agg = $data['cells'][$r][$c] ?? null;
                $cells[$c] = $agg === null ? null : $this->value($agg, $denominator);
            }
            $rows[] = ['key' => $r, 'label' => $this->rowLabels[$r] ?? $r, 'cells' => $cells, 'total' => $this->value($rowAgg[$r], $denominator)];
        }
        $cols = [];
        $colTotals = [];
        foreach ($colKeys as $c) {
            $cols[] = ['key' => $c, 'label' => $this->colLabels[$c] ?? $c];
            $colTotals[$c] = $this->value($colAgg[$c], $denominator);
        }

        return ['currency' => $data['currency'], 'cols' => $cols, 'rows' => $rows, 'colTotals' => $colTotals,
            'grand' => $grandAgg === null ? null : $this->value($grandAgg, $denominator),
            'truncated' => count($data['rows']) > count($rowKeys) || count($data['cols']) > count($colKeys)];
    }

    /**
     * Series for <x-hc.chart>: the rows are the labels; each column is one series (a single series when there are no columns,
     * or for a pie / doughnut, which shows the row totals).
     *
     * @return array{labels: list<string>, datasets: list<array{label: string, data: list<float|int|null>}>}
     */
    public function chart(int $slice = 0, string $type = 'bar', int $maxLabels = 30, int $maxSeries = 10): array
    {
        $pivot = $this->pivot($slice);
        $rows = array_slice($pivot['rows'], 0, $maxLabels);
        $labels = array_map(fn ($row) => $row['label'], $rows);
        $number = fn (?float $v) => $v === null ? null : round($v, 2);
        $measure = $this->measureLabel();
        if (! $this->hasColumns || in_array($type, ['pie', 'doughnut'], true)) {
            return ['labels' => $labels, 'datasets' => [['label' => $measure, 'data' => array_map(fn ($row) => $number($row['total']), $rows)]]];
        }
        $datasets = [];
        foreach (array_slice($pivot['cols'], 0, $maxSeries) as $col) {
            $datasets[] = ['label' => $col['label'], 'data' => array_map(fn ($row) => $number($row['cells'][$col['key']] ?? null), $rows)];
        }

        return ['labels' => $labels, 'datasets' => $datasets];
    }

    /** "Count of rows", "Sum of Amount", ... in the current language. */
    public function measureLabel(): string
    {
        $type = __('report_builder.measure_'.$this->spec->measure);
        $field = $this->spec->measureField === null ? null : $this->dataset->field($this->spec->measureField)?->label();

        return $field === null ? $type : "{$type}: {$field}";
    }

    /** A cell's displayed value from its aggregates; null when the measure has nothing to show (no values, no rows). */
    private function value(array $agg, float $denominator): ?float
    {
        return match ($this->spec->measure) {
            'count' => (float) $agg['n'],
            'distinct' => (float) $agg['d'],
            'share' => $denominator > 0 ? $agg['n'] / $denominator * 100 : null,
            'sum' => $agg['s'],
            'avg' => $agg['c'] > 0 ? $agg['s'] / $agg['c'] : null,
            'min' => $agg['min'],
            'max' => $agg['max'],
        };
    }

    /** @param array{n: int, d: int, s: float, c: int, min: ?float, max: ?float}|null $into */
    private static function merge(?array $into, array $agg): array
    {
        if ($into === null) {
            return $agg;
        }

        return [
            'n' => $into['n'] + $agg['n'], 'd' => $into['d'] + $agg['d'], 's' => $into['s'] + $agg['s'], 'c' => $into['c'] + $agg['c'],
            'min' => $into['min'] === null ? $agg['min'] : ($agg['min'] === null ? $into['min'] : min($into['min'], $agg['min'])),
            'max' => $into['max'] === null ? $agg['max'] : ($agg['max'] === null ? $into['max'] : max($into['max'], $agg['max'])),
        ];
    }
}
