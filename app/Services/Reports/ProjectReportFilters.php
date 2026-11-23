<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Services\Finance\Fields;
use App\Services\ProjectNumberAllocator;
use App\Support\ArabicSearch;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The common filter set of the desktop search panel (M4.17), shared by Search and, later, Statistics. It works on any query over the
 * `projects` table and knows nothing about the search modes.
 *
 * Aliases the caller provides:
 *   p   `projects` (required; deleted rows are the caller's business)
 *   ci  `cities`   (only for the text box, which accepts "DAM-1041"; pass `$cityAlias` to change it, null to skip the city code)
 * Everything else is a correlated sub-query with its own alias (`cs*`, `fa*`, `fl*`), so no other join is needed and the filters can be
 * added to a query that groups or joins freely:
 *   status            the state in force (project_state_history.is_current)
 *   parish, marital, home street, sex, age   the beneficiary (project_applicants role "مستفيد"), age counted at the request date
 *   fund date / amount                          the project's loan (taken_on, amount; USD = amount / exchange_rate for SYP)
 * Filters are lists of lookup ids (a project matches any of them); ranges are inclusive and open at an empty end.
 */
final class ProjectReportFilters
{
    public const BENEFICIARY_ROLE = 'مستفيد';

    /** Filter key => [options table, project column]. */
    public const PROJECT_LOOKUPS = [
        'fund_type' => ['fund_types', 'fund_type_id'], 'project_type' => ['project_types', 'project_type_id'], 'subtype' => ['project_subtypes', 'project_subtype_id'],
        'donor' => ['donors', 'donor_id'], 'donor_group' => ['donor_groups', 'donor_group_id'], 'category' => ['project_categories', 'project_category_id'],
        'subcategory' => ['project_subcategories', 'project_subcategory_id'], 'career' => ['careers', 'career_id'], 'project_street' => ['streets', 'street_id'],
    ];

    /** Filter key => [options table, people column] (the beneficiary's). */
    public const PERSON_LOOKUPS = ['parish' => ['parishes', 'parish_id'], 'marital' => ['marital_statuses', 'marital_status_id'], 'home_street' => ['streets', 'street_id']];

    public const LISTS = ['status', ...[
        'fund_type', 'project_type', 'subtype', 'donor', 'donor_group', 'category', 'subcategory', 'career', 'project_street', 'parish', 'marital', 'home_street', 'age',
    ]];

    /** `meeting_*` belong to the profession meetings search only; project queries ignore them. */
    public const DATES = ['request_from', 'request_to', 'fund_from', 'fund_to', 'meeting_from', 'meeting_to'];

    public const AMOUNTS = ['amount_from', 'amount_to', 'usd_from', 'usd_to'];

    /** @return array<string, mixed> validation rules for the request/Livewire state of these filters */
    public static function rules(): array
    {
        $rules = ['q' => ['nullable', 'string', 'max:100'], 'partnership' => ['nullable', 'in:individual,partnership'], 'sex' => ['sometimes', 'array', 'max:2'], 'sex.*' => ['in:male,female']];
        foreach (self::LISTS as $key) {
            $rules[$key] = ['sometimes', 'array', 'max:200'];
            $rules[$key.'.*'] = ['integer', 'min:1'];
        }
        foreach (self::DATES as $key) {
            $rules[$key] = ['nullable', 'date_format:Y-m-d', 'after_or_equal:1900-01-01', 'before_or_equal:2100-12-31'];
        }
        foreach (self::AMOUNTS as $key) {
            $rules[$key] = ['nullable', 'numeric', 'min:0', 'max:1000000000000'];
        }

        return $rules;
    }

    /**
     * The filters as the page and the export use them: unknown keys dropped, lists of positive ints, valid dates, numeric amounts.
     * An end before its start is left as is (the result is simply empty).
     *
     * @return array<string, mixed>
     */
    public static function clean(array $input): array
    {
        $out = ['q' => mb_substr(trim(is_string($input['q'] ?? null) ? $input['q'] : ''), 0, 100)];
        foreach (self::LISTS as $key) {
            $out[$key] = array_values(array_unique(array_filter(array_map(
                fn ($v) => (is_int($v) || (is_string($v) && ctype_digit($v))) && (int) $v > 0 ? (int) $v : null, array_slice((array) ($input[$key] ?? []), 0, 200)))));
        }
        $out['sex'] = array_values(array_intersect((array) ($input['sex'] ?? []), ['male', 'female']));
        $out['partnership'] = in_array($input['partnership'] ?? '', ['individual', 'partnership'], true) ? $input['partnership'] : '';
        foreach (self::DATES as $key) {
            $out[$key] = is_string($input[$key] ?? null) && Fields::isDate($input[$key]) ? $input[$key] : '';
        }
        foreach (self::AMOUNTS as $key) {
            $v = $input[$key] ?? '';
            $out[$key] = (is_string($v) || is_int($v) || is_float($v)) && is_numeric($v) && (float) $v >= 0 ? (string) $v : '';
        }

        return $out;
    }

    /** Whether any filter (other than the text box) narrows the result. */
    public static function active(array $filters): bool
    {
        foreach (self::clean($filters) as $key => $value) {
            if ($key !== 'q' && $value !== [] && $value !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * Option lists for the filter panel, names in the current language.
     *
     * @param  list<int>  $cityIds  the cities in scope (streets are per city)
     * @return array<string, list<array{id: int, label: string}>>
     */
    public static function options(array $cityIds): array
    {
        $en = app()->getLocale() === 'en';
        $pick = fn ($r) => (string) ($en && ($r->name_en ?? '') !== '' ? $r->name_en : $r->name_ar);
        $list = function (string $table, bool $perCity = false) use ($cityIds, $pick) {
            $q = DB::table($table)->when($table !== 'age_ranges', fn ($q) => $q->where('is_active', true))->orderBy('sort_order')->orderBy('id');
            if ($perCity) {
                $q->whereIn('city_id', $cityIds ?: [0])->orderBy('city_id');
            }

            return $q->get()->map(fn ($r) => ['id' => (int) $r->id, 'label' => $pick($r)])->sortBy('label', SORT_NATURAL | SORT_FLAG_CASE)->values()->all();
        };
        $out = [];
        foreach (self::PROJECT_LOOKUPS + self::PERSON_LOOKUPS as $key => [$table]) {
            $out[$key] = $list($table, $table === 'streets');
        }
        $out['status'] = DB::table('project_states')->orderBy('sort_order')->orderBy('id')->get()->map(fn ($r) => ['id' => (int) $r->id, 'label' => $pick($r)])->all();
        $out['age'] = DB::table('age_ranges')->orderBy('min_age')->get()->map(fn ($r) => ['id' => (int) $r->id, 'label' => $pick($r)])->all();
        $out['sex'] = [['id' => 'male', 'label' => __('search.sex.male')], ['id' => 'female', 'label' => __('search.sex.female')]];

        return $out;
    }

    /**
     * Narrows a projects query. `$cityAlias` is the alias of `cities` for the project number in the text box ("DAM-1041").
     *
     * @param  array<string, mixed>  $filters  as returned by clean()
     */
    public static function apply(Builder $query, array $filters, ?string $cityAlias = 'ci'): Builder
    {
        $f = self::clean($filters);
        foreach (self::PROJECT_LOOKUPS as $key => [, $column]) {
            $f[$key] !== [] && $query->whereIn('p.'.$column, $f[$key]);
        }
        if ($f['status'] !== []) {
            $query->whereExists(fn ($q) => $q->selectRaw('1')->from('project_state_history as cs1')->whereColumn('cs1.project_id', 'p.id')
                ->where('cs1.is_current', true)->whereNull('cs1.deleted_at')->whereIn('cs1.project_state_id', $f['status']));
        }
        $f['partnership'] === 'individual' && $query->where('p.partnership', 1);
        $f['partnership'] === 'partnership' && $query->where('p.partnership', '>', 1);
        $f['request_from'] !== '' && $query->where('p.request_date', '>=', $f['request_from']);
        $f['request_to'] !== '' && $query->where('p.request_date', '<=', $f['request_to']);
        $f['amount_from'] !== '' && $query->where('p.requested_amount', '>=', $f['amount_from']);
        $f['amount_to'] !== '' && $query->where('p.requested_amount', '<=', $f['amount_to']);

        $personal = array_filter([$f['parish'], $f['marital'], $f['home_street'], $f['sex'], $f['age']], fn ($v) => $v !== []);
        if ($personal !== []) {
            $query->whereExists(function ($q) use ($f) {
                $q->selectRaw('1')->from('project_applicants as fa1')->join('people as fp1', 'fp1.id', '=', 'fa1.person_id')->whereColumn('fa1.project_id', 'p.id')
                    ->whereNull('fa1.deleted_at')->whereNull('fp1.deleted_at')->where('fa1.role', self::BENEFICIARY_ROLE);
                foreach (self::PERSON_LOOKUPS as $key => [, $column]) {
                    $f[$key] !== [] && $q->whereIn('fp1.'.$column, $f[$key]);
                }
                $f['sex'] !== [] && $q->whereIn('fp1.sex', $f['sex']);
                if ($f['age'] !== []) {
                    $ranges = DB::table('age_ranges')->whereIn('id', $f['age'])->get(['min_age', 'max_age']);
                    $age = self::ageAtRequest('fp1.birth_date', 'p.request_date');
                    // Every chosen range applies (the desktop used the first only); a range is [min, max).
                    $q->where(function ($or) use ($ranges, $age) {
                        $or->whereRaw('1 = 0');
                        foreach ($ranges as $r) {
                            $or->orWhereRaw("($age) >= ? and ($age) < ?", [(int) $r->min_age, (int) $r->max_age]);
                        }
                    });
                    $q->whereNotNull('fp1.birth_date')->whereNotNull('p.request_date');
                }
            });
        }

        $funded = array_filter([$f['fund_from'], $f['fund_to'], $f['usd_from'], $f['usd_to']], fn ($v) => $v !== '');
        if ($funded !== []) {
            $usd = self::usdSql('fl1');
            $query->whereExists(function ($q) use ($f, $usd) {
                $q->selectRaw('1')->from('loans as fl1')->whereColumn('fl1.project_id', 'p.id')->whereNull('fl1.deleted_at');
                $f['fund_from'] !== '' && $q->where('fl1.taken_on', '>=', $f['fund_from']);
                $f['fund_to'] !== '' && $q->where('fl1.taken_on', '<=', $f['fund_to']);
                $f['usd_from'] !== '' && $q->whereRaw("$usd >= (? + 0)", [(float) $f['usd_from']]);
                $f['usd_to'] !== '' && $q->whereRaw("$usd <= (? + 0)", [(float) $f['usd_to']]);
            });
        }

        if ($f['q'] !== '') {
            self::text($query, $f['q'], $cityAlias);
        }

        return $query;
    }

    /** SQL: whole years between a birth date and a reference date (both SQL fragments), valid on MariaDB and SQLite. */
    public static function ageAtRequest(string $birth, string $at): string
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return "(CAST(strftime('%Y', $at) AS INTEGER) - CAST(strftime('%Y', $birth) AS INTEGER) - (CASE WHEN strftime('%m%d', $at) < strftime('%m%d', $birth) THEN 1 ELSE 0 END))";
        }

        return "TIMESTAMPDIFF(YEAR, $birth, $at)";
    }

    /** SQL: the loan's amount in US dollars rounded to two places (`SYP`/`SP`: amount / rate, `USD`: amount; NULL without a rate). */
    public static function usdSql(string $loan): string
    {
        return "ROUND(CASE WHEN $loan.currency = 'USD' THEN $loan.amount WHEN $loan.currency IN ('SYP', 'SP') THEN $loan.amount / NULLIF($loan.exchange_rate, 0) END, 2)";
    }

    /** The text box: a project number ("1041", "DAM-1041") or words of the project's name or of a linked person's names (Arabic folded). */
    public static function text(Builder $query, string $term, ?string $cityAlias = 'ci'): void
    {
        $number = ProjectNumberAllocator::parse($term);
        $words = array_slice(preg_split('/\s+/u', $term, -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, 5);
        $query->where(function ($q) use ($number, $words, $cityAlias) {
            if ($number !== null) {
                $q->orWhere(fn ($n) => $n->where('p.project_number', $number[1])->when($number[0] !== null && $cityAlias !== null, fn ($c) => $c->where($cityAlias.'.code', $number[0])));
            }
            $q->orWhere(function ($all) use ($words) {
                foreach ($words as $word) {
                    $all->where(function ($w) use ($word) {
                        ArabicSearch::orWhereLike($w, 'p.name', $word);
                        $w->orWhereExists(function ($e) use ($word) {
                            $e->selectRaw('1')->from('project_applicants as ta1')->join('people as tp1', 'tp1.id', '=', 'ta1.person_id')->whereColumn('ta1.project_id', 'p.id')
                                ->whereNull('ta1.deleted_at')->whereNull('tp1.deleted_at')->where(function ($n) use ($word) {
                                    foreach (['tp1.first_name', 'tp1.last_name', 'tp1.father_name'] as $column) {
                                        ArabicSearch::orWhereLike($n, $column, $word);
                                    }
                                });
                        });
                    });
                }
            });
        });
    }
}
