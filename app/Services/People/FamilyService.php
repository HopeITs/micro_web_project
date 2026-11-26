<?php

declare(strict_types=1);

namespace App\Services\People;

use App\Models\WebAccount;
use App\Support\ArabicSearch;
use App\Support\CityContext;
use App\Support\Datasets\SearchTerms;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Reading families (see {@see Households}): the families list, one family book with its households, and everything about the people
 * of a household. Reading needs `people.view` in the family's city; nothing is written here (member changes are PeopleService's).
 * Counts are computed from the rows on every read; the list runs a fixed number of queries whatever the page size.
 */
final class FamilyService
{
    /** "Children" on the screens: younger than this (the labels say so). */
    public const CHILD_AGE = 18;

    public const PER_PAGE = [20, 50, 100];

    /** Size filter: key => [least, most] members. */
    public const SIZES = ['1' => [1, 1], '2-3' => [2, 3], '4-5' => [4, 5], '6+' => [6, null]];

    public const QUICK = ['attention', 'shared', 'children', 'noworker', 'projects', 'noprojects'];

    /** Sort key => the direction it starts with. */
    public const SORTS = ['head' => 'asc', 'number' => 'asc', 'members' => 'desc', 'residents' => 'desc', 'working' => 'desc', 'children' => 'desc', 'newest' => 'desc'];

    /** Family-wide tables of the family page: key => person sub-table. */
    public const RECORDS = [
        'work' => ['work_experiences'],
        'health' => ['illnesses'],
        'assets' => ['assets'],
        'aid' => ['aid_sources', 'aid_received', 'losses'],
    ];

    /** @var array<string, array{families: list<int>, rows: list<int>}> a search is run once, however many reads use it */
    private array $found = [];

    public function __construct(private PeopleService $people) {}

    /**
     * The cities a families screen reads: `people.view` (inside the session's city restriction), narrowed by the chosen chips.
     *
     * @param  list<int|string>  $chosen
     * @return list<int>
     */
    public function cityIds(WebAccount $account, array $chosen = []): array
    {
        $permitted = $this->people->cityIds($account);
        $context = app(CityContext::class);
        if ($context->active()) {
            $permitted = array_values(array_intersect($permitted, array_map('intval', $context->cityIds())));
        }
        abort_if($permitted === [], 403);
        $picked = array_values(array_intersect(array_map('intval', $chosen), $permitted));

        return $picked ?: $permitted;
    }

    // ---------------------------------------------------------------- the list

    /** The day a person must be born after to count as a child. */
    private static function childCutoff(): string
    {
        return Carbon::today()->subYears(self::CHILD_AGE)->format('Y-m-d');
    }

    /**
     * The family rows the search matches (any member, or the book number) and their families; null without a search.
     *
     * @return ?array{families: list<int>, rows: list<int>}
     */
    private function matches(array $cities, string $search): ?array
    {
        $search = mb_substr($search, 0, 200);
        if (SearchTerms::split($search) === []) {
            return null;
        }
        $key = implode(',', $cities).'|'.$search;
        if (! isset($this->found[$key])) {
            $query = DB::table('family_members as sm')->join('people as sp', 'sp.id', '=', 'sm.person_id')->join('families as sf', 'sf.id', '=', 'sm.family_id')
                ->whereNull('sm.deleted_at')->whereNull('sp.deleted_at')->whereNull('sf.deleted_at')->whereIn('sf.city_id', $cities);
            $this->applySearch($query, $search, 'sp', 'sf');
            $rows = $query->get(['sm.id', 'sm.family_id']);
            $this->found[$key] = [
                'families' => $rows->pluck('family_id')->map(fn ($id) => (int) $id)->unique()->values()->all(),
                'rows' => $rows->pluck('id')->map(fn ($id) => (int) $id)->all(),
            ];
        }

        return $this->found[$key];
    }

