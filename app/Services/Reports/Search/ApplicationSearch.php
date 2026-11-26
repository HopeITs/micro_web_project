<?php

declare(strict_types=1);

namespace App\Services\Reports\Search;

use App\Services\Reports\ProjectReportFilters;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * "Applications" (desktop `Search_Form.bind_Applications`): one row per project and applicant (beneficiary, partner), with the project, the
 * state in force, the loan (one per project) and the person's details. Totals: distinct projects and the sum of the people at home.
 * A row whose beneficiary name (first, last, father) belongs to more than one person in the permitted cities is marked.
 */
final class ApplicationSearch extends SearchMode
{
    public const CLOSED = ['Canceled', 'Cancelled', 'Closed'];

    public const FUNDED = ['Financed', 'Finished'];

    public function key(): string
    {
        return 'applications';
    }

    public function group(): string
    {
        return 'applications';
    }

    public function capabilities(): array
    {
        return ['applications.view', 'people.view'];
    }

    public function columns(): array
    {
        return ['number', 'beneficiary', 'beneficiary_en', 'father', 'mother', 'career', 'description', 'speciality', 'category', 'subcategory', 'project_type', 'subtype', 'fund_type',
            'request_date', 'state', 'state_date', 'state_count', 'fund_date', 'fund_amount', 'currency', 'donor', 'group', 'waiting_months', 'sustainability',
            'birth_date', 'age_at_request', 'parish', 'priest1', 'priest2', 'home_street', 'address', 'registration', 'national_number', 'mobile', 'mobile2', 'family_size', 'at_home',
            'military', 'discharge_date', 'military_note', 'military_war', 'sex', 'marital', 'project_street', 'project_place', 'role', 'stage'];
    }

    public function numeric(): array
    {
        return [1, 17, 19, 23, 24, 26, 36, 37];
    }

    public function sensitive(): array
    {
        return [33, 34, 35];
    }

    public function query(array $cityIds, array $filters): Builder
    {
        $q = $this->projectBase($cityIds)
            ->leftJoin('project_applicants as a', fn ($j) => $j->on('a.project_id', '=', 'p.id')->whereNull('a.deleted_at'))
            ->leftJoin('people as pe', fn ($j) => $j->on('pe.id', '=', 'a.person_id')->whereNull('pe.deleted_at'))
            ->leftJoin('loans as l', fn ($j) => $j->on('l.project_id', '=', 'p.id')->whereNull('l.deleted_at'))
            ->addSelect([...$this->baseColumns(), 'a.id as applicant_id', 'a.role', 'l.id as loan_id', 'l.taken_on', 'l.amount', 'l.currency', 'cs.changed_on', 'st.name_en as state_key', 'st.name_ar as state_ar', 'st.name_en as state_en',
                'p.name', 'p.speciality', 'p.request_date', 'p.place_address', 'p.application_stage', 'pe.id as person_id', 'pe.first_name', 'pe.last_name', 'pe.father_name', 'pe.mother_name', 'pe.full_name_en',
                'pe.birth_date', 'pe.home_address', 'pe.registration_place', 'pe.national_number', 'pe.mobile', 'pe.mobile_2', 'pe.family_size', 'pe.household_size', 'pe.military_service',
                'pe.military_service_date', 'pe.military_service_note', 'pe.military_in_war', 'pe.sex']);
        $this->lookup($q, 'careers', 'cr', 'p.career_id', 'career');
        $this->lookup($q, 'project_categories', 'pc', 'p.project_category_id', 'category');
        $this->lookup($q, 'project_subcategories', 'ps', 'p.project_subcategory_id', 'subcategory');
        $this->lookup($q, 'project_types', 'pt', 'p.project_type_id', 'ptype');
        $this->lookup($q, 'project_subtypes', 'pst', 'p.project_subtype_id', 'subtype');
        $this->lookup($q, 'fund_types', 'ft', 'p.fund_type_id', 'fund');
        $this->lookup($q, 'donors', 'dn', 'p.donor_id', 'donor');
        $this->lookup($q, 'donor_groups', 'dg', 'p.donor_group_id', 'dgroup');
        $this->lookup($q, 'parishes', 'pa', 'pe.parish_id', 'parish');
        $this->lookup($q, 'priests', 'pr1', 'pe.priest_id', 'priest1');
        $this->lookup($q, 'priests', 'pr2', 'pe.second_priest_id', 'priest2');
        $this->lookup($q, 'streets', 'hs', 'pe.street_id', 'hstreet');
        $this->lookup($q, 'streets', 'ps2', 'p.street_id', 'pstreet');
        $this->lookup($q, 'marital_statuses', 'ms', 'pe.marital_status_id', 'marital');

        return ProjectReportFilters::apply($q, $filters);
    }

    public function sorts(): array
    {
        return $this->projectSorts([
            'beneficiary' => ['pe.first_name', 'pe.last_name'], 'beneficiary_en' => ['pe.full_name_en'], 'father' => ['pe.father_name'], 'mother' => ['pe.mother_name'], 'career' => 'cr',
            'description' => ['p.name'], 'speciality' => ['p.speciality'], 'category' => 'pc', 'subcategory' => 'ps', 'project_type' => 'pt', 'subtype' => 'pst', 'fund_type' => 'ft',
            'request_date' => ['p.request_date'], 'state' => 'st', 'state_date' => ['cs.changed_on'], 'fund_date' => ['l.taken_on'], 'fund_amount' => ['l.currency', 'l.amount'],
            'currency' => ['l.currency'], 'donor' => 'dn', 'group' => 'dg', 'birth_date' => ['pe.birth_date'], 'parish' => 'pa', 'home_street' => 'hs', 'family_size' => ['pe.family_size'],
            'at_home' => ['pe.household_size'], 'sex' => ['pe.sex'], 'marital' => 'ms', 'project_street' => 'ps2', 'role' => ['a.role'],
        ]);
    }

