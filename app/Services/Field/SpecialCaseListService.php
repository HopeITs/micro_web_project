<?php

declare(strict_types=1);

namespace App\Services\Field;

use App\Models\WebAccount;
use App\Services\AccountAccess;
use App\Services\AccountAuditTrail;
use App\Services\Finance\Fields;
use App\Services\ProjectNumberAllocator;
use App\Support\ArabicSearch;
use App\Support\TableTools\TableSort;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The list of all special cases (M4.13, owner decision 5; desktop `Search_Form.bind_SpecialCases`, search mode 7) across the cities where
 * the account holds `applications.view` + `special_cases.view`: the desktop Search columns, newest first or by any column header. Filters: date from/to, type,
 * recorder, current project state, cities and a text (reason, description, action, beneficiary, project number). The export also needs
 * `reports.export` and covers only the cities where it is held. M4.17 Search reuses this query.
 */
final class SpecialCaseListService
{
    public const PER_PAGE = 50;

    /** The sortable columns, in the order of headings(). */
    public const SORT_KEYS = ['city', 'number', 'beneficiary', 'father', 'career', 'category', 'subcategory', 'date', 'recorder', 'type', 'reason', 'description', 'action'];

    public function __construct(private AccountAccess $access, private AccountAuditTrail $audit) {}

    public function cities(WebAccount $account): Collection
    {
        return $this->access->citiesWithAll($account, ['applications.view', 'special_cases.view']);
    }

    private function permitted(WebAccount $account, bool $export): Collection
    {
        $permitted = $this->cities($account);
        if ($export) {
            $permitted = $permitted->filter(fn ($city) => $this->access->check($account, (int) $city->id, 'reports.export'))->values();
        }
        if ($permitted->isEmpty()) {
            $this->audit->record($account->id, 'access.denied', 'denied');
            abort(403);
        }

        return $permitted;
    }

    public function opened(WebAccount $account): void
    {
        $this->permitted($account, false);
        $this->audit->record($account->id, 'special_cases.list', 'succeeded');
    }

    /**
     * The filters, cleaned (from, to, type, recorder, state, cities, q, sort, dir). A start after the end is refused with the desktop wording.
     *
     * @throws ValidationException
     */
    public static function clean(array $input): array
    {
        $int = fn ($v) => (is_int($v) || is_string($v)) && ($i = filter_var($v, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])) !== false ? $i : null;
        $date = fn ($v) => is_string($v) && Fields::isDate($v) ? $v : null;
        $filters = [
            'from' => $date($input['from'] ?? null), 'to' => $date($input['to'] ?? null), 'type' => $int($input['type'] ?? null),
            'recorder' => $int($input['recorder'] ?? null), 'state' => $int($input['state'] ?? null),
            'cities' => array_values(array_filter(array_map(fn ($c) => $int($c), (array) ($input['cities'] ?? [])))),
            'q' => mb_substr(trim(is_string($input['q'] ?? null) ? $input['q'] : ''), 0, 100),
        ];
        [$filters['sort'], $dir] = TableSort::clean($input['sort'] ?? null, $input['dir'] ?? null, self::SORT_KEYS);
        $filters['dir'] = $filters['sort'] === null ? null : $dir;
        if ($filters['from'] !== null && $filters['to'] !== null && $filters['from'] > $filters['to']) {
            throw ValidationException::withMessages(['from' => __('special_cases.errors.range')]);
        }

