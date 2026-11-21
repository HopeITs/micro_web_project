<?php

namespace App\Support\Datasets;

use App\Models\City;
use App\Models\DonorGroup;
use App\Models\LoanInstallment;
use App\Models\ProjectState;
use App\Services\Finance\FinanceService;
use App\Services\ProjectNumberAllocator;
use App\Support\ArabicSearch;
use App\Support\CityContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Loan instalments of the permitted cities with their loan, project, city, donor group, the project's beneficiary
 * and current state (the payment schedule). Aliases on the base query: loan_installments (the base table, not
 * aliased: CityScope and soft deletes qualify by it), l (loan), p (project), c (city), g (donor group),
 * pe (the project's beneficiary), cs (current state row).
 * The base query leaves out instalments whose loan or project is deleted.
 */
final class InstallmentsDataset extends Dataset
{
    public function key(): string
    {
        return 'installments';
    }

    public function query(): Builder
    {
        $applicant = DB::table('project_applicants as pa')->join('people as pp', 'pp.id', '=', 'pa.person_id')->whereNull('pa.deleted_at')
            ->select('pa.project_id')->selectRaw("COALESCE(MIN(CASE WHEN pa.role = 'مستفيد' THEN pa.id END), MIN(pa.id)) as applicant_id")->groupBy('pa.project_id');
        $current = DB::table('project_state_history')->select('project_id', 'project_state_id')->where('is_current', true)->whereNull('deleted_at');

        // LoanInstallment::query() carries CityScope: the permitted cities are applied before any filter.
        return LoanInstallment::query()
            ->join('loans as l', 'l.id', '=', 'loan_installments.loan_id')
            ->join('projects as p', 'p.id', '=', 'l.project_id')
            ->join('cities as c', 'c.id', '=', 'p.city_id')
            ->leftJoin('donor_groups as g', 'g.id', '=', 'p.donor_group_id')
            ->leftJoinSub($applicant, 'ap', 'ap.project_id', '=', 'p.id')
            ->leftJoin('project_applicants as pb', 'pb.id', '=', 'ap.applicant_id')
            ->leftJoin('people as pe', 'pe.id', '=', 'pb.person_id')
            ->leftJoinSub($current, 'cs', 'cs.project_id', '=', 'p.id')
            ->whereNull('l.deleted_at')->whereNull('p.deleted_at');
    }

    protected function define(): array
    {
        $en = fn () => app()->getLocale() === 'en';
        $lookup = fn (string $model) => fn () => $model::query()->ordered()->get(['id', 'name_ar', 'name_en'])
            ->mapWithKeys(fn ($row) => [$row->id => $row->label()])->all();
        $today = now()->toDateString();
        // Derived exactly as the page shows it: paid, else overdue when due before today, else not yet paid.
        $status = "CASE WHEN loan_installments.is_paid = 1 THEN 'paid' WHEN loan_installments.due_on < '{$today}' THEN 'overdue' ELSE 'unpaid' END";

        return [
            new Field('number', 'hc.number', 'number', 'p.project_number', groupable: false),
            new Field('beneficiary', 'hc.beneficiary', 'text', 'pe.first_name', sortable: false, sensitive: true,
                searchColumns: ['pe.first_name', 'pe.father_name', 'pe.last_name']),
            new Field('national_number', 'hc.national_number', 'text', 'pe.national_number', sortable: false, sensitive: true),
            new Field('name', 'hc.project', 'text', 'p.name'),
            new Field('city', 'hc.city', 'lookup', 'loan_installments.city_id', groupable: true, options: function () use ($en) {
                $context = app(CityContext::class);
                $query = City::query()->orderBy('id');
                if ($context->active()) {
                    $query->whereIn('id', $context->cityIds());
                }

                return $query->get(['id', 'name_ar', 'name_en'])->mapWithKeys(fn ($c) => [$c->id => $en() ? $c->name_en : $c->name_ar])->all();
            }),
            new Field('state', 'hc.state', 'lookup', 'cs.project_state_id', groupable: true, options: $lookup(ProjectState::class)),
            new Field('group', 'finance.payments.group', 'lookup', 'p.donor_group_id', groupable: true, options: $lookup(DonorGroup::class)),
            new Field('status', 'finance.payments.columns.status', 'enum', $status, sortable: false, groupable: true,
                options: fn () => ['paid' => __('finance.installments.paid'), 'overdue' => __('finance.installments.overdue'), 'unpaid' => __('finance.installments.not_paid')]),
            new Field('due_on', 'finance.payments.columns.due', 'date', 'loan_installments.due_on', groupable: true),
            new Field('received_on', 'finance.payments.columns.received', 'date', 'loan_installments.received_on'),
            new Field('amount', 'finance.payments.columns.amount', 'money', 'loan_installments.amount', aggregatable: true),
            new Field('paid_amount', 'hc.paid_amount', 'money', 'CASE WHEN loan_installments.is_paid = 1 THEN loan_installments.amount ELSE 0 END', sortable: false, aggregatable: true),
            new Field('currency', 'finance.payments.currency', 'enum', 'l.currency', groupable: true,
                options: fn () => array_combine(FinanceService::CURRENCIES, FinanceService::CURRENCIES)),
        ];
    }

    /**
     * Same rules as the projects list (see SearchTerms): a term that is a project number (DAM-1001, DAM 1001 or 1001)
     * matches that number (in that city when the code is given) or, when bare, a national number written exactly so;
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
                $any->orWhereIn('p.project_number', array_values(array_unique($bare)));
            }
            foreach ($coded as $code => $numbers) {
                $any->orWhere(fn (Builder $q) => $q->where('c.code', $code)->whereIn('p.project_number', array_values(array_unique($numbers))));
            }
            foreach ($nationals as $national) {
                $any->orWhere('pe.national_number', strlen($national) > 10 ? 'like' : '=', strlen($national) > 10 ? ArabicSearch::like($national) : $national);
            }
            foreach ($texts as $term) {
                $any->orWhere(function (Builder $all) use ($term) {
                    foreach (SearchTerms::words($term) as $word) {
                        $all->where(function (Builder $q) use ($word) {
                            foreach (['p.name', 'pe.first_name', 'pe.father_name', 'pe.last_name'] as $column) {
                                ArabicSearch::orWhereLike($q, $column, $word);
                            }
                            $q->orWhere('pe.national_number', 'like', ArabicSearch::like($word));
                        });
                    }
                });
            }
        });
    }
}