    /**
     * One row per live family row of the cities, with its household key and the flags the list sums. With a search only the matching
     * families are read, and `matched` marks the rows that matched.
     */
    private function memberRows(array $cities, ?array $found = null): Builder
    {
        $key = Households::keySql('fm');
        $beneficiary = (int) (Households::beneficiaryRelationId() ?? 0);
        $job = "EXISTS (SELECT 1 FROM person_work_experiences w WHERE w.person_id = fm.person_id AND w.status = 'yes' AND w.deleted_at IS NULL)";
        $blank = fn (string $column) => "({$column} IS NULL OR {$column} = '')";
        // The same gaps the family page lists one by one (FamilyService::flags).
        $incomplete = implode(' OR ', [
            $blank('p.national_number'), $blank('p.father_name'), 'p.person_state_id IS NULL', 'p.birth_date IS NULL', 'p.sex IS NULL',
            "(fm.person_id <> {$key} AND (fm.relation_id IS NULL OR fm.relation_id = {$beneficiary}))",
            "(fm.person_id <> {$key} AND fm.has_work = 1 AND LOWER(s.type) = 'active' AND NOT {$job})",
        ]);

        // Row ids are integers read from the database, so they are safe to inline (a long list stays out of the bindings).
        $matched = $found === null ? '1' : 'CASE WHEN fm.id IN ('.implode(',', $found['rows'] ?: [0]).') THEN 1 ELSE 0 END';

        return DB::table('family_members as fm')
            ->join('families as f', 'f.id', '=', 'fm.family_id')
            ->join('people as p', 'p.id', '=', 'fm.person_id')
            ->leftJoin('person_states as s', 's.id', '=', 'p.person_state_id')
            ->whereNull('fm.deleted_at')->whereNull('f.deleted_at')->whereNull('p.deleted_at')
            ->whereIn('f.city_id', $cities)
            ->when($found !== null, fn (Builder $q) => $q->whereIntegerInRaw('fm.family_id', $found['families'] ?: [0]))
            ->selectRaw("fm.family_id, f.city_id, f.family_number, {$key} as head_id, {$matched} as matched,
                CASE WHEN LOWER(s.type) = 'active' THEN 1 ELSE 0 END as resident,
                CASE WHEN fm.has_work = 1 OR (fm.has_work IS NULL AND {$job}) THEN 1 ELSE 0 END as working,
                CASE WHEN p.birth_date > ? THEN 1 ELSE 0 END as child,
                CASE WHEN {$incomplete} THEN 1 ELSE 0 END as incomplete,
                CASE WHEN fm.person_id = {$key} THEN 1 ELSE 0 END as is_head,
                CASE WHEN EXISTS (SELECT 1 FROM project_applicants pa JOIN projects pr ON pr.id = pa.project_id WHERE pa.person_id = fm.person_id AND pa.deleted_at IS NULL AND pr.deleted_at IS NULL) THEN 1 ELSE 0 END as has_project",
                [self::childCutoff()]);
    }

