<?php

namespace App\Services\Search;

use App\Models\WebAccount;
use App\Services\AccountAccess;
use App\Services\People\PeopleService;
use App\Services\ProjectNumberAllocator;
use App\Support\ArabicSearch;
use App\Support\Navigation;
use App\Support\ProjectStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * F3 "Ctrl+K" search (docs/planning/ux-borrowed-features-2026-11.md), borrowed from HopeHR's
 * "search for a person, a page or a command". Everything it returns is limited by the account's own
 * capability per city: people by people.view, projects by projects.view, pages and commands by the
 * capability of the page. The term travels in the Livewire request body, never in a URL.
 */
final class GlobalSearch
{
    public const LIMIT = 6;

    /** Commands: something to start, each behind the capability its page checks again. */
    private const COMMANDS = [
        ['key' => 'new_application', 'icon' => 'plus', 'route' => 'applications.create', 'capability' => 'applications.manage'],
        ['key' => 'new_person', 'icon' => 'users', 'route' => 'people.create', 'capability' => 'people.manage'],
        ['key' => 'national_check', 'icon' => 'id', 'route' => 'people.national', 'capability' => 'people.view'],
        ['key' => 'change_password', 'icon' => 'settings', 'route' => 'account.password', 'capability' => null],
    ];

    public function __construct(private AccountAccess $access, private PeopleService $people) {}

    /**
     * @return array{people: list<array>, projects: list<array>, pages: list<array>, commands: list<array>}
     */
    public function search(WebAccount $account, string $term): array
    {
        $term = trim(mb_substr($term, 0, 100));
        $commands = $this->commands($account, $term);
        $commandUrls = array_column($commands, 'url');
        $pages = array_values(array_filter($this->pages($account, $term), fn ($page) => ! in_array($page['url'], $commandUrls, true)));
        $out = ['people' => [], 'projects' => [], 'pages' => $pages, 'commands' => $commands];
        if (mb_strlen($term) < 2) {
            return $out;
        }
        $out['projects'] = $this->projects($account, $term);
        $out['commands'] = [...$out['commands'], ...$this->projectActions($account, $out['projects'])];
        if (ProjectNumberAllocator::parse($term) === null || strlen(preg_replace('/\D/', '', $term)) >= 5) {
            $out['people'] = $this->peopleFor($account, $term);
        }

        return $out;
    }

    private function peopleFor(WebAccount $account, string $term): array
    {
        if ($this->people->cityIds($account) === []) {
            return [];
        }
        $locale = app()->getLocale();

        return collect($this->people->search($account, ['q' => $term])->items())->take(self::LIMIT)->map(fn ($p) => [
            'title' => trim($p->first_name.' '.$p->father_name.' '.$p->last_name),
            'meta' => ($locale === 'en' && $p->city_en ? $p->city_en : $p->city_ar).($p->national_number ? ' · '.$p->national_number : ''),
            'url' => route('people.show', ['locale' => $locale, 'person' => $p->id]),
            'icon' => 'user',
        ])->values()->all();
    }

    private function projects(WebAccount $account, string $term): array
    {
        return $this->projectsFor($account, $term, 'projects.view', self::LIMIT);
    }

