<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Support\Datasets\Dataset;
use App\Support\Datasets\ReportSpec;
use Carbon\CarbonImmutable;

/**
 * Drill-down: the address of the dataset's own list page with the filters a pivot cell stands for (the report's filters plus the
 * cell's row value, column value and currency), in the form the lists already read from the URL: `f[field][op]` / `f[field][values][]`
 * with ids, codes, numbers and dates only, and the few fields a list takes as their own parameter (the city chips) under that name.
 *
 * It cannot represent a report that filters by a text field (names, national numbers never go in an address): url() then returns null
 * and the report shows its numbers without links. When the report already filters the field a cell groups by, the cell's value replaces it.
 */
final class ReportDrill
{
    public static function canLink(Dataset $dataset, ReportSpec $spec): bool
    {
        if ($dataset->listRoute() === null) {
            return false;
        }
        foreach ($spec->filters->filters as $filter) {
            $field = $dataset->field($filter['field']);
            if ($field === null || $field->type === 'text' || $field->sensitive) {
                return false;
            }
        }

        return true;
    }

    /** @param  string|null  $row  row key ("" = not set); null = every row  @param  string|null  $col  likewise for the column */
    public static function url(Dataset $dataset, ReportSpec $spec, ?string $row = null, ?string $col = null, ?string $currency = null): ?string
    {
        if (! self::canLink($dataset, $spec)) {
            return null;
        }
        $filters = [];
        foreach ($spec->filters->filters as $filter) {
            $filters[$filter['field']] = ['op' => $filter['op'], 'values' => $filter['values']];
        }
        if ($row !== null) {
            $filters[$spec->rows] = self::constraint($dataset, $spec->rows, $spec->grain($dataset, 'rows'), $row);
        }
        if ($col !== null && $spec->columns !== null) {
            $filters[$spec->columns] = self::constraint($dataset, $spec->columns, $spec->grain($dataset, 'columns'), $col);
        }
        if ($currency !== null && $dataset->currencyField() !== null) {
            $filters[$dataset->currencyField()] = ['op' => 'in', 'values' => [$currency]];
        }

        $query = ['locale' => app()->getLocale()];
        $list = [];
        $params = $dataset->listParams();
        foreach ($filters as $key => $filter) {
            if (isset($params[$key]) && $filter['op'] === 'in') {
                [$name, $many] = $params[$key];
                $query[$name] = $many ? array_map('strval', $filter['values']) : (string) $filter['values'][0];
            } else {
                $list[$key] = ['op' => $filter['op'], 'values' => array_map('strval', $filter['values'])];
            }
        }
        if ($list === [] && ($neutral = $dataset->listNeutralFilter()) !== null) {
            $list[$neutral['field']] = ['op' => $neutral['op'], 'values' => $neutral['values']];
        }
        if ($list !== []) {
            $query['f'] = $list;
        }

        return route($dataset->listRoute(), $query);
    }

    /** @return array{op: string, values: list<string>} the list filter for one group value */
    public static function constraint(Dataset $dataset, string $fieldKey, ?string $grain, string $key): array
    {
        if ($key === '') {
            return ['op' => 'empty', 'values' => []];
        }
        if ($dataset->field($fieldKey)?->type === 'date') {
            $start = $grain === 'month' ? CarbonImmutable::createFromFormat('!Y-m', $key) : CarbonImmutable::createFromFormat('!Y', $key);
            $end = $grain === 'month' ? $start->endOfMonth() : $start->endOfYear();

            return ['op' => 'between', 'values' => [$start->toDateString(), $end->toDateString()]];
        }

        return ['op' => 'in', 'values' => [$key]];
    }
}
