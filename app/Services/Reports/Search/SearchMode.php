<?php

declare(strict_types=1);

namespace App\Services\Reports\Search;

use App\Models\WebAccount;
use App\Services\AccountAccess;
use App\Services\ProjectNumberAllocator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * One desktop search type ("Search by", M4.17) on the unified tables. A mode knows its key, the capabilities a city must grant, its
 * columns, one filtered row query and how a row becomes cells; SearchService does authorisation, paging, masking, links, audit and export.
 *
 * Query contract: the query selects at least `p.id as project_id` (null when the mode has no project), `p.city_id`, `ci.code`,
 * `ci.name_ar as city_ar`, `ci.name_en as city_en` (see baseColumns()), is ordered deterministically by order() and has no GROUP BY, so
 * paginate()/lazy() and the counters work on it. Anything per row that needs another table is read for the whole page in lines().
 */
abstract class SearchMode
{
    public const PER_PAGE = 50;

    /** link kind => [route, [capabilities the reader needs in the row's city]] */
    public const LINKS = [
        'project' => ['projects.show', ['projects.view'], 'project'],
        'person' => ['people.show', ['people.view'], 'person'],
        'finance' => ['finance.show', ['finance.view', 'applications.view'], 'project'],
        'checklist' => ['checklists.show', ['assessments.view'], 'project'],
        'special-cases' => ['special-cases.project', ['special_cases.view', 'applications.view'], 'project'],
        'visits' => ['visits.hub', ['visits.view', 'applications.view'], 'project'],
        'meeting' => ['meetings.show', ['profession_meetings.view'], 'meeting'],
    ];

    /** @var array<string, Collection> */
    private array $memo = [];

    /** The account asking, for modes whose rows depend on the account's grants (attachments). */
    protected ?WebAccount $actor = null;

    public function __construct(protected AccountAccess $access) {}

    public function for(WebAccount $account): static
    {
        $this->actor = $account;

        return $this;
    }

    abstract public function key(): string;

    /** The group of the mode select. */
    abstract public function group(): string;

    /** @return list<string> capabilities a city must grant, all of them, for the mode to be offered there */
    abstract public function capabilities(): array;

    /** @return list<string> column keys under `search.columns` (the city column is always first and is not listed) */
    abstract public function columns(): array;

    /** The filtered query over the given cities (the account's permitted ones). */
    abstract public function query(array $cityIds, array $filters): Builder;

    abstract public function order(Builder $query): Builder;

    /**
     * @param  Collection<int, object>  $rows  one page or export chunk
     * @return list<list<scalar|null>> the cells of each row in columns() order (without the city)
     */
    abstract public function lines(Collection $rows): array;

    /** Capabilities an export needs in a city on top of the mode's own. */
    public function exportCapabilities(): array
    {
        return ['reports.export'];
    }

    /** 'project' (the common filter set), 'meeting' (category, sub category, career, meeting dates) or 'none'. */
    public function filterKind(): string
    {
        return 'project';
    }

    /** The mode has its own switch "last visit only". */
    public function hasLastSwitch(): bool
    {
        return false;
    }

    /** Whether a row is a project (the counter counts distinct projects, else rows). */
    public function hasProject(): bool
    {
        return true;
    }

    /** @return list<int> zero-based indexes of the cells (city included) written as numbers in a workbook */
    public function numeric(): array
    {
        return [1];
    }

    /** @return list<int> indexes (city included) shown only to readers holding `people.view` in the row's city */
    public function sensitive(): array
    {
        return [];
    }

    /** Index (city included) of the cell that carries the row link. */
    public function linkColumn(): int
    {
        return 1;
    }

    /** @return ?array{0: string, 1: int} link kind and the id the route takes (project id, person id or meeting id) */
    public function target(object $row): ?array
    {
        return $row->project_id === null ? null : ['project', (int) $row->project_id];
    }

    /** Extra cities filter (attachments: category grants). */
    public function cityAllowed(WebAccount $account, int $city): bool
    {
        return true;
    }

    /** Whether a row is marked (duplicate beneficiary name); index-aligned with the rows. @return list<bool> */
    public function flags(Collection $rows): array
    {
        return [];
    }

    /** Totals over all matching rows (not one page), or null. @return ?array<string, mixed> */
    public function summary(Builder $query): ?array
    {
        return null;
    }

    /** Rows or distinct projects matching. */
    public function counter(Builder $query): int
    {
        $base = $this->bare($query);

        return (int) ($this->hasProject() ? $base->selectRaw('COUNT(DISTINCT p.id) as c')->value('c') : $base->selectRaw('COUNT(*) as c')->value('c'));
    }

    /** The query without select, order and limits (for aggregates). */
    protected function bare(Builder $query): Builder
    {
        return $query->cloneWithout(['columns', 'orders', 'offset', 'limit'])->cloneWithoutBindings(['select', 'order']);
    }

    /** @return list<string> */
    public function headings(): array
    {
        return array_map(fn ($key) => __('search.columns.'.$key), ['city', ...$this->columns()]);
    }

    public function label(): string
    {
        return __('search.modes.'.$this->key());
    }

    // ------------------------------------------------------------------ helpers for the modes

