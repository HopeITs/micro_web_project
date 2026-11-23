<?php

namespace App\Support\Datasets;

use App\Models\City;
use App\Models\EducationLevel;
use App\Models\MaritalStatus;
use App\Models\Person;
use App\Models\ProjectState;
use App\Services\People\PeopleService;
use App\Services\ProjectNumberAllocator;
use App\Support\ArabicSearch;
use App\Support\CityContext;
use App\Support\ProjectStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * People of the permitted cities with their family book, project count and latest education (the people list).
 * Aliases on the base query: people, c (city), fm/f (first family and its book), pa (project count), le/pe (latest education row).
 * Names, national number, mobile and family book number are sensitive (visible on screen today, so exportable but logged as sensitive).
 */
final class PeopleDataset extends Dataset
{
    public function key(): string
    {
        return 'people';
    }

    public function viewCapabilities(): array
    {
        return ['people.view'];
    }

    public function listRoute(): ?string
    {
        return 'people.index';
    }

    public function listParams(): array
    {
        return ['city' => ['city', true]];
    }

    public function query(): Builder
    {
        $family = DB::table('family_members')->whereNull('deleted_at')->selectRaw('person_id, MIN(family_id) as family_id')->groupBy('person_id');
        $projects = DB::table('project_applicants')->whereNull('deleted_at')->selectRaw('person_id, COUNT(*) as n')->groupBy('person_id');
        $latestEducation = DB::table('person_educations')->whereNull('deleted_at')->selectRaw('person_id, MAX(id) as education_id')->groupBy('person_id');

        // Person::query() carries CityScope (and the soft-delete scope): the permitted cities apply before any filter.
        return Person::query()
            ->join('cities as c', 'c.id', '=', 'people.city_id')
            ->leftJoinSub($family, 'fm', 'fm.person_id', '=', 'people.id')->leftJoin('families as f', 'f.id', '=', 'fm.family_id')
            ->leftJoinSub($projects, 'pa', 'pa.person_id', '=', 'people.id')
            ->leftJoinSub($latestEducation, 'le', 'le.person_id', '=', 'people.id')->leftJoin('person_educations as pe', 'pe.id', '=', 'le.education_id');
    }

