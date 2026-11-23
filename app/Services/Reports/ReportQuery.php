<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Models\WebAccount;
use App\Services\AccountAccess;
use App\Support\Datasets\Dataset;
use App\Support\Datasets\Field;
use App\Support\Datasets\InvalidQuerySpec;
use App\Support\Datasets\ReportSpec;
use Illuminate\Support\Facades\DB;

/**
 * Runs a ReportSpec as ONE GROUP BY query (smart-search plan 2026-11, phase 3) and shapes the rows as a pivot (ReportResult).
 *
 *   SELECT <rows> g_r, <columns> g_c, <currency> g_cur, COUNT(*) m_n, ... FROM <dataset base> WHERE city IN (...) AND <filters>
 *   GROUP BY g_r, g_c, g_cur
 *
 * - Cities: only those where the account holds every view capability of the dataset (plus `$extraCapabilities`, e.g. the
 *   export capability), on top of the CityScope the dataset's base query already carries.
 * - Every select expression is also a GROUP BY item (by alias) or an aggregate, so the query is valid under ONLY_FULL_GROUP_BY.
 * - Money is never added across currencies: a money measure forces the dataset's currency as one more grouping, and the
 *   result has one slice (table) per currency.
 * - The measures are kept as parts (rows, distinct keys, sum, count of values, min, max) so row, column and grand totals
 *   are exact for every measure, including an average, without a second query.
 */
final class ReportQuery
{
    public const MAX_ROWS = 200;

    public const MAX_COLUMNS = 40;

    public function __construct(private AccountAccess $access) {}

    /** @return list<int> the cities a report of this dataset covers for the account */
    public function cityIds(WebAccount $account, Dataset $dataset, array $extraCapabilities = []): array
    {
        return $this->access->citiesWithAll($account, [...$dataset->viewCapabilities(), ...$extraCapabilities])->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * @param  list<string>  $extraCapabilities  further per-city capabilities the covered cities must hold
     *
     * @throws InvalidQuerySpec
     */
    public function run(WebAccount $account, Dataset $dataset, ReportSpec $spec, array $extraCapabilities = []): ReportResult
    {
        $spec->validate($dataset);
        $cities = $this->cityIds($account, $dataset, $extraCapabilities);
        if ($cities === []) {
            return new ReportResult($spec, $dataset, [], [], [], false);
        }

        $rowField = $dataset->field($spec->rows);
        $colField = $spec->columns === null ? null : $dataset->field($spec->columns);
        $measure = $spec->measureField === null ? null : $dataset->field($spec->measureField);
        $currency = $measure?->type === 'money' ? $dataset->field((string) $dataset->currencyField()) : null;

        $query = $dataset->query()->whereIn($dataset->cityColumn(), $cities);
        $spec->filters->apply($query, $dataset, [], false);
        $base = $query->toBase()->reorder();

        $select = [DB::raw($dataset->groupSql($rowField, $spec->grain($dataset, 'rows')).' as g_r')];
        $group = ['g_r'];
        if ($colField !== null) {
            $select[] = DB::raw($dataset->groupSql($colField, $spec->grain($dataset, 'columns')).' as g_c');
            $group[] = 'g_c';
        }
        if ($currency !== null) {
            $select[] = DB::raw("{$currency->sql} as g_cur");
            $group[] = 'g_cur';
        }
        $select[] = DB::raw('COUNT(*) as m_n');
        match ($spec->measure) {
            'distinct' => $select[] = DB::raw('COUNT(DISTINCT '.$dataset->measureKey().') as m_d'),
            'sum' => $select[] = DB::raw("SUM({$measure->sql}) as m_s"),
            'avg' => array_push($select, DB::raw("SUM({$measure->sql}) as m_s"), DB::raw("COUNT({$measure->sql}) as m_c")),
            'min' => $select[] = DB::raw("MIN({$measure->sql}) as m_min"),
            'max' => $select[] = DB::raw("MAX({$measure->sql}) as m_max"),
            default => null,
        };
        $rows = $base->select($select)->groupBy(...$group)->get();

        return $this->shape($spec, $dataset, $rowField, $colField, $currency !== null, $rows);
    }

    private function shape(ReportSpec $spec, Dataset $dataset, Field $rowField, ?Field $colField, bool $byCurrency, iterable $rows): ReportResult
    {
        $slices = [];
        $rowKeys = [];
        $colKeys = [];
        foreach ($rows as $row) {
            $r = self::key($row->g_r);
            $c = $colField === null ? '' : self::key($row->g_c);
            $slice = $byCurrency ? self::key($row->g_cur) : '';
            $rowKeys[$r] = true;
            $colKeys[$c] = true;
            $slices[$slice]['cells'][$r][$c] = [
                'n' => (int) $row->m_n, 'd' => (int) ($row->m_d ?? 0), 's' => (float) ($row->m_s ?? 0), 'c' => (int) ($row->m_c ?? 0),
                'min' => isset($row->m_min) ? (float) $row->m_min : null, 'max' => isset($row->m_max) ? (float) $row->m_max : null,
            ];
        }

        $rowOrder = self::order($rowField, array_keys($rowKeys));
        $colOrder = $colField === null ? [''] : self::order($colField, array_keys($colKeys));
        $rowLabels = [];
        foreach ($rowOrder as $key) {
            $rowLabels[$key] = self::label($rowField, $key);
        }
        $colLabels = [];
        foreach ($colOrder as $key) {
            $colLabels[$key] = $colField === null ? '' : self::label($colField, $key);
        }

        ksort($slices);
        $out = [];
        foreach ($slices as $currency => $slice) {
            $presentCols = [];
            foreach ($slice['cells'] as $cells) {
                foreach (array_keys($cells) as $c) {
                    $presentCols[(string) $c] = true;
                }
            }
            $out[] = [
                'currency' => $byCurrency ? (string) $currency : null,
                'rows' => array_values(array_intersect($rowOrder, array_map('strval', array_keys($slice['cells'])))),
                'cols' => array_values(array_intersect($colOrder, array_map('strval', array_keys($presentCols)))),
                'cells' => $slice['cells'],
            ];
        }

        return new ReportResult($spec, $dataset, $out, $rowLabels, $colLabels, $colField !== null);
    }

    /** null and '' are the same "not set" group. */
    private static function key(mixed $value): string
    {
        return $value === null || $value === '' ? '' : (string) $value;
    }

    /** Label of one group value: the lookup / enum option in the current language, the year or month, or "not set". */
    public static function label(Field $field, string $key): string
    {
        if ($key === '') {
            return __('report_builder.not_set');
        }

        return $field->type === 'date' ? $key : ($field->options()[$key] ?? $key);
    }

    /**
     * Option fields in the order of their list (lookup order), dates ascending, anything unknown after them, "not set" last.
     *
     * @param  list<string|int>  $keys
     * @return list<string>
     */
    private static function order(Field $field, array $keys): array
    {
        $keys = array_map('strval', $keys);
        $position = $field->type === 'date' ? [] : array_flip(array_map('strval', array_keys($field->options())));
        usort($keys, function (string $a, string $b) use ($position) {
            if ($a === '' || $b === '') {
                return $a === '' ? ($b === '' ? 0 : 1) : -1;
            }
            $pa = $position[$a] ?? PHP_INT_MAX;
            $pb = $position[$b] ?? PHP_INT_MAX;

            return $pa <=> $pb ?: strnatcmp($a, $b);
        });

        return $keys;
    }
}
