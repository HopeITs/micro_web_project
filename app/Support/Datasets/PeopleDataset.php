<?php

namespace App\Support\Datasets;

use App\Models\City;
use App\Models\EducationLevel;
use App\Models\MaritalStatus;
use App\Models\Person;
use App\Support\ArabicSearch;
use App\Support\CityContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * People of the permitted cities with their family book, project count and latest education (the people list).
 * Aliases on the base query: people, c (city), fm/f (first family and its book), pa (project count), le/pe (latest education row).
 * Names, national number, mobile and family book number are sensitive: found through the search box only.
 */
final class PeopleDataset extends Dataset
{
    public function key(): string
    {
        return 'people';
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

        return [
            new Field('name', 'people.name', 'text', 'people.first_name', filterable: false, sortable: false, exportable: false, sensitive: true,
                searchColumns: ['people.first_name', 'people.father_name', 'people.last_name']),
            new Field('national_number', 'people.fields.national_number', 'text', 'people.national_number', filterable: false, sortable: false, sensitive: true),
            new Field('mobile', 'people.fields.mobile', 'text', 'people.mobile', filterable: false, sortable: false, sensitive: true, searchColumns: ['people.mobile', 'people.mobile_2']),
            new Field('family_number', 'people.family_number', 'text', 'f.family_number', filterable: false, sortable: false, sensitive: true),
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
            new Field('projects_count', 'people.projects', 'number', "CAST(COALESCE(pa.n, 0) AS {$int})", aggregatable: true),
            new Field('registered', 'hc.registered_on', 'date', 'people.created_at'),
        ];
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
