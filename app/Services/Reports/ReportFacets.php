<?php

declare(strict_types=1);

namespace App\Services\Reports;

use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The counts beside the choices of one filter on the search and statistics pages: how many records each value would give under every
 * OTHER filter. The caller hands a closure that turns a filter set into the ids it matches (already limited to the cities and rows the
 * account may read); the filter being counted is taken out first, so unticked values keep their numbers. One query per open filter.
 */
final class ReportFacets
{
    /**
     * Projects per value of a filter.
     *
     * @param  array<string, mixed>  $filters  every filter in force
     * @param  Closure(array<string, mixed>): Builder  $scope  a query selecting the ids of the projects that pass the given filters
     * @return array<int|string, int>
     */
    public function projects(string $key, array $filters, Closure $scope): array
    {
        $f = ProjectReportFilters::clean($filters) + $filters;
        $f['not'] = array_values(array_diff($f['not'], [$key]));

        if (in_array($key, ProjectReportFilters::PERSONAL, true)) {
            return $this->personal($key, $f, $scope);
        }
        if ($key === 'partnership') {
            $f['partnership'] = '';
            $row = $this->among('projects as xp', 'xp.id', $scope($f))
                ->selectRaw('SUM(CASE WHEN xp.partnership = 1 THEN 1 ELSE 0 END) as individual, SUM(CASE WHEN xp.partnership > 1 THEN 1 ELSE 0 END) as partnership')->first();

            return ['individual' => (int) ($row->individual ?? 0), 'partnership' => (int) ($row->partnership ?? 0)];
        }
        if (isset(ProjectReportFilters::PROJECT_LOOKUPS[$key])) {
            $column = 'xp.'.ProjectReportFilters::PROJECT_LOOKUPS[$key][1];
            $f[$key] = [];

            return $this->grouped($this->among('projects as xp', 'xp.id', $scope($f)), $column, 'COUNT(*)');
        }
        if ($key === 'status') {
            $f['status'] = [];
            $query = $this->among('project_state_history as xh', 'xh.project_id', $scope($f))->where('xh.is_current', true)->whereNull('xh.deleted_at');

            return $this->grouped($query, 'xh.project_state_id', 'COUNT(DISTINCT xh.project_id)');
        }
        if ($key === 'request_year') {
            $f['request_year'] = [];

            return $this->grouped($this->among('projects as xp', 'xp.id', $scope($f)), 'SUBSTR(xp.request_date, 1, 4)', 'COUNT(*)');
        }
        if ($key === 'fund_year') {
            $f['fund_year'] = [];
            $query = $this->among('loans as xl', 'xl.project_id', $scope($f))->whereNull('xl.deleted_at');

            return $this->grouped($query, 'SUBSTR(xl.taken_on, 1, 4)', 'COUNT(DISTINCT xl.project_id)');
        }

        return [];
    }

    /**
     * A person filter is counted on the beneficiary row itself, together with the other person filters chosen (they describe the same
     * beneficiary), exactly as ProjectReportFilters::apply() would match them.
     */
    private function personal(string $key, array $f, Closure $scope): array
    {
        $chosen = array_values(array_filter(ProjectReportFilters::PERSONAL, fn ($k) => $k !== $key && $f[$k] !== [] && ! in_array($k, $f['not'], true)));
        $rest = $f;
        foreach ([$key, ...$chosen] as $k) {
            $rest[$k] = [];
        }
        $query = $this->among('project_applicants as xa', 'xa.project_id', $scope($rest))
            ->join('people as xpe', 'xpe.id', '=', 'xa.person_id')->join('projects as xp', 'xp.id', '=', 'xa.project_id')
            ->whereNull('xa.deleted_at')->whereNull('xpe.deleted_at')->where('xa.role', ProjectReportFilters::BENEFICIARY_ROLE);
        ProjectReportFilters::personConditions($query, $f, $chosen, 'xpe', 'xp');

        if ($key === 'age') {
            $age = ProjectReportFilters::ageAtRequest('xpe.birth_date', 'xp.request_date');
            $ranges = DB::table('age_ranges')->orderBy('min_age')->get(['id', 'min_age', 'max_age']);
            if ($ranges->isEmpty()) {
                return [];
            }
            $columns = $ranges->map(fn ($r) => sprintf('COUNT(DISTINCT CASE WHEN (%s) >= %d AND (%s) < %d THEN xa.project_id END) as r%d', $age, $r->min_age, $age, $r->max_age, $r->id));
            $row = $query->whereNotNull('xpe.birth_date')->whereNotNull('xp.request_date')->selectRaw($columns->implode(', '))->first();
            $out = [];
            foreach ($ranges as $r) {
                $out[(int) $r->id] = (int) ($row->{'r'.$r->id} ?? 0);
            }

            return $out;
        }
        $column = $key === 'sex' ? 'xpe.sex' : 'xpe.'.ProjectReportFilters::PERSON_LOOKUPS[$key][1];

        return $this->grouped($query, $column, 'COUNT(DISTINCT xa.project_id)');
    }

