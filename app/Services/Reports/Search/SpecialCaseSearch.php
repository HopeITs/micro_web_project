<?php

declare(strict_types=1);

namespace App\Services\Reports\Search;

use App\Services\Reports\ProjectReportFilters;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;

/**
 * "Special cases" (desktop `bind_SpecialCases`): the same rows and columns as the special cases list (App\Services\Field\SpecialCaseListService:
 * case date, recorder, type, reason, description, action, the beneficiary and the project's career and categories), with the common project
 * filters. (The list's own query is private to that service, so this mode repeats its joins and takes the filters from ProjectReportFilters.)
 */
final class SpecialCaseSearch extends SearchMode
{
    public function key(): string
    {
        return 'special-cases';
    }

    public function group(): string
    {
        return 'field';
    }

    public function capabilities(): array
    {
        return ['special_cases.view', 'applications.view'];
    }

    public function columns(): array
    {
        return ['number', 'beneficiary', 'father', 'career', 'category', 'subcategory', 'case_date', 'recorder', 'case_type', 'reason', 'description', 'action'];
    }

    public function target(object $row): ?array
    {
        return ['special-cases', (int) $row->project_id];
    }

    public function query(array $cityIds, array $filters): Builder
    {
        $q = $this->projectBase($cityIds)->join('special_cases as s', fn ($j) => $j->on('s.project_id', '=', 'p.id')->whereNull('s.deleted_at'))
            ->leftJoin('web_accounts as w', 'w.id', '=', 's.account_id')
            ->addSelect([...$this->baseColumns(), 's.id as case_id', 's.case_date', 's.reason', 's.description', 's.action', 'w.username as recorder']);
        $this->lookup($q, 'special_case_types', 't', 's.special_case_type_id', 'type');
        $this->lookup($q, 'careers', 'cr', 'p.career_id', 'career');
        $this->lookup($q, 'project_categories', 'pc', 'p.project_category_id', 'category');
        $this->lookup($q, 'project_subcategories', 'ps', 'p.project_subcategory_id', 'subcategory');

        return ProjectReportFilters::apply($q, $filters);
    }

    public function sorts(): array
    {
        return $this->projectSorts([
            'career' => 'cr', 'category' => 'pc', 'subcategory' => 'ps', 'case_date' => ['s.case_date'], 'recorder' => ['w.username'], 'case_type' => 't', 'reason' => ['s.reason'],
        ]);
    }

    public function order(Builder $query): Builder
    {
        return $query->orderByDesc('s.case_date')->orderBy('p.city_id')->orderByDesc('s.id');
    }

    public function lines(Collection $rows): array
    {
        $people = $this->applicants($rows->pluck('project_id')->all());

        return $rows->map(function ($r) use ($people) {
            $first = $people[$r->project_id]['first'] ?? null;

            return [$this->number($r), $this->personName($first), (string) ($first->father_name ?? ''), $this->name($r, 'career'), $this->name($r, 'category'), $this->name($r, 'subcategory'),
                $this->day($r->case_date), (string) $r->recorder, $this->name($r, 'type'), (string) $r->reason, (string) $r->description, (string) $r->action];
        })->all();
    }
}