    /** Every project of the permitted cities with a state in force: p, ci, cs (state history in force), st (its state). */
    protected function projectBase(array $cityIds): Builder
    {
        $current = DB::table('project_state_history')->where('is_current', true)->whereNull('deleted_at')->groupBy('project_id')
            ->select('project_id', DB::raw('MAX(id) as history_id'));

        return DB::table('projects as p')->join('cities as ci', 'ci.id', '=', 'p.city_id')
            ->joinSub($current, 'cur', 'cur.project_id', '=', 'p.id')
            ->join('project_state_history as cs', 'cs.id', '=', 'cur.history_id')
            ->join('project_states as st', 'st.id', '=', 'cs.project_state_id')
            ->whereIn('p.city_id', $cityIds === [] ? [0] : $cityIds)->whereNull('p.deleted_at');
    }

    /** Joins projects (with city and state in force) to a query that starts elsewhere; `$on` is the column holding the project id. */
    protected function joinProject(Builder $query, string $on, array $cityIds): Builder
    {
        $current = DB::table('project_state_history')->where('is_current', true)->whereNull('deleted_at')->groupBy('project_id')
            ->select('project_id', DB::raw('MAX(id) as history_id'));

        return $query->join('projects as p', 'p.id', '=', $on)->join('cities as ci', 'ci.id', '=', 'p.city_id')
            ->joinSub($current, 'cur', 'cur.project_id', '=', 'p.id')
            ->join('project_state_history as cs', 'cs.id', '=', 'cur.history_id')
            ->join('project_states as st', 'st.id', '=', 'cs.project_state_id')
            ->whereIn('p.city_id', $cityIds === [] ? [0] : $cityIds)->whereNull('p.deleted_at');
    }

    /** @return list<string> */
    protected function baseColumns(): array
    {
        return ['p.id as project_id', 'p.city_id', 'p.project_number', 'ci.code', 'ci.name_ar as city_ar', 'ci.name_en as city_en'];
    }

    /** Left-joins a lookup table and selects both names as `{as}_ar` / `{as}_en`. */
    protected function lookup(Builder $query, string $table, string $alias, string $column, string $as): Builder
    {
        return $query->leftJoin($table.' as '.$alias, $alias.'.id', '=', $column)->addSelect($alias.'.name_ar as '.$as.'_ar', $alias.'.name_en as '.$as.'_en');
    }

    /** The name of a joined lookup in the current language. */
    protected function name(object $row, string $as): string
    {
        $en = app()->getLocale() === 'en';
        $ar = (string) ($row->{$as.'_ar'} ?? '');
        $enName = (string) ($row->{$as.'_en'} ?? '');

        return $en && $enName !== '' ? $enName : $ar;
    }

    /** Name of a lookup row object (name_ar / name_en). */
    protected function pick(?object $row): string
    {
        if ($row === null) {
            return '';
        }

        return app()->getLocale() === 'en' && ($row->name_en ?? '') !== '' ? (string) $row->name_en : (string) ($row->name_ar ?? '');
    }

    protected function number(object $row): string
    {
        return ProjectNumberAllocator::display((string) $row->code, (int) $row->project_number);
    }

    /** Rows matching (not distinct projects). */
    public function rows(Builder $query): int
    {
        return (int) $this->bare($query)->selectRaw('COUNT(*) as c')->value('c');
    }

    public function cityName(object $row): string
    {
        return app()->getLocale() === 'en' && ($row->city_en ?? '') !== '' ? (string) $row->city_en : (string) $row->city_ar;
    }

    protected function day(mixed $value): string
    {
        $text = $value === null ? '' : substr((string) $value, 0, 10);

        return $text === '' || str_starts_with($text, '0000') ? '' : $text;
    }

    protected function personName(?object $person, bool $withFather = false, string $prefix = ''): string
    {
        if ($person === null) {
            return '';
        }
        $name = trim(($person->{$prefix.'first_name'} ?? '').' '.($person->{$prefix.'last_name'} ?? ''));
        $father = (string) ($person->{$prefix.'father_name'} ?? '');

        return $withFather && $father !== '' ? $name.' '.__('search.son_of').' '.$father : $name;
    }

    /**
     * The applicants of the given projects, one query: project id => ['all' => "A, B", 'first' => the beneficiary (else the first
     * applicant) with first_name, last_name, father_name, mother_name, full_name_en, mobile, national_number, person_id, role].
     *
     * @return array<int, array{all: string, first: object}>
     */
    protected function applicants(array $projectIds): array
    {
        if ($projectIds === []) {
            return [];
        }
        $rows = DB::table('project_applicants as a')->join('people as pe', 'pe.id', '=', 'a.person_id')->whereIn('a.project_id', array_values(array_unique($projectIds)))
            ->whereNull('a.deleted_at')->whereNull('pe.deleted_at')->orderByRaw("CASE WHEN a.role = 'مستفيد' THEN 0 ELSE 1 END")->orderBy('a.id')
            ->get(['a.project_id', 'a.role', 'pe.id as person_id', 'pe.first_name', 'pe.last_name', 'pe.father_name', 'pe.mother_name', 'pe.full_name_en', 'pe.mobile', 'pe.national_number']);
        $out = [];
        foreach ($rows->groupBy('project_id') as $project => $group) {
            $out[(int) $project] = ['all' => $group->map(fn ($p) => $this->personName($p, true))->implode(', '), 'first' => $group->first()];
        }

        return $out;
    }

    /** Memoises a small lookup read within one request (catalogs used by every page row). */
    protected function memo(string $key, \Closure $load): Collection
    {
        return $this->memo[$key] ??= $load();
    }
}