    /**
     * Projects matching a number ("DAM-1041", "1041") or words of the project or beneficiary name, limited to the cities where the
     * account holds $capability. Shared by Ctrl+K and the "add new" project pickers (checklist, special cases).
     *
     * @return list<array<string, mixed>>
     */
    public function projectsFor(WebAccount $account, string $term, string $capability, int $limit = self::LIMIT): array
    {
        $term = trim(mb_substr($term, 0, 100));
        if (mb_strlen($term) < 1) {
            return [];
        }
        $cities = $this->access->cities($account, $capability)->pluck('id')->all();
        if ($cities === []) {
            return [];
        }
        $current = DB::table('project_state_history')->select('project_id', 'project_state_id')->where('is_current', true)->whereNull('deleted_at');
        $first = DB::table('project_applicants')->whereNull('deleted_at')->select('project_id', DB::raw('MIN(person_id) as person_id'))->groupBy('project_id');
        $query = DB::table('projects')->join('cities as c', 'c.id', '=', 'projects.city_id')
            ->leftJoinSub($current, 'cs', 'cs.project_id', '=', 'projects.id')->leftJoin('project_states as st', 'st.id', '=', 'cs.project_state_id')
            ->leftJoinSub($first, 'ap', 'ap.project_id', '=', 'projects.id')->leftJoin('people as b', 'b.id', '=', 'ap.person_id')
            ->whereNull('projects.deleted_at')->whereIn('projects.city_id', $cities)
            ->select('projects.id', 'projects.city_id', 'projects.project_number', 'projects.name', 'c.code', 'c.name_ar as city_ar', 'c.name_en as city_en', 'st.name_ar as state_ar', 'st.name_en as state_en',
                'b.first_name', 'b.father_name', 'b.last_name');

        $number = ProjectNumberAllocator::parse($term);
        if ($number !== null) {
            // While typing "DAM-10" the user is on the way to DAM-1001: match the number's start, exact first.
            [$code, $value] = $number;
            $query->whereRaw('CAST(projects.project_number AS CHAR) LIKE ?', [$value.'%'])
                ->orderByRaw('CASE WHEN projects.project_number = ? THEN 0 ELSE 1 END', [$value])->orderBy('projects.project_number');
            if ($code !== null) {
                $query->where('c.code', $code);
            }
        } else {
            // Every word must match the project's name or a part of the beneficiary's name, Arabic letters folded.
            foreach (preg_split('/\s+/u', $term) as $word) {
                $applicants = DB::table('project_applicants as pa')->join('people as pp', 'pp.id', '=', 'pa.person_id')->whereNull('pa.deleted_at')
                    ->where(function ($q) use ($word) {
                        foreach (['pp.first_name', 'pp.father_name', 'pp.last_name'] as $column) {
                            ArabicSearch::orWhereLike($q, $column, $word);
                        }
                    })->select('pa.project_id');
                $query->where(function ($q) use ($word, $applicants) {
                    ArabicSearch::orWhereLike($q, 'projects.name', $word);
                    $q->orWhereIn('projects.id', $applicants);
                });
            }
        }
        $locale = app()->getLocale();

        return $query->orderByDesc('projects.request_date')->limit($limit)->get()->map(fn ($p) => [
            'title' => ProjectNumberAllocator::display($p->code, (int) $p->project_number).' · '.$p->name,
            'meta' => ($locale === 'en' && $p->city_en ? $p->city_en : $p->city_ar).($p->first_name ? ' · '.trim($p->first_name.' '.$p->father_name.' '.$p->last_name) : ''),
            'state' => $p->state_ar ? ($locale === 'en' && $p->state_en ? $p->state_en : $p->state_ar) : null,
            'tone' => ProjectStatus::tone($p->state_en), 'stateIcon' => ProjectStatus::icon($p->state_en),
            'url' => route('projects.show', ['locale' => $locale, 'project' => $p->id]),
            'icon' => 'folder', 'project' => $p->id, 'city' => (int) $p->city_id, 'number' => ProjectNumberAllocator::display($p->code, (int) $p->project_number),
        ])->values()->all();
    }

    /** M4.10: for the top projects found, "Executive file of ALP-1001" and "Purchases of ALP-1001" for an account that may open those pages in the project's city. */
    private function projectActions(WebAccount $account, array $projects): array
    {
        $out = [];
        foreach (array_slice($projects, 0, 3) as $project) {
            if (! $this->access->check($account, $project['city'], 'applications.view')) {
                continue;
            }
            // M4.13: "New special case ALP-1001" opens the project's special cases (special_cases.view).
            foreach (['executive' => ['executive.show', 'executive_files.view', 'clipboard'], 'purchasing' => ['purchasing.show', 'purchasing.view', 'clipboard'],
                'special_cases' => ['special-cases.project', 'special_cases.view', 'clipboard']] as $key => [$route, $capability, $icon]) {
                if (Route::has($route) && $this->access->check($account, $project['city'], $capability)) {
                    $out[] = ['title' => __($key.'.cmd').' '.$project['number'], 'meta' => $project['title'], 'url' => route($route, ['locale' => app()->getLocale(), 'project' => $project['project']]), 'icon' => $icon];
                }
            }
        }

        return $out;
    }

    private function pages(WebAccount $account, string $term): array
    {
        $out = [];
        foreach (Navigation::for($account) as $items) {
            foreach ($items as $item) {
                $label = __('hc.nav_'.$item['key']);
                if ($item['url'] && $this->matches($term, $label, $item['key'])) {
                    $out[] = ['title' => $label, 'url' => $item['url'], 'icon' => $item['icon'], 'key' => 'page:'.$item['key']];
                }
            }
        }

        return array_slice($out, 0, $term === '' ? 8 : self::LIMIT);
    }

    private function commands(WebAccount $account, string $term): array
    {
        $out = [];
        foreach (self::COMMANDS as $command) {
            if (! Route::has($command['route']) || ($command['capability'] && $this->access->cities($account, $command['capability'])->isEmpty())) {
                continue;
            }
            $label = __('hc.cmd_'.$command['key']);
            if ($this->matches($term, $label, $command['key'])) {
                $out[] = ['title' => $label, 'url' => route($command['route'], ['locale' => app()->getLocale()]), 'icon' => $command['icon'], 'key' => 'cmd:'.$command['key']];
            }
        }

        return $out;
    }

    private function matches(string $term, string $label, string $key): bool
    {
        if ($term === '') {
            return true;
        }
        $needle = Str::lower(ArabicSearch::fold($term));

        return Str::contains(Str::lower(ArabicSearch::fold($label)), $needle) || Str::contains(str_replace('_', ' ', $key), $needle);
    }
}
