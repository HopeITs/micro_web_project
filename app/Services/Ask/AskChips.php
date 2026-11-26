<?php

namespace App\Services\Ask;

use App\Support\Datasets\Dataset;

/** The understood filters of a question as chips: field name and a short summary of the value, in the screen's language. */
final class AskChips
{
    /**
     * @param  list<array{field: string, op: string, values: list<mixed>}>  $filters
     * @return list<array{field: string, label: string, summary: string}>
     */
    public static function for(Dataset $dataset, array $filters): array
    {
        $chips = [];
        foreach ($filters as $filter) {
            $field = $dataset->field($filter['field']);
            if ($field === null) {
                continue;
            }
            $options = $field->hasOptions() ? $field->options() : [];
            $v = $filter['values'];
            $summary = match ($filter['op']) {
                'empty' => __('hc.filter_op_empty'),
                'not_empty' => __('hc.filter_op_not_empty'),
                'in', 'not_in' => ($filter['op'] === 'not_in' ? __('hc.filter_not').' ' : '').implode('، ', array_map(fn ($x) => $options[(string) $x] ?? (string) $x, $v)),
                'contains' => implode('، ', array_map('strval', $v)),
                'gt' => '> '.$v[0],
                'lt' => '< '.$v[0],
                'between' => count($v) === 1 ? __('hc.filter_preset_'.$v[0])
                    : (($v[0] ?? '') !== '' && ($v[1] ?? '') !== '' ? $v[0].' – '.$v[1] : (($v[0] ?? '') !== '' ? '≥ '.$v[0] : '≤ '.($v[1] ?? ''))),
                default => '',
            };
            $chips[] = ['field' => $field->key, 'label' => $field->label(), 'summary' => $summary];
        }

        return $chips;
    }
}
