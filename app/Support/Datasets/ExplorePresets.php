<?php

declare(strict_types=1);

namespace App\Support\Datasets;

/**
 * The "ready questions" of the Explore page (task X2): per dataset, a few presets that are each just a spec plus a view
 * (list, pivot or chart). They come from the 15 axes of the desktop statistics (state, fund type, project type, donor, sex,
 * marital status, education, request year ...) and from the most used of the 22 search types (overdue instalments, applications
 * in the full stage, people without projects ...). Only field keys and lookup values appear here, never names or numbers; every
 * preset is checked against its dataset before it is applied (ExploreScenarioTest checks all of them).
 *
 * Labels are `explore.q.<dataset>.<key>` in lang/{ar,en}/explore.php.
 */
final class ExplorePresets
{
    public const VIEWS = ['list', 'pivot', 'chart'];

    /**
     * @return list<array{key: string, view: string, rows: string, rowGrain: string, columns: string, columnGrain: string, measure: string, measureField: string, chart: string, filters: list<array{field: string, op: string, values: list<string>}>}>
     */
    public static function for(string $dataset): array
    {
        $q = fn (string $key, string $view, string $rows = '', string $columns = '', string $chart = 'bar', string $measure = 'count', string $measureField = '', array $filters = [], string $rowGrain = '', string $columnGrain = '') => [
            'key' => $key, 'view' => $view, 'rows' => $rows, 'rowGrain' => $rowGrain, 'columns' => $columns, 'columnGrain' => $columnGrain,
            'measure' => $measure, 'measureField' => $measureField, 'chart' => $chart, 'filters' => $filters,
        ];

        return match ($dataset) {
            'projects' => [
                $q('by_state', 'chart', 'state'),
                $q('state_by_city', 'pivot', 'state', 'city'),
                $q('by_fund_type', 'chart', 'fund_type', chart: 'pie'),
                $q('by_project_type', 'chart', 'project_type'),
                $q('by_donor', 'chart', 'donor'),
                $q('by_sex', 'chart', 'sex', chart: 'pie'),
                $q('by_stage', 'pivot', 'stage', 'state'),
                $q('by_year', 'chart', 'request_date', chart: 'line', rowGrain: 'year'),
                $q('full_stage', 'list', filters: [['field' => 'stage', 'op' => 'in', 'values' => ['full']]]),
                $q('overdue', 'list', filters: [['field' => 'overdue_installments', 'op' => 'gt', 'values' => ['0']]]),
                $q('this_year', 'list', filters: [['field' => 'request_date', 'op' => 'between', 'values' => ['this_year']]]),
            ],
            'people' => [
                $q('by_sex', 'chart', 'sex', chart: 'pie'),
                $q('by_marital', 'chart', 'marital_status'),
                $q('by_education', 'chart', 'education'),
                $q('by_city', 'chart', 'city'),
                $q('sex_by_city', 'pivot', 'city', 'sex'),
                $q('with_projects', 'pivot', 'has_projects', 'city'),
                $q('without_projects', 'list', filters: [['field' => 'has_projects', 'op' => 'in', 'values' => ['without']]]),
            ],
            'installments' => [
                $q('status_by_currency', 'pivot', 'status', measure: 'sum', measureField: 'amount'),
                $q('due_by_month', 'chart', 'due_on', chart: 'line', measure: 'sum', measureField: 'amount', rowGrain: 'month'),
                $q('by_donor', 'chart', 'donor', measure: 'sum', measureField: 'amount'),
                $q('by_state', 'pivot', 'state', 'status'),
                $q('by_city', 'chart', 'city', measure: 'sum', measureField: 'amount'),
                $q('overdue', 'list', filters: [['field' => 'status', 'op' => 'in', 'values' => ['overdue']]]),
                $q('this_month', 'list', filters: [['field' => 'due_on', 'op' => 'between', 'values' => ['this_month']]]),
            ],
            default => [],
        };
    }

    /** @return array<string, mixed>|null */
    public static function find(string $dataset, string $key): ?array
    {
        foreach (self::for($dataset) as $preset) {
            if ($preset['key'] === $key) {
                return $preset;
            }
        }

        return null;
    }
}
