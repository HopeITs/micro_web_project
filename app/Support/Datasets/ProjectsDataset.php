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
use App\Support\ProjectStatus;
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

    public function viewCapabilities(): array
    {
        return ['projects.view'];
    }

    public function listRoute(): ?string
    {
        return 'projects.index';
    }

    public function listParams(): array
    {
        return ['city' => ['city', true]];
    }

    public function query(): Builder
    {
        $current = DB::table('project_state_history')->select('project_id', 'project_state_id', 'changed_on')->where('is_current', true)->whereNull('deleted_at');
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

        // Related values as correlated scalar subqueries: one value per project row, no GROUP BY on the outer query
        // (valid under MariaDB ONLY_FULL_GROUP_BY), usable in a select, a where and an order by.
        $loanAmount = '(SELECT SUM(l.amount) FROM loans l WHERE l.project_id = projects.id AND l.deleted_at IS NULL)';
        $overdue = '(SELECT COUNT(*) FROM loan_installments li JOIN loans lo ON lo.id = li.loan_id WHERE lo.project_id = projects.id'
            .' AND lo.deleted_at IS NULL AND li.deleted_at IS NULL AND li.is_paid = 0 AND li.due_on < CURRENT_DATE)';

        return [
            new Field('number', 'hc.number', 'number', 'projects.project_number', groupable: false,
                with: ['row_city_code' => 'c.code'],
                cell: fn (object $row) => [
                    'text' => ProjectNumberAllocator::display((string) $row->row_city_code, (int) $row->col_number),
                    'href' => route('projects.show', ['locale' => app()->getLocale(), 'project' => $row->id]), 'ltr' => false, 'class' => 'hc-pnum',
                ]),
            new Field('beneficiary', 'hc.beneficiary', 'text', 'p.first_name', sortable: false, sensitive: true,
                searchColumns: ['p.first_name', 'p.father_name', 'p.last_name'],
                with: ['row_father_name' => 'p.father_name', 'row_last_name' => 'p.last_name'],
                cell: fn (object $row) => ['text' => trim(preg_replace('/\s+/u', ' ', $row->col_beneficiary.' '.$row->row_father_name.' '.$row->row_last_name))]),
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
                options: fn () => ['preliminary' => __('hc.stage_preliminary'), 'full' => __('hc.stage_full')],
                cell: fn (object $row) => $row->col_stage === null ? [] : ['badge' => true, 'tone' => $row->col_stage === 'full' ? 'brand' : null]),
            new Field('state', 'hc.state', 'lookup', 'cs.project_state_id', groupable: true, options: $lookup(ProjectState::class),
                with: ['row_state_en' => 'st.name_en'],
                cell: fn (object $row) => $row->col_state === null ? [] : ['badge' => true, 'tone' => ProjectStatus::tone($row->row_state_en), 'icon' => ProjectStatus::icon($row->row_state_en)]),
            new Field('state_changed_on', 'hc.state_changed_on', 'date', 'cs.changed_on'),
            new Field('request_date', 'hc.request_date', 'date', 'projects.request_date', groupable: true),
            new Field('requested_amount', 'hc.requested', 'money', 'projects.requested_amount', aggregatable: true),
            new Field('project_type', 'hc.project_type', 'lookup', 'projects.project_type_id', groupable: true, options: $lookup(ProjectType::class)),
            new Field('fund_type', 'hc.fund_type', 'lookup', 'projects.fund_type_id', groupable: true, options: $lookup(FundType::class)),
            new Field('donor', 'hc.donor', 'lookup', 'projects.donor_id', groupable: true, options: $lookup(Donor::class)),
            new Field('sex', 'hc.beneficiary_sex', 'enum', 'p.sex', groupable: true,
                options: fn () => ['male' => __('hc.sex_male'), 'female' => __('hc.sex_female')]),
            new Field('mobile', 'hc.beneficiary_mobile', 'text', 'p.mobile', sortable: false, sensitive: true, cell: fn () => ['ltr' => true]),
            new Field('loan_amount', 'hc.loan_amount', 'money', $loanAmount, aggregatable: true),
            new Field('overdue_installments', 'hc.overdue_installments', 'number', $overdue),
        ];
    }

    public function defaultColumns(): array
    {
        return ['number', 'beneficiary', 'name', 'city', 'stage', 'state', 'request_date', 'requested_amount'];
    }

    /** Newest requests first, as the list always opened. */
    protected function defaultOrder(Builder $query): void
    {
        $query->orderByDesc('projects.request_date')->orderBy('c.code')->orderByDesc('projects.project_number');
    }

    protected function rowSelect(): array
    {
        return ['projects.id as id'];
    }

    public function cityColumn(): string
    {
        return 'projects.city_id';
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