    protected function define(): array
    {
        $en = fn () => app()->getLocale() === 'en';
        $lookup = fn (string $model) => fn () => $model::query()->ordered()->get(['id', 'name_ar', 'name_en'])
            ->mapWithKeys(fn ($row) => [$row->id => $row->label()])->all();
        // Numeric expressions carry an integer type so a value typed as text compares as a number on every database.
        $sqlite = DB::getDriverName() === 'sqlite';
        $int = $sqlite ? 'INTEGER' : 'SIGNED';
        $age = $sqlite ? "(julianday('now') - julianday(people.birth_date)) / 365.25" : 'TIMESTAMPDIFF(YEAR, people.birth_date, CURDATE())';
        $year = $sqlite ? "CAST(strftime('%Y', people.birth_date) AS INTEGER)" : 'YEAR(people.birth_date)';
        // Related values as correlated scalar subqueries (one value per person, no GROUP BY); the latest project is the highest project id the person applied to.
        $latest = '(SELECT pr.id FROM project_applicants la JOIN projects pr ON pr.id = la.project_id WHERE la.person_id = people.id AND la.deleted_at IS NULL AND pr.deleted_at IS NULL ORDER BY pr.id DESC LIMIT 1)';
        $latestState = "(SELECT h.project_state_id FROM project_state_history h WHERE h.project_id = {$latest} AND h.is_current = 1 AND h.deleted_at IS NULL LIMIT 1)";
        $familySize = '(SELECT COUNT(*) FROM family_members fs WHERE fs.family_id = fm.family_id AND fs.deleted_at IS NULL)';

        return [
            new Field('name', 'people.name', 'text', 'people.first_name', filterable: false, sensitive: true,
                searchColumns: ['people.first_name', 'people.father_name', 'people.last_name'],
                with: ['row_father_name' => 'people.father_name', 'row_last_name' => 'people.last_name'],
                cell: fn (object $row) => [
                    'text' => PeopleService::fullName((object) ['first_name' => $row->col_name, 'father_name' => $row->row_father_name, 'last_name' => $row->row_last_name]),
                    'href' => route('people.show', ['locale' => app()->getLocale(), 'person' => $row->id]), 'ltr' => false,
                ]),
            new Field('national_number', 'people.fields.national_number', 'text', 'people.national_number', filterable: false, sortable: false, sensitive: true, cell: fn () => ['ltr' => true]),
            new Field('mobile', 'people.fields.mobile', 'text', 'people.mobile', filterable: false, sortable: false, sensitive: true, searchColumns: ['people.mobile', 'people.mobile_2'], cell: fn () => ['ltr' => true]),
            new Field('family_number', 'people.family_number', 'text', 'f.family_number', filterable: false, sortable: false, sensitive: true, cell: fn () => ['ltr' => true]),
            new Field('city', 'hc.city', 'lookup', 'people.city_id', groupable: true, options: function () use ($en) {
                $context = app(CityContext::class);
                $query = City::query()->orderBy('id');
                if ($context->active()) {
                    $query->whereIn('id', $context->cityIds());
                }

                return $query->get(['id', 'name_ar', 'name_en'])->mapWithKeys(fn ($c) => [$c->id => $en() ? $c->name_en : $c->name_ar])->all();
            }),
            new Field('sex', 'hc.person_sex', 'enum', 'people.sex', groupable: true,
                options: fn () => ['male' => __('hc.sex_male'), 'female' => __('hc.sex_female')]),
            new Field('age', 'hc.age', 'number', "CAST({$age} AS {$int})"),
            new Field('birth_date', 'hc.birth_date', 'date', 'people.birth_date'),
            new Field('marital_status', 'hc.marital_status', 'lookup', 'people.marital_status_id', groupable: true, options: $lookup(MaritalStatus::class)),
            new Field('education', 'hc.education_level', 'lookup', 'pe.education_level_id', groupable: true, options: $lookup(EducationLevel::class)),
            new Field('has_projects', 'people.projects_filter', 'enum', "CASE WHEN pa.n IS NULL THEN 'without' ELSE 'with' END", groupable: true,
                options: fn () => ['with' => __('people.with_projects'), 'without' => __('people.without_projects')]),
            new Field('projects_count', 'people.projects', 'number', "CAST(COALESCE(pa.n, 0) AS {$int})", aggregatable: true,
                cell: fn (object $row) => ['text' => (string) (int) $row->col_projects_count, 'export' => (string) (int) $row->col_projects_count,
                    'badge' => (int) $row->col_projects_count > 0, 'tone' => 'brand', 'ltr' => false]),
            new Field('registered', 'hc.registered_on', 'date', 'people.created_at'),
            new Field('birth_year', 'people.birth_year', 'number', $year, filterable: false,
                cell: fn (object $row) => ['text' => (string) ($row->col_birth_year ?? ''), 'export' => $row->col_birth_year === null ? null : (string) $row->col_birth_year, 'ltr' => false]),
            new Field('family_size', 'people.family_size', 'number', $familySize, filterable: false),
            new Field('latest_project', 'people.latest_project', 'number', "(SELECT project_number FROM projects WHERE id = {$latest})", filterable: false,
                with: ['row_city_code' => "(SELECT c2.code FROM projects p2 JOIN cities c2 ON c2.id = p2.city_id WHERE p2.id = {$latest})"],
                cell: fn (object $row) => $row->col_latest_project === null ? [] : [
                    'text' => ProjectNumberAllocator::display((string) $row->row_city_code, (int) $row->col_latest_project), 'ltr' => false, 'class' => 'hc-pnum']),
            new Field('latest_state', 'people.latest_state', 'lookup', $latestState, filterable: false, sortable: false, options: $lookup(ProjectState::class),
                with: ['row_state_en' => "(SELECT st2.name_en FROM project_states st2 WHERE st2.id = {$latestState})"],
                cell: fn (object $row) => $row->col_latest_state === null ? [] : ['badge' => true, 'tone' => ProjectStatus::tone($row->row_state_en), 'icon' => ProjectStatus::icon($row->row_state_en)]),
        ];
    }

    public function defaultColumns(): array
    {
        return ['name', 'national_number', 'birth_year', 'mobile', 'family_number', 'city', 'projects_count'];
    }

    /** By name, as the list always opened. */
    protected function defaultOrder(Builder $query): void
    {
        $query->orderBy('people.first_name')->orderBy('people.last_name');
    }

    protected function rowSelect(): array
    {
        return ['people.id as id'];
    }

    /**
     * Terms (see SearchTerms) are OR'ed. A term of digits, spaces, "+" and "-" (at least three digits) matches the national number
     * (without its leading zeros), either mobile or the family book number; any other term needs every word in a name part
     * (Arabic letters folded), the English full name or the family book number.
     */
    public function search(Builder $query, string $text): Builder
    {
        $terms = SearchTerms::split($text);
        if ($terms === []) {
            return $query;
        }

        return $query->where(function (Builder $any) use ($terms) {
            foreach ($terms as $term) {
                $digits = preg_replace('/\D/', '', $term);
                if ($digits !== '' && strlen($digits) >= 3 && preg_match('/^[\d\s+\-]+$/', $term) === 1) {
                    $any->orWhere(fn (Builder $q) => $q->where('people.national_number', 'like', '%'.ltrim($digits, '0').'%')
                        ->orWhere('people.mobile', 'like', '%'.$digits.'%')->orWhere('people.mobile_2', 'like', '%'.$digits.'%')
                        ->orWhere('f.family_number', 'like', '%'.$digits.'%'));

                    continue;
                }
                $any->orWhere(function (Builder $all) use ($term) {
                    foreach (SearchTerms::words($term) as $word) {
                        $like = ArabicSearch::like($word);
                        $all->where(function (Builder $q) use ($word, $like) {
                            foreach (['people.first_name', 'people.father_name', 'people.last_name'] as $column) {
                                ArabicSearch::orWhereLike($q, $column, $word);
                            }
                            $q->orWhere('people.full_name_en', 'like', $like)->orWhere('f.family_number', 'like', $like);
                        });
                    }
                });
            }
        });
    }
}