    /**
     * Profession meetings per value of the three lists their search takes.
     *
     * @param  Closure(array<string, mixed>): Builder  $scope  a query selecting the ids of the meetings that pass the given filters
     * @return array<int|string, int>
     */
    public function meetings(string $key, array $filters, Closure $scope): array
    {
        if (! in_array($key, ['career', 'category', 'subcategory'], true)) {
            return [];
        }
        $f = ProjectReportFilters::clean($filters);
        $f[$key] = [];
        $f['not'] = array_values(array_diff($f['not'], [$key]));
        if ($key === 'career') {
            // A meeting belongs to its own career and to each extra one; UNION keeps a pair once.
            $own = $this->among('profession_meetings as xm', 'xm.id', $scope($f))->select('xm.id as meeting_id', 'xm.career_id');
            $extra = $this->among('profession_meeting_careers as xc', 'xc.profession_meeting_id', $scope($f))->whereNull('xc.deleted_at')
                ->select('xc.profession_meeting_id as meeting_id', 'xc.career_id');

            return $this->grouped(DB::query()->fromSub($own->union($extra), 'xu'), 'xu.career_id', 'COUNT(*)');
        }
        $query = $this->among('profession_meetings as xm', 'xm.id', $scope($f));
        if ($key === 'category') {
            $query->join('project_subcategories as xs', 'xs.id', '=', 'xm.project_subcategory_id');
        }

        return $this->grouped($query, $key === 'category' ? 'xs.project_category_id' : 'xm.project_subcategory_id', 'COUNT(*)');
    }

    /** @return array{request_year: list<int>, fund_year: list<int>} the years found in these cities, newest first */
    public function years(array $cityIds): array
    {
        $ids = $cityIds === [] ? [0] : $cityIds;
        $read = fn (string $table, string $column) => DB::table($table)->whereIn('city_id', $ids)->whereNull('deleted_at')->whereNotNull($column)
            ->selectRaw("DISTINCT SUBSTR($column, 1, 4) as y")->pluck('y')->map(fn ($y) => (int) $y)->filter(fn ($y) => $y >= 1900 && $y <= 2100)->sortDesc()->values()->all();

        return ['request_year' => $read('projects', 'request_date'), 'fund_year' => $read('loans', 'taken_on')];
    }

    private function among(string $table, string $column, Builder $ids): Builder
    {
        return DB::table($table)->whereIn($column, $ids);
    }

    /** One GROUP BY, selected and grouped by the same expression (valid under ONLY_FULL_GROUP_BY). @return array<int|string, int> */
    private function grouped(Builder $query, string $expression, string $count): array
    {
        $out = [];
        foreach ($query->selectRaw("$expression as facet_value, $count as facet_count")->groupByRaw($expression)->get() as $row) {
            if ($row->facet_value !== null) {
                $out[(string) $row->facet_value] = (int) $row->facet_count;
            }
        }

        return $out;
    }
}
