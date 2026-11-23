<?php

declare(strict_types=1);

namespace App\Services\Reports\Search;

use App\Services\AccountAccess;
use App\Services\Reports\ProjectReportFilters;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The desktop's twelve "detail table" search types (`bind_person_tables`, `bind_project_tables`): the rows of one detail table (assets, losses,
 * materials, ...) listed with the project they belong to.
 *
 * - "person" types list a person's detail rows with the person's name and relation and the project of the family's provider (the family row's
 *   provider, or the person themself when the row has none), as the desktop joins them; a person with several family rows appears once for each.
 * - "project" types list a project's detail rows with the project's beneficiary.
 * Only projects with a state in force appear (the desktop's `Current_State = 1`).
 *
 * A column is [key, 'lookup', lookup table, foreign key column], [key, 'text', column], [key, 'date', column], [key, 'bool', column] or [key, 'num', column].
 */
final class DetailSearch extends SearchMode
{
    public const DEFINITIONS = [
        'aid-sources' => ['person', 'person_aid_sources', [['aid_source', 'lookup', 'aid_sources', 'aid_source_id'], ['year', 'text', 'year_text'], ['amount', 'text', 'amount_text'], ['aided_project_type', 'text', 'project_type_text'], ['items', 'text', 'notes']]],
        'assets' => ['person', 'person_assets', [['asset_type', 'lookup', 'asset_types', 'asset_type_id'], ['year', 'text', 'year_text'], ['asset_kind', 'lookup', 'asset_kinds', 'asset_kind_id'], ['description', 'text', 'description'], ['asset_benefit', 'lookup', 'asset_benefits', 'asset_benefit_id'], ['notes', 'text', 'note']]],
        'courses' => ['person', 'person_courses', [['course_type', 'lookup', 'project_subcategories', 'project_subcategory_id'], ['course_name', 'text', 'name'], ['institute', 'text', 'place'], ['funder', 'text', 'donor_text'], ['course_date', 'text', 'date_text']]],
        'goals' => ['person', 'person_goals', [['goal', 'lookup', 'goals', 'goal_id']]],
        'relief-aids' => ['person', 'person_aid_received', [['aid_source', 'lookup', 'aid_sources', 'aid_source_id'], ['aid_type', 'lookup', 'aid_types', 'aid_type_id'], ['aid_period', 'lookup', 'aid_periods', 'aid_period_id'], ['notes', 'text', 'notes']]],
        'illness' => ['person', 'person_illnesses', [['illness', 'lookup', 'illnesses', 'illness_id'], ['description', 'text', 'note'], ['medical_aid', 'bool', 'has_medical_aid'], ['aid_source', 'lookup', 'aid_sources', 'aid_source_id'], ['aid_period', 'lookup', 'aid_periods', 'aid_period_id'], ['medical_need', 'lookup', 'medical_needs', 'medical_need_id']]],
        'losses' => ['person', 'person_losses', [['loss_type', 'lookup', 'loss_types', 'loss_type_id'], ['year', 'text', 'year_text'], ['notes', 'text', 'note']]],
        'home-power' => ['person', 'person_power_sources', [['power_source', 'lookup', 'power_sources', 'power_source_id'], ['system_description', 'text', 'note'], ['monthly_cost', 'num', 'cost'], ['responsible', 'text', 'responsibility']]],
        'current-works' => ['person', 'person_work_experiences', [['work', 'lookup', 'occupations', 'occupation_id'], ['work_type', 'lookup', 'work_types', 'work_type_id'], ['description', 'text', 'description'], ['start', 'text', 'begin_text'], ['premises', 'text', 'place_state'], ['address', 'text', 'place'], ['employer', 'text', 'contact'], ['salary', 'num', 'salary']], ['status', 'yes']],
        'work-experiences' => ['person', 'person_work_experiences', [['work', 'lookup', 'occupations', 'occupation_id'], ['work_type', 'lookup', 'work_types', 'work_type_id'], ['description', 'text', 'description'], ['start', 'text', 'begin_text'], ['end', 'text', 'end_text'], ['premises', 'text', 'place_state'], ['address', 'text', 'place'], ['employer', 'text', 'contact'], ['leaving_reason', 'text', 'leaving_reason']], ['status', 'no']],
        'materials' => ['project', 'project_materials', [['item', 'text', 'name'], ['material_type', 'text', 'comments'], ['quantity', 'num', 'quantity'], ['unit_price', 'num', 'price'], ['local_contribution', 'num', 'local_contribution']]],
        'project-power' => ['project', 'project_power_sources', [['power_source', 'lookup', 'power_sources', 'power_source_id'], ['system_description', 'text', 'note'], ['monthly_cost', 'num', 'cost'], ['responsible', 'text', 'responsibility']]],
    ];

    public function __construct(AccountAccess $access, private string $key = 'assets')
    {
        parent::__construct($access);
        abort_unless(isset(self::DEFINITIONS[$key]), 404);
    }

    public function key(): string
    {
        return $this->key;
    }

    public function group(): string
    {
        return $this->kind() === 'person' ? 'people' : 'applications';
    }

    public function capabilities(): array
    {
        return $this->kind() === 'person' ? ['people.view', 'applications.view'] : ['applications.view'];
    }

    private function kind(): string
    {
        return self::DEFINITIONS[$this->key][0];
    }

    /** @return list<array<int, string>> */
    private function definition(): array
    {
        return self::DEFINITIONS[$this->key][2];
    }

    public function columns(): array
    {
        $lead = $this->kind() === 'person' ? ['number', 'person', 'relation'] : ['number', 'beneficiary', 'father'];

        return [...$lead, ...array_map(fn ($c) => $c[0], $this->definition())];
    }

    public function numeric(): array
    {
        $numeric = [1];
        foreach ($this->definition() as $i => $c) {
            $c[1] === 'num' && $numeric[] = $i + 4;
        }

        return $numeric;
    }

    public function query(array $cityIds, array $filters): Builder
    {
        [$kind, $table] = self::DEFINITIONS[$this->key];
        $q = DB::table($table.' as d')->whereNull('d.deleted_at');
        if ($kind === 'person') {
            $q->join('people as pe', fn ($j) => $j->on('pe.id', '=', 'd.person_id')->whereNull('pe.deleted_at'))
                ->leftJoin('family_members as fm', fn ($j) => $j->on('fm.person_id', '=', 'pe.id')->whereNull('fm.deleted_at'))
                ->leftJoin('relations as rl', 'rl.id', '=', 'fm.relation_id')
                ->join('project_applicants as a', fn ($j) => $j->on('a.person_id', '=', DB::raw('COALESCE(fm.provider_person_id, pe.id)'))->whereNull('a.deleted_at'));
            $this->joinProject($q, 'a.project_id', $cityIds);
            $q->addSelect(['pe.id as person_id', 'pe.first_name', 'pe.last_name', 'rl.name_ar as relation_ar', 'rl.name_en as relation_en']);
        } else {
            $this->joinProject($q, 'd.project_id', $cityIds);
        }
        $q->addSelect([...$this->baseColumns(), 'd.id as detail_id']);
        foreach ($this->definition() as $i => $c) {
            if ($c[1] === 'lookup') {
                $this->lookup($q, $c[2], 'k'.$i, 'd.'.$c[3], 'c'.$i);
            } else {
                $q->addSelect('d.'.$c[2].' as v'.$i);
            }
        }
        if (isset(self::DEFINITIONS[$this->key][3])) {
            [$column, $wanted] = self::DEFINITIONS[$this->key][3];
            $q->whereRaw('LOWER(d.'.$column.') = ?', [$wanted]);
        }

        return ProjectReportFilters::apply($q, $filters);
    }

    public function order(Builder $query): Builder
    {
        $query->orderBy('p.project_number')->orderBy('p.city_id')->orderBy('d.id');

        return $this->kind() === 'person' ? $query->orderBy('fm.id')->orderBy('a.id') : $query;
    }

    public function lines(Collection $rows): array
    {
        $people = $this->kind() === 'project' ? $this->applicants($rows->pluck('project_id')->all()) : [];

        return $rows->map(function ($r) use ($people) {
            $lead = $this->kind() === 'person'
                ? [$this->number($r), $this->personName($r), $this->name($r, 'relation')]
                : [$this->number($r), $this->personName($people[$r->project_id]['first'] ?? null), (string) ($people[$r->project_id]['first']->father_name ?? '')];
            $cells = [];
            foreach ($this->definition() as $i => $c) {
                $cells[] = match ($c[1]) {
                    'lookup' => $this->name($r, 'c'.$i),
                    'date' => $this->day($r->{'v'.$i}),
                    'bool' => $r->{'v'.$i} === null ? '' : ((int) $r->{'v'.$i} === 1 ? __('search.yes') : __('search.no')),
                    default => $r->{'v'.$i} ?? '',
                };
            }

            return [...$lead, ...$cells];
        })->all();
    }
}