    /** One row per household. */
    private function grouped(array $cities, ?array $found = null): Builder
    {
        return DB::query()->fromSub($this->memberRows($cities, $found), 'r')
            ->groupBy('r.family_id', 'r.city_id', 'r.family_number', 'r.head_id')
            ->selectRaw('r.family_id, r.city_id, r.family_number, r.head_id, COUNT(*) as members, SUM(r.resident) as residents, SUM(r.working) as working,
                SUM(r.child) as children, SUM(r.incomplete) as incomplete, SUM(r.is_head) as heads, MAX(r.has_project) as has_project, MAX(r.matched) as matched');
    }

    /** Book numbers held by more than one beneficiary in a city (the desktop's duplicate-number warning). */
    private function sharedNumbers(array $cities, ?array $found = null): Builder
    {
        $head = 'NULLIF('.Households::keySql('x').', 0)';

        return DB::table('family_members as x')->join('families as xf', 'xf.id', '=', 'x.family_id')->join('people as xp', 'xp.id', '=', 'x.person_id')
            ->whereNull('x.deleted_at')->whereNull('xf.deleted_at')->whereNull('xp.deleted_at')->whereIn('xf.city_id', $cities)
            ->when($found !== null, fn (Builder $q) => $q->whereIn('xf.family_number', DB::table('families')->select('family_number')->whereIntegerInRaw('id', $found['families'] ?: [0])))
            ->groupBy('xf.city_id', 'xf.family_number')->havingRaw("COUNT(DISTINCT {$head}) > 1")
            ->selectRaw("xf.city_id as shared_city, xf.family_number as shared_number, COUNT(DISTINCT {$head}) as holders");
    }

    private static function attentionSql(): string
    {
        return '(h.incomplete > 0 OR h.heads = 0 OR (hp.family_size IS NOT NULL AND hp.family_size <> h.members) OR (hp.household_size IS NOT NULL AND hp.household_size <> h.residents))';
    }

    /**
     * Search terms (see SearchTerms) are OR'ed over any member: digits find a national number, a mobile or a book number; other
     * terms need every word in one member's name (Arabic letters folded) or in the book number.
     */
    private function applySearch(Builder $query, string $text, string $person, ?string $family): void
    {
        $query->where(function (Builder $any) use ($text, $person, $family) {
            foreach (SearchTerms::split($text) as $term) {
                $digits = preg_replace('/\D/', '', $term);
                if ($digits !== '' && strlen($digits) >= 3 && preg_match('/^[\d\s+\-]+$/', $term) === 1) {
                    $any->orWhere(function (Builder $q) use ($digits, $person, $family) {
                        $q->where($person.'.national_number', 'like', '%'.(ltrim($digits, '0') ?: $digits).'%')
                            ->orWhere($person.'.mobile', 'like', '%'.$digits.'%')->orWhere($person.'.mobile_2', 'like', '%'.$digits.'%');
                        $family === null || $q->orWhere($family.'.family_number', 'like', '%'.$digits.'%');
                    });

                    continue;
                }
                $any->orWhere(function (Builder $all) use ($term, $person, $family) {
                    foreach (SearchTerms::words($term) as $word) {
                        $like = ArabicSearch::like($word);
                        $all->where(function (Builder $q) use ($word, $like, $person, $family) {
                            foreach (['first_name', 'father_name', 'last_name'] as $column) {
                                ArabicSearch::orWhereLike($q, $person.'.'.$column, $word);
                            }
                            $q->orWhere($person.'.full_name_en', 'like', $like);
                            $family === null || $q->orWhere($family.'.family_number', 'like', $like);
                        });
                    }
                });
            }
        });
    }

    /** The households of the cities that match the search, with their head. */
    private function listQuery(array $cities, string $search): Builder
    {
        $found = $this->matches($cities, $search);
        $query = DB::query()->fromSub($this->grouped($cities, $found), 'h')
            ->leftJoin('people as hp', fn ($j) => $j->on('hp.id', '=', 'h.head_id')->whereNull('hp.deleted_at'))
            ->leftJoinSub($this->sharedNumbers($cities, $found), 'sh', fn ($j) => $j->on('sh.shared_city', '=', 'h.city_id')->on('sh.shared_number', '=', 'h.family_number'));

        // A household is found when one of its own rows matched (not only one of another household on the same book).
        return $found === null ? $query : $query->where('h.matched', 1);
    }

    /**
     * Counts over the searched households (before the quick filter and the size), for the tiles.
     *
     * @param  list<int>  $cities  from cityIds()
     * @return array{households: int, members: int, children: int, attention: int, shared: int, noworker: int}
     */
    public function totals(array $cities, string $search): array
    {
        $row = $this->listQuery($cities, $search)->selectRaw('COUNT(*) as households, SUM(h.members) as members, SUM(h.children) as children,
            SUM(CASE WHEN '.self::attentionSql().' THEN 1 ELSE 0 END) as attention,
            SUM(CASE WHEN sh.holders IS NOT NULL AND h.head_id > 0 THEN 1 ELSE 0 END) as shared,
            SUM(CASE WHEN h.working = 0 THEN 1 ELSE 0 END) as noworker')->first();

        return array_map('intval', (array) $row);
    }

    /**
     * One page of households with their members and projects.
     *
     * @param  list<int>  $cities  from cityIds()
     * @param  array{search?: string, quick?: string, size?: string, sort?: string, dir?: string}  $filters
     * @param  ?array<string, int>  $totals  totals() of the same cities and search: spares counting the pages again
     */
    public function page(array $cities, array $filters, int $perPage, ?array $totals = null): LengthAwarePaginator
    {
        $search = (string) ($filters['search'] ?? '');
        $query = $this->listQuery($cities, $search);
        match ($filters['quick'] ?? '') {
            'attention' => $query->whereRaw(self::attentionSql()),
            'shared' => $query->whereNotNull('sh.holders')->where('h.head_id', '>', 0),
            'children' => $query->where('h.children', '>', 0),
            'noworker' => $query->where('h.working', 0),
            'projects' => $query->where('h.has_project', 1),
            'noprojects' => $query->where('h.has_project', 0),
            default => null,
        };
        if (isset(self::SIZES[$filters['size'] ?? ''])) {
            [$least, $most] = self::SIZES[$filters['size']];
            $query->where('h.members', '>=', $least);
            $most === null || $query->where('h.members', '<=', $most);
        }
        $sort = isset(self::SORTS[$filters['sort'] ?? '']) ? $filters['sort'] : 'head';
        $dir = in_array($filters['dir'] ?? '', ['asc', 'desc'], true) ? $filters['dir'] : self::SORTS[$sort];
        foreach (match ($sort) {
            'head' => ['hp.first_name', 'hp.father_name', 'hp.last_name'],
            'number' => ['h.family_number'],
            'newest' => ['h.family_id'],
            default => ['h.'.$sort],
        } as $column) {
            $query->orderBy($column, $dir);
        }
        $query->orderBy('h.family_id')->orderBy('h.head_id')->select([
            'h.family_id', 'h.city_id', 'h.family_number', 'h.head_id', 'h.members', 'h.residents', 'h.working', 'h.children', 'h.incomplete', 'h.heads',
            'hp.first_name', 'hp.father_name', 'hp.last_name', 'hp.mobile', 'hp.family_size', 'hp.household_size', 'sh.holders',
        ]);
        $known = match (true) {
            $totals === null || isset(self::SIZES[$filters['size'] ?? '']) => null,
            ($filters['quick'] ?? '') === '' => $totals['households'],
            // Only these totals count households; `children` counts people.
            in_array($filters['quick'], ['attention', 'shared', 'noworker'], true) => $totals[$filters['quick']],
            default => null,
        };
        $page = $query->paginate(in_array($perPage, self::PER_PAGE, true) ? $perPage : self::PER_PAGE[0], ['*'], 'page', null, $known);
        $this->decorate($page->getCollection(), $search);

        return $page;
    }

    /** Adds the page rows' members, matches and projects (three or four queries for the whole page). */
    private function decorate(Collection $households, string $search): void
    {
        if ($households->isEmpty()) {
            return;
        }
        $cities = DB::table('cities')->get(['id', 'code', 'name_ar', 'name_en'])->keyBy('id');
        $members = $this->members($households->pluck('family_id')->unique()->values()->all())
            ->groupBy(fn ($m) => $m->family_id.':'.$m->head_id);
        $ids = $households->flatMap(fn ($h) => ($members->get($h->family_id.':'.$h->head_id) ?? collect())->pluck('person_id'))->unique()->values()->all();
        $found = [];
        if (SearchTerms::split($search) !== []) {
            $query = DB::table('people as p')->whereIn('p.id', $ids ?: [0]);
            $this->applySearch($query, $search, 'p', null);
            $found = array_flip($query->pluck('p.id')->all());
        }
        $projects = $this->projects($ids)->groupBy('person_id');
        foreach ($households as $h) {
            foreach (['family_id', 'city_id', 'head_id', 'members', 'residents', 'working', 'children', 'incomplete', 'heads'] as $number) {
                $h->{$number} = (int) $h->{$number};
            }
            $h->city = $cities[$h->city_id];
            $h->people = $members->get($h->family_id.':'.$h->head_id) ?? collect();
            $h->head_name = $h->head_id > 0 && $h->first_name !== null ? PeopleService::fullName($h) : null;
            $h->matched = $h->people->filter(fn ($m) => isset($found[$m->person_id]) && $m->person_id !== $h->head_id)->values();
            $mine = $h->people->flatMap(fn ($m) => $projects->get($m->person_id) ?? collect())->unique('id')->sortByDesc('id')->values();
            $h->projects = $mine->count();
            $h->latest = $mine->first();
            $h->shared = $h->holders !== null && $h->head_id > 0;
            $h->counts_differ = ($h->family_size !== null && (int) $h->family_size !== $h->members) || ($h->household_size !== null && (int) $h->household_size !== $h->residents);
        }
    }

    // ---------------------------------------------------------------- members

    /**
     * The live members of families with everything the screens show: relation, sex, age, residence state, marital status,
     * education (level, degree = education type, specialty; the person's latest education record when the family row has none),
     * current jobs and their income. Heads come first, then by age. Callers authorize.
     *
     * @param  list<int>  $familyIds
     */
    public function members(array $familyIds, ?int $headId = null): Collection
    {
        $en = app()->getLocale() === 'en';
        $label = fn (string $alias) => $en ? "COALESCE({$alias}.name_en, {$alias}.name_ar)" : "{$alias}.name_ar";
        $query = DB::table('family_members as m')->join('people as p', 'p.id', '=', 'm.person_id')
            ->leftJoin('relations as r', 'r.id', '=', 'm.relation_id')->leftJoin('person_states as s', 's.id', '=', 'p.person_state_id')
            ->leftJoin('marital_statuses as ms', 'ms.id', '=', 'p.marital_status_id')->leftJoin('education_levels as el', 'el.id', '=', 'm.education_level_id')
            ->leftJoin('education_specialties as es', 'es.id', '=', 'm.education_specialty_id')->leftJoin('education_types as et', 'et.id', '=', 'es.education_type_id')
            ->whereIn('m.family_id', $familyIds ?: [0])->whereNull('m.deleted_at')->whereNull('p.deleted_at');
        if ($headId !== null) {
            $query->whereRaw(Households::keySql('m').' = ?', [$headId]);
        }
        $rows = $query->orderBy('m.id')->get([
            'm.id', 'm.family_id', 'm.person_id', 'm.relation_id', 'm.education_level_id', 'm.education_specialty_id', 'm.has_work', 'm.work_name',
            DB::raw(Households::keySql('m').' as head_id'),
            'p.first_name', 'p.father_name', 'p.last_name', 'p.sex', 'p.birth_date', 'p.national_number', 'p.mobile', 'p.person_state_id', 'p.family_size', 'p.household_size',
            DB::raw($label('r').' as relation'), DB::raw($label('s').' as state'), 's.type as state_type', DB::raw($label('ms').' as marital'),
            DB::raw($label('el').' as education_level'), DB::raw($label('es').' as education_specialty'), DB::raw($label('et').' as education_degree'),
        ]);
        $ids = $rows->pluck('person_id')->unique()->values()->all();
        $jobs = DB::table('person_work_experiences as w')->leftJoin('occupations as o', 'o.id', '=', 'w.occupation_id')->leftJoin('work_types as t', 't.id', '=', 'w.work_type_id')
            ->whereIn('w.person_id', $ids ?: [0])->where('w.status', 'yes')->whereNull('w.deleted_at')->orderBy('w.id')
            ->get(['w.person_id', 'w.description', 'w.salary', DB::raw($label('o').' as occupation'), DB::raw($label('t').' as work_type')])->groupBy('person_id');
        $without = $rows->filter(fn ($m) => $m->education_level_id === null && $m->education_specialty_id === null)->pluck('person_id')->unique()->values()->all();
        $education = $without === [] ? collect() : DB::table('person_educations as e')->leftJoin('education_levels as el', 'el.id', '=', 'e.education_level_id')
            ->leftJoin('education_specialties as es', 'es.id', '=', 'e.education_specialty_id')->leftJoin('education_types as et', 'et.id', '=', 'es.education_type_id')
            ->whereIn('e.person_id', $without)->whereNull('e.deleted_at')->orderBy('e.id')
            ->get(['e.person_id', DB::raw($label('el').' as education_level'), DB::raw($label('es').' as education_specialty'), DB::raw($label('et').' as education_degree')])
            ->keyBy('person_id'); // the latest row wins

        return $rows->map(function (object $m) use ($jobs, $education) {
            foreach (['id', 'family_id', 'person_id', 'head_id'] as $number) {
                $m->{$number} = (int) $m->{$number};
            }
            $m->relation_id = $m->relation_id === null ? null : (int) $m->relation_id;
            $m->has_work = $m->has_work === null ? null : (bool) $m->has_work;
            $m->is_head = $m->person_id === $m->head_id;
            $m->name = PeopleService::fullName($m);
            $m->birth_year = $m->birth_date ? (int) substr((string) $m->birth_date, 0, 4) : null;
            $m->age = $m->birth_date ? max(0, (int) Carbon::parse((string) $m->birth_date)->diffInYears(Carbon::today())) : null;
            $m->child = $m->age !== null && $m->age < self::CHILD_AGE;
            $m->resident = strtolower((string) $m->state_type) === 'active';
            if ($m->education_level_id === null && $m->education_specialty_id === null && ($own = $education->get($m->person_id))) {
                [$m->education_level, $m->education_specialty, $m->education_degree] = [$own->education_level, $own->education_specialty, $own->education_degree];
            }
            $m->jobs = $jobs->get($m->person_id) ?? collect();
            $m->working = $m->has_work === true || ($m->has_work === null && $m->jobs->isNotEmpty());
            $salaries = $m->jobs->pluck('salary')->filter(fn ($s) => $s !== null);
            $m->income = $salaries->isEmpty() ? null : (float) $salaries->sum();
            $m->work_text = collect([$m->work_name])->concat($m->jobs->map(fn ($j) => collect([$j->occupation, $j->description])->filter()->implode(' - ')))
                ->map(fn ($text) => trim((string) $text))->filter()->unique()->values()->implode('، ');

            return $m;
        })->sortBy(fn (object $m) => [$m->is_head ? 0 : 1, $m->birth_date === null ? 1 : 0, (string) $m->birth_date, $m->id])->values();
    }

    /**
     * The projects people applied to, newest first, with the current state. Callers authorize.
     *
     * @param  list<int>  $personIds
     */
    public function projects(array $personIds): Collection
    {
        return DB::table('project_applicants as a')->join('projects as p', 'p.id', '=', 'a.project_id')->join('cities as c', 'c.id', '=', 'p.city_id')
            ->leftJoin('project_state_history as h', fn ($j) => $j->on('h.project_id', '=', 'p.id')->where('h.is_current', 1)->whereNull('h.deleted_at'))
            ->leftJoin('project_states as s', 's.id', '=', 'h.project_state_id')
            ->whereNull('a.deleted_at')->whereNull('p.deleted_at')->whereIn('a.person_id', $personIds ?: [0])->orderByDesc('p.id')
            ->get(['p.id', 'p.project_number', 'p.name', 'p.application_stage', 'p.request_date', 'c.code', 'a.person_id', 'a.role', 's.name_ar as state_ar', 's.name_en as state_en'])
            ->unique(fn ($r) => $r->id.':'.$r->person_id)->values()
            ->map(function (object $r) {
                $r->id = (int) $r->id;
                $r->person_id = (int) $r->person_id;
                $r->state = app()->getLocale() === 'en' ? ($r->state_en ?: $r->state_ar) : $r->state_ar;

                return $r;
            });
    }

    // ---------------------------------------------------------------- one family

    /**
     * A family book the account may read (404 otherwise): its households' heads (0 = rows with no recorded provider, last) and the
     * other households holding the same number in the city.
     *
     * @return array{family: object, city: object, heads: list<int>, elsewhere: Collection}
     */
    public function family(WebAccount $account, int $familyId): array
    {
        $family = DB::table('families')->where('id', $familyId)->whereNull('deleted_at')->whereIn('city_id', $this->cityIds($account))->first();
        abort_if($family === null, 404);
        $heads = $this->householdsOfNumber((int) $family->city_id, (string) $family->family_number);

        return [
            'family' => $family,
            'city' => DB::table('cities')->where('id', $family->city_id)->first(),
            'heads' => $heads->where('family_id', (int) $family->id)->pluck('head_id')->sortBy(fn ($h) => [$h > 0 ? 0 : 1, $h])->values()->all(),
            'elsewhere' => $heads->where('family_id', '<>', (int) $family->id)->values(),
        ];
    }

    /**
     * Every household under one book number in a city, with the head's name.
     *
     * @return Collection<int, object{family_id: int, head_id: int, head_name: ?string}>
     */
    public function householdsOfNumber(int $city, string $number): Collection
    {
        $rows = DB::table('family_members as m')->join('families as f', 'f.id', '=', 'm.family_id')->join('people as p', 'p.id', '=', 'm.person_id')
            ->where('f.city_id', $city)->where('f.family_number', $number)->whereNull('f.deleted_at')->whereNull('m.deleted_at')->whereNull('p.deleted_at')
            ->selectRaw('m.family_id, '.Households::keySql('m').' as head_id')->distinct()->get();
        $names = DB::table('people')->whereIn('id', $rows->pluck('head_id')->filter()->all() ?: [0])->whereNull('deleted_at')->get(['id', 'first_name', 'father_name', 'last_name'])->keyBy('id');

        return $rows->map(fn ($r) => (object) [
            'family_id' => (int) $r->family_id, 'head_id' => (int) $r->head_id,
            'head_name' => isset($names[(int) $r->head_id]) ? PeopleService::fullName($names[(int) $r->head_id]) : null,
        ])->sortBy(fn ($r) => [$r->family_id, $r->head_id])->values();
    }

    /**
     * One household as it stands now: head, members, totals, projects and what needs attention. Callers authorize the family.
     *
     * @return array{family: object, head_id: int, head: ?object, head_name: ?string, head_person: ?object, members: Collection, totals: array, projects: Collection, flags: list<array>}
     */
    public function household(int $familyId, int $headId): array
    {
        $family = DB::table('families')->where('id', $familyId)->whereNull('deleted_at')->first();
        abort_if($family === null, 404);
        $members = $this->members([$familyId], $headId);
        $head = $members->firstWhere('is_head', true);
        $headPerson = $headId > 0 ? DB::table('people')->where('id', $headId)->whereNull('deleted_at')->first(['id', 'first_name', 'father_name', 'last_name', 'sex', 'family_size', 'household_size', 'city_id']) : null;
        $projects = $this->projects($members->pluck('person_id')->all());
        $incomes = $members->pluck('income')->filter(fn ($v) => $v !== null);
        $totals = [
            'members' => $members->count(), 'residents' => $members->where('resident', true)->count(), 'working' => $members->where('working', true)->count(),
            'children' => $members->where('child', true)->count(), 'income' => $incomes->isEmpty() ? null : (float) $incomes->sum(),
            'projects' => $projects->unique('id')->count(),
        ];

        return [
            'family' => $family, 'head_id' => $headId, 'head' => $head, 'head_name' => $headPerson ? PeopleService::fullName($headPerson) : null,
            'head_person' => $headPerson, 'members' => $members, 'totals' => $totals, 'projects' => $projects,
            'flags' => $this->flags($family, $headId, $head, $headPerson, $members),
        ];
    }

    /**
     * What needs attention, from the stored data and the desktop's own rules for a family row (name, father, relation, birth, sex,
     * residence state, national number; a resident member who works has a row in the family work table; the stored counts).
     *
     * @return list<array{key: string, tone: string, members: list<object>, stored?: array, computed?: array}>
     */
    private function flags(object $family, int $headId, ?object $head, ?object $headPerson, Collection $members): array
    {
        if ($members->isEmpty()) {
            return [];
        }
        $beneficiary = Households::beneficiaryRelationId();
        $flags = [];
        if ($head === null) {
            $flags[] = ['key' => 'no_head', 'tone' => 'danger', 'members' => []];
        }
        $blank = fn ($value) => trim((string) $value) === '';
        foreach ([
            'relation' => fn ($m) => ! $m->is_head && $m->relation_id === null,
            'beneficiary_relation' => fn ($m) => ! $m->is_head && $beneficiary !== null && $m->relation_id === $beneficiary,
            'state' => fn ($m) => $m->person_state_id === null,
            'national' => fn ($m) => $blank($m->national_number),
            'father' => fn ($m) => $blank($m->father_name),
            'birth' => fn ($m) => $m->birth_date === null,
            'sex' => fn ($m) => $m->sex === null,
            'work' => fn ($m) => ! $m->is_head && $m->has_work === true && $m->resident && $m->jobs->isEmpty(),
        ] as $key => $test) {
            $found = $members->filter($test)->values()->all();
            if ($found !== []) {
                $flags[] = ['key' => $key, 'tone' => 'warn', 'members' => $found];
            }
        }
        if ($head !== null && $headPerson !== null) {
            $computed = Households::counts($headId);
            $stored = ['family_size' => $headPerson->family_size === null ? null : (int) $headPerson->family_size, 'household_size' => $headPerson->household_size === null ? null : (int) $headPerson->household_size];
            if ($stored !== $computed) {
                $missing = $stored['family_size'] === null || $stored['household_size'] === null;
                $flags[] = ['key' => $missing ? 'counts_missing' : 'counts', 'tone' => $missing ? 'info' : 'warn', 'members' => [], 'stored' => $stored, 'computed' => $computed];
            }
        }
        if (preg_match('/\A[A-Z]{2,6}-W\d+\z/', (string) $family->family_number) === 1) {
            $flags[] = ['key' => 'temporary_number', 'tone' => 'info', 'members' => []];
        }

        return $flags;
    }

    /**
     * The households a person belongs to, in the cities the account may read: the one they head first.
     *
     * @return Collection<int, object>
     */
    public function memberships(WebAccount $account, int $personId): Collection
    {
        $cities = $this->people->cityIds($account);
        $rows = Households::membershipsOf($personId)->filter(fn ($m) => in_array($m->city_id, $cities, true))->unique(fn ($m) => $m->family_id.':'.$m->head_id)->values();
        $en = app()->getLocale() === 'en';
        $relations = DB::table('relations')->get(['id', 'name_ar', 'name_en'])->keyBy('id');
        $heads = DB::table('people')->whereIn('id', $rows->pluck('head_id')->all() ?: [0])->whereNull('deleted_at')->get(['id', 'first_name', 'father_name', 'last_name'])->keyBy('id');

        return $rows->map(function (object $m) use ($en, $relations, $heads) {
            $relation = $m->relation_id === null ? null : ($relations[$m->relation_id] ?? null);
            $m->relation = $relation ? ($en && $relation->name_en ? $relation->name_en : $relation->name_ar) : null;
            $m->head_name = isset($heads[$m->head_id]) ? PeopleService::fullName($heads[$m->head_id]) : null;

            return $m;
        });
    }

    /**
     * The family-wide tables (work, health, property, aid and losses) of some people: tab => sub-table => rows by person.
     *
     * @param  list<int>  $personIds
     * @return array<string, array<string, Collection>>
     */
    public function records(array $personIds): array
    {
        $out = [];
        foreach (self::RECORDS as $tab => $kinds) {
            foreach ($kinds as $kind) {
                $out[$tab][$kind] = $this->people->detailRows($kind, $personIds);
            }
        }

        return $out;
    }
}