    public function order(Builder $query): Builder
    {
        return $query->orderBy('p.project_number')->orderBy('p.city_id')->orderBy('p.id')->orderBy('a.id')->orderBy('l.id');
    }

    public function summary(Builder $query): ?array
    {
        $row = $this->bare($query)->selectRaw('COUNT(DISTINCT p.id) as projects, COALESCE(SUM(pe.household_size), 0) as members')->first();

        return ['projects' => (int) $row->projects, 'members' => (int) $row->members];
    }

    public function lines(Collection $rows): array
    {
        $counts = DB::table('project_state_history')->whereIn('project_id', $rows->pluck('project_id')->unique()->all() ?: [0])->whereNull('deleted_at')
            ->groupBy('project_id')->select('project_id', DB::raw('COUNT(*) as n'))->pluck('n', 'project_id');
        $today = CarbonImmutable::today();
        $sex = ['male' => __('search.sex.male'), 'female' => __('search.sex.female')];

        return $rows->map(function ($r) use ($counts, $today, $sex) {
            $request = $this->day($r->request_date);
            $fund = $this->day($r->taken_on);
            $stateDate = $this->day($r->changed_on);
            $birth = $this->day($r->birth_date);

            return [
                $this->number($r), $this->personName($r), (string) $r->full_name_en, (string) $r->father_name, (string) $r->mother_name, $this->name($r, 'career'), (string) $r->name,
                (string) $r->speciality, $this->name($r, 'category'), $this->name($r, 'subcategory'), $this->name($r, 'ptype'), $this->name($r, 'subtype'), $this->name($r, 'fund'),
                $request, $this->name($r, 'state'), $stateDate, (int) ($counts[$r->project_id] ?? 0), $fund, $r->amount === null ? '' : $r->amount, (string) ($r->currency ?? ''),
                $this->name($r, 'donor'), $this->name($r, 'dgroup'), $request !== '' && $fund !== '' ? $this->months($request, $fund) : '',
                self::sustainability($r->state_key, $fund, $stateDate, $today),
                $birth, $birth !== '' && $request !== '' ? (int) CarbonImmutable::parse($birth)->diffInYears(CarbonImmutable::parse($request)) : '',
                $this->name($r, 'parish'), $this->name($r, 'priest1'), $this->name($r, 'priest2'), $this->name($r, 'hstreet'), (string) $r->home_address, (string) $r->registration_place,
                (string) $r->national_number, (string) $r->mobile, (string) $r->mobile_2, $r->family_size ?? '', $r->household_size ?? '',
                (string) $r->military_service, $this->day($r->military_service_date), (string) $r->military_service_note,
                $r->military_in_war === null ? '' : ((int) $r->military_in_war === 1 ? __('search.yes') : __('search.no')), $sex[$r->sex] ?? '', $this->name($r, 'marital'),
                $this->name($r, 'pstreet'), (string) $r->place_address, (string) $r->role, __('search.stage.'.($r->application_stage ?? 'full')),
            ];
        })->all();
    }

    public function flags(Collection $rows): array
    {
        $names = $rows->pluck('first_name')->filter()->unique()->values()->all();
        if ($names === []) {
            return array_fill(0, $rows->count(), false);
        }
        $people = DB::table('people as pe')->join('project_applicants as a', 'a.person_id', '=', 'pe.id')->whereIn('pe.first_name', $names)->whereIn('pe.city_id', $rows->pluck('city_id')->unique()->all())
            ->whereNull('pe.deleted_at')->whereNull('a.deleted_at')->distinct()->get(['pe.id', 'pe.first_name', 'pe.last_name', 'pe.father_name']);
        $by = [];
        foreach ($people as $p) {
            $by[$p->first_name.'|'.$p->last_name.'|'.$p->father_name][$p->id] = true;
        }

        return $rows->map(fn ($r) => $r->person_id !== null && count($by[$r->first_name.'|'.$r->last_name.'|'.$r->father_name] ?? []) > 1)->all();
    }

    public function target(object $row): ?array
    {
        return ['project', (int) $row->project_id];
    }

    private function months(string $from, string $to): int
    {
        return (int) CarbonImmutable::parse($from)->diffInMonths(CarbonImmutable::parse($to), false);
    }

    /** Years the project has run since its loan: closed or cancelled → to the state date; financed or finished → to today; otherwise empty. */
    public static function sustainability(?string $stateKey, string $fund, string $stateDate, CarbonImmutable $today): float|string
    {
        if ($fund === '') {
            return '';
        }
        $end = match (true) {
            in_array($stateKey, self::CLOSED, true) => $stateDate === '' ? null : CarbonImmutable::parse($stateDate),
            in_array($stateKey, self::FUNDED, true) => $today,
            default => null,
        };

        return $end === null ? '' : round(CarbonImmutable::parse($fund)->diffInDays($end, false) / 365.25, 2);
    }
}
