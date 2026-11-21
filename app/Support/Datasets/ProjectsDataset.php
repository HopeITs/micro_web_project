<?php

namespace App\Support\Datasets;

use App\Models\City;
use App\Models\Donor;
use App\Models\FundType;
use App\Models\Project;
use App\Models\ProjectState;
use App\Models\ProjectType;
use App\Services\ProjectNumberAllocator;
use App\Support\ArabicSearch;
use App\Support\CityContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Projects of the permitted cities with their first applicant and current state (the projects list, F3).
 * Aliases on the base query: projects, c (city), p (first applicant person), cs (current state row), st (state).
 */
final class ProjectsDataset extends Dataset
{
    public function key(): string
    {
        return 'projects';
    }

    public function query(): Builder
    {
        $current = DB::table('project_state_history')->select('project_id', 'project_state_id')->where('is_current', true)->whereNull('deleted_at');
        $applicant = DB::table('project_applicants as pa')->join('people as pp', 'pp.id', '=', 'pa.person_id')
            ->whereNull('pa.deleted_at')->select('pa.project_id', DB::raw('MIN(pp.id) as person_id'))->groupBy('pa.project_id');

        // Project::query() carries CityScope: the permitted cities are applied before any filter.
        return Project::query()
            ->join('cities as c', 'c.id', '=', 'projects.city_id')
            ->leftJoinSub($applicant, 'ap', 'ap.project_id', '=', 'projects.id')
            ->leftJoin('people as p', 'p.id', '=', 'ap.person_id')
            ->leftJoinSub($current, 'cs', 'cs.project_id', '=', 'projects.id')
            ->leftJoin('project_states as st', 'st.id', '=', 'cs.project_state_id');
    }

    protected function define(): array
    {
        $en = fn () => app()->getLocale() === 'en';
        $lookup = fn (string $model) => fn () => $model::query()->ordered()->get(['id', 'name_ar', 'name_en'])
            ->mapWithKeys(fn ($row) => [$row->id => $row->label()])->all();

        return [
            new Field('number', 'hc.number', 'number', 'projects.project_number', groupable: false),
            new Field('beneficiary', 'hc.beneficiary', 'text', 'p.first_name', sortable: false, sensitive: true,
                searchColumns: ['p.first_name', 'p.father_name', 'p.last_name']),
            new Field('national_number', 'hc.national_number', 'text', 'p.national_number', sortable: false, sensitive: true),
            new Field('name', 'hc.project', 'text', 'projects.name'),
            new Field('city', 'hc.city', 'lookup', 'projects.city_id', groupable: true, options: function () use ($en) {
                $context = app(CityContext::class);
                $query = City::query()->orderBy('id');
                if ($context->active()) {
                    $query->whereIn('id', $context->cityIds());
                }

                return $query->get(['id', 'name_ar', 'name_en'])->mapWithKeys(fn ($c) => [$c->id => $en() ? $c->name_en : $c->name_ar])->all();
            }),
            new Field('stage', 'hc.stage', 'enum', 'projects.application_stage', groupable: true,
                options: fn () => ['preliminary' => __('hc.stage_preliminary'), 'full' => __('hc.stage_full')]),
            new Field('state', 'hc.state', 'lookup', 'cs.project_state_id', groupable: true, options: $lookup(ProjectState::class)),
            new Field('request_date', 'hc.request_date', 'date', 'projects.request_date', groupable: true),
            new Field('requested_amount', 'hc.requested', 'money', 'projects.requested_amount', aggregatable: true),
            new Field('project_type', 'hc.project_type', 'lookup', 'projects.project_type_id', groupable: true, options: $lookup(ProjectType::class)),
            new Field('fund_type', 'hc.fund_type', 'lookup', 'projects.fund_type_id', groupable: true, options: $lookup(FundType::class)),
            new Field('donor', 'hc.donor', 'lookup', 'projects.donor_id', groupable: true, options: $lookup(Donor::class)),
            new Field('sex', 'hc.beneficiary_sex', 'enum', 'p.sex', groupable: true,
                options: fn () => ['male' => __('hc.sex_male'), 'female' => __('hc.sex_female')]),
        ];
    }

    /**
     * Terms (see SearchTerms) are OR'ed. A term that is a project number (DAM-1001, DAM 1001 or 1001) matches
     * that number (in that city when the code is given) or, when bare, a national number written exactly so;
     * a long run of digits is a national number; any other term needs every word in the project's name, the
     * beneficiary's name (Arabic letters folded) or the national number.
     */
    public function search(Builder $query, string $text): Builder
    {
        $terms = SearchTerms::split($text);
        if ($terms === []) {
            return $query;
        }

        $bare = [];
        $coded = [];
        $nationals = [];
        $texts = [];
        foreach ($terms as $term) {
            $number = ctype_digit($term) && strlen($term) > 10 ? null : ProjectNumberAllocator::parse($term);
            if ($number !== null) {
                [$code, $value] = $number;
                if ($code === null) {
                    $bare[] = $value;
                    $nationals[] = $term;
                } else {
                    $coded[$code][] = $value;
                }
            } elseif (ctype_digit($term)) {
                $nationals[] = $term;
            } else {
                $texts[] = $term;
            }
        }

        return $query->where(function (Builder $any) use ($bare, $coded, $nationals, $texts) {
            if ($bare !== []) {
                $any->orWhereIn('projects.project_number', array_values(array_unique($bare)));
            }
            foreach ($coded as $code => $numbers) {
                $any->orWhere(fn (Builder $q) => $q->where('c.code', $code)->whereIn('projects.project_number', array_values(array_unique($numbers))));
            }
            foreach ($nationals as $national) {
                $any->orWhere('p.national_number', strlen($national) > 10 ? 'like' : '=', strlen($national) > 10 ? ArabicSearch::like($national) : $national);
            }
            foreach ($texts as $term) {
                $any->orWhere(function (Builder $all) use ($term) {
                    foreach (SearchTerms::words($term) as $word) {
                        $all->where(function (Builder $q) use ($word) {
                            foreach (['projects.name', 'p.first_name', 'p.father_name', 'p.last_name'] as $column) {
                                ArabicSearch::orWhereLike($q, $column, $word);
                            }
                            $q->orWhere('p.national_number', 'like', ArabicSearch::like($word));
                        });
                    }
                });
            }
        });
    }
}
