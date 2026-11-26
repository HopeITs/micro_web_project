<?php

namespace App\Services\Ask;

use App\Support\Datasets\Dataset;

/**
 * The address of a dataset's list with the understood filters, ids / codes / numbers / dates only (the same form the lists read
 * from the URL, as ReportDrill builds it). The question itself and the project-number search never go in an address; a filter
 * on a text or sensitive field is left out.
 */
final class AskLinks
{
    /** @param  list<array{field: string, op: string, values: list<mixed>}>  $filters */
    public static function listUrl(Dataset $dataset, array $filters): ?string
    {
        if ($dataset->listRoute() === null) {
            return null;
        }
        $query = ['locale' => app()->getLocale()];
        $list = [];
        $params = $dataset->listParams();
        foreach ($filters as $filter) {
            $field = $dataset->field($filter['field']);
            if ($field === null || $field->type === 'text' || $field->sensitive) {
                continue;
            }
            if (isset($params[$filter['field']]) && $filter['op'] === 'in') {
                [$name, $many] = $params[$filter['field']];
                $query[$name] = $many ? array_map('strval', $filter['values']) : (string) $filter['values'][0];
            } else {
                $list[$filter['field']] = ['op' => $filter['op'], 'values' => array_map(fn ($v) => $v === null ? '' : (string) $v, $filter['values'])];
            }
        }
        if ($list === [] && count($query) === 1) {
            return null;
        }
        if ($list !== []) {
            $query['f'] = $list;
        }

        return route($dataset->listRoute(), $query);
    }
}