        return $filters;
    }

    private function ids(Collection $permitted, array $filters): array
    {
        $all = $permitted->pluck('id')->map(fn ($id) => (int) $id)->all();
        $selected = array_values(array_intersect($filters['cities'], $all));

        return $selected !== [] ? $selected : $all;
    }

    /** The filtered cases s with their project p, type t, recorder w, career and categories. */
    private function query(array $cities, array $filters): Builder
    {
        $query = DB::table('special_cases as s')->join('projects as p', 'p.id', '=', 's.project_id')->join('cities as ci', 'ci.id', '=', 's.city_id')
            ->leftJoin('special_case_types as t', 't.id', '=', 's.special_case_type_id')->leftJoin('web_accounts as w', 'w.id', '=', 's.account_id')
            ->leftJoin('careers as cr', 'cr.id', '=', 'p.career_id')->leftJoin('project_categories as pc', 'pc.id', '=', 'p.project_category_id')
            ->leftJoin('project_subcategories as ps', 'ps.id', '=', 'p.project_subcategory_id')
            ->whereIn('s.city_id', $cities)->whereNull('s.deleted_at')->whereNull('p.deleted_at');
        ($filters['invalid'] ?? false) && $query->whereRaw('1 = 0');
        $filters['from'] && $query->where('s.case_date', '>=', $filters['from']);
        $filters['to'] && $query->where('s.case_date', '<=', $filters['to']);
        $filters['type'] && $query->where('s.special_case_type_id', $filters['type']);
        $filters['recorder'] && $query->where('s.account_id', $filters['recorder']);
        if ($filters['state']) {
            $query->whereExists(fn ($q) => $q->selectRaw('1')->from('project_state_history as h')->whereColumn('h.project_id', 'p.id')
                ->where('h.is_current', true)->where('h.project_state_id', $filters['state']));
        }
        if ($filters['q'] !== '') {
            $term = $filters['q'];
            $number = ProjectNumberAllocator::parse($term);
            $query->where(function ($q) use ($term, $number) {
                foreach (['s.reason', 's.description', 's.action'] as $column) {
                    ArabicSearch::orWhereLike($q, $column, $term);
                }
                if ($number !== null) {
                    $q->orWhere(fn ($n) => $n->where('p.project_number', $number[1])->when($number[0] !== null, fn ($c) => $c->where('ci.code', $number[0])));
                }
                $q->orWhereExists(function ($e) use ($term) {
                    $e->selectRaw('1')->from('project_applicants as a')->join('people as pe', 'pe.id', '=', 'a.person_id')->whereColumn('a.project_id', 'p.id')
                        ->whereNull('a.deleted_at');
                    // Every word of the term in the first, last or father's name ("جورج حداد" finds George Haddad).
                    foreach (array_slice(preg_split('/\s+/u', $term, -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, 5) as $word) {
                        $e->where(function ($n) use ($word) {
                            foreach (['pe.first_name', 'pe.last_name', 'pe.father_name'] as $column) {
                                ArabicSearch::orWhereLike($n, $column, $word);
                            }
                        });
                    }
                });
            });
        }

        return $query->select([
            's.id', 's.city_id', 's.project_id', 's.case_date', 's.reason', 's.description', 's.action', 'ci.code', 'p.project_number',
            't.name_ar as type_ar', 't.name_en as type_en', 'w.username as recorder', 'cr.name_ar as career_ar', 'cr.name_en as career_en',
            'pc.name_ar as category_ar', 'pc.name_en as category_en', 'ps.name_ar as subcategory_ar', 'ps.name_en as subcategory_en',
        ]);
    }

    /** The order of the screen and the file: the asked header column, then newest first. */
    private function ordered(Builder $query, array $filters): Builder
    {
        $person = fn (string $column) => DB::table('project_applicants as sa')->join('people as sp', 'sp.id', '=', 'sa.person_id')->whereColumn('sa.project_id', 'p.id')
            ->whereNull('sa.deleted_at')->orderByRaw("CASE WHEN sa.role = 'مستفيد' THEN 0 ELSE 1 END")->orderBy('sa.id')->select('sp.'.$column)->limit(1);
        $name = fn (string $alias) => app()->getLocale() === 'en' ? "COALESCE(NULLIF({$alias}.name_en, ''), {$alias}.name_ar)" : $alias.'.name_ar';
        TableSort::apply($query, [
            'city' => 'ci.code', 'number' => ['ci.code', 'p.project_number'], 'beneficiary' => [$person('first_name'), $person('last_name')], 'father' => $person('father_name'),
            'career' => $name('cr'), 'category' => $name('pc'), 'subcategory' => $name('ps'), 'date' => 's.case_date', 'recorder' => 'w.username', 'type' => $name('t'),
            'reason' => 's.reason', 'description' => 's.description', 'action' => 's.action',
        ], $filters['sort'] ?? null, $filters['dir'] ?? null);

        return $query->orderByDesc('s.case_date')->orderByDesc('s.id');
    }

    /** @return list<string> column headings, in the desktop Search order */
    public static function headings(): array
    {
        return array_map(fn ($k) => __('special_cases.columns.'.$k), ['city', 'number', 'beneficiary', 'father', 'career', 'category', 'subcategory', 'date', 'recorder', 'type', 'reason', 'description', 'action']);
    }

    /** One row per case of a page or export chunk, with the beneficiary (first applicant of role مستفيد) looked up once per chunk. */
    private function rows(Collection $cases): Collection
    {
        $en = app()->getLocale() === 'en';
        $people = DB::table('project_applicants as a')->join('people as pe', 'pe.id', '=', 'a.person_id')->whereIn('a.project_id', $cases->pluck('project_id')->unique()->all() ?: [0])
            ->whereNull('a.deleted_at')->orderByRaw("CASE WHEN a.role = 'مستفيد' THEN 0 ELSE 1 END")->orderBy('a.id')->get(['a.project_id', 'pe.first_name', 'pe.last_name', 'pe.father_name'])
            ->groupBy('project_id')->map(fn ($g) => $g->first());
        $pick = fn ($ar, $enName) => $en && $enName ? (string) $enName : (string) ($ar ?? '');

        return $cases->map(function ($c) use ($people, $pick) {
            $p = $people[$c->project_id] ?? null;

            return [(string) $c->code, ProjectNumberAllocator::display((string) $c->code, (int) $c->project_number),
                $p === null ? '' : trim(($p->first_name ?? '').' '.($p->last_name ?? '')), (string) ($p->father_name ?? ''),
                $pick($c->career_ar, $c->career_en), $pick($c->category_ar, $c->category_en), $pick($c->subcategory_ar, $c->subcategory_en),
                Fields::day($c->case_date), (string) ($c->recorder ?? ''), $pick($c->type_ar, $c->type_en),
                (string) ($c->reason ?? ''), (string) ($c->description ?? ''), (string) ($c->action ?? '')];
        });
    }

    public function report(WebAccount $account, array $filters): array
    {
        $permitted = $this->permitted($account, false);
        $cities = $this->ids($permitted, $filters);
        $page = $this->ordered($this->query($cities, $filters), $filters)->paginate(self::PER_PAGE);
        $links = $page->getCollection()->map(fn ($c) => ['id' => (int) $c->id, 'project' => (int) $c->project_id])->values()->all();
        $page->setCollection($this->rows($page->getCollection()));
        $all = $permitted->pluck('id')->map(fn ($id) => (int) $id)->all();
        $recorders = DB::table('special_cases')->whereIn('city_id', $all)->whereNull('deleted_at')->whereNotNull('account_id')->distinct()->pluck('account_id')->all();

        return [
            'rows' => $page, 'links' => $links, 'cityOptions' => $permitted, 'headings' => self::headings(),
            'canExport' => $permitted->contains(fn ($c) => $this->access->check($account, (int) $c->id, 'reports.export')),
            'types' => app(SpecialCaseService::class)->types(DB::table('special_case_types')->pluck('id')->all()),
            'recorders' => DB::table('web_accounts')->whereIn('id', $recorders ?: [0])->orderBy('username')->get(['id', 'username']),
            'states' => DB::table('project_states')->orderBy('id')->get(['id', 'name_ar', 'name_en']),
        ];
    }

    /** @return array{0: list<string>, 1: iterable<list<mixed>>} headings and rows of the export, audited before the first byte */
    public function export(WebAccount $account, array $filters): array
    {
        $permitted = $this->permitted($account, true);
        $cities = $this->ids($permitted, $filters);
        $this->audit->record($account->id, 'reports.exported', 'succeeded');
        $query = $this->ordered($this->query($cities, $filters), $filters);

        return [self::headings(), (function () use ($query) {
            foreach ($query->lazy(500)->chunk(500) as $chunk) {
                foreach ($this->rows(collect($chunk->all())) as $row) {
                    yield $row;
                }
            }
        })()];
    }
}
