<?php

namespace App\Livewire;

use App\Models\City;
use App\Models\Project;
use App\Models\ProjectState;
use App\Services\ProjectNumberAllocator;
use App\Support\ArabicSearch;
use App\Support\CityContext;
use App\Support\ProjectStatus;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Projects of the permitted cities: instant search (CITY-#### / number / names), filters kept in the URL, and
 * count tiles per stage of the application path (F3). The search term can hold a beneficiary's name or national
 * number, so it stays out of the URL (UX plan F3, same rule as the people list).
 */
class ProjectsIndex extends Component
{
    use WithPagination;

    public string $search = '';

    #[Url(except: '')]
    public string $path = '';

    /** @var list<int> */
    #[Url(as: 'city', except: [])]
    public array $cities = [];

    #[Url(except: '')]
    public string $stage = '';

    #[Url(except: '')]
    public string $state = '';

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'stage', 'state', 'path'], true) || str_starts_with($property, 'cities')) {
            $this->resetPage();
        }
    }

    public function togglePath(string $group): void
    {
        $this->path = $this->path === $group || ! array_key_exists($group, ProjectStatus::groups()) ? '' : $group;
        $this->resetPage();
    }

    public function toggleCity(int $id): void
    {
        $this->cities = in_array($id, $this->cities, true)
            ? array_values(array_diff($this->cities, [$id]))
            : [...$this->cities, $id];
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'cities', 'stage', 'state', 'path');
        $this->resetPage();
    }

    private function query(bool $withPath = true): Builder
    {
        $current = DB::table('project_state_history')->select('project_id', 'project_state_id')->where('is_current', true)->whereNull('deleted_at');
        $applicant = DB::table('project_applicants as pa')->join('people as pp', 'pp.id', '=', 'pa.person_id')
            ->whereNull('pa.deleted_at')->select('pa.project_id', DB::raw('MIN(pp.id) as person_id'))->groupBy('pa.project_id');

        $query = Project::query()
            ->join('cities as c', 'c.id', '=', 'projects.city_id')
            ->leftJoinSub($applicant, 'ap', 'ap.project_id', '=', 'projects.id')
            ->leftJoin('people as p', 'p.id', '=', 'ap.person_id')
            ->leftJoinSub($current, 'cs', 'cs.project_id', '=', 'projects.id')
            ->leftJoin('project_states as st', 'st.id', '=', 'cs.project_state_id')
            ->select('projects.id', 'projects.project_number', 'projects.name', 'projects.application_stage', 'projects.requested_amount', 'projects.request_date',
                'c.code as city_code', 'c.name_ar as city_ar', 'c.name_en as city_en', 'p.first_name', 'p.father_name', 'p.last_name',
                'st.name_ar as state_ar', 'st.name_en as state_en');

        $permitted = app(CityContext::class)->cityIds();
        $chosen = array_values(array_intersect($this->cities, $permitted));
        if ($chosen !== []) {
            $query->whereIn('projects.city_id', $chosen);
        }
        if (in_array($this->stage, ['preliminary', 'full'], true)) {
            $query->where('projects.application_stage', $this->stage);
        }
        $groups = ProjectStatus::groups();
        if ($withPath && isset($groups[$this->path])) {
            $query->whereIn('st.name_en', $groups[$this->path]);
        }
        if ($this->state !== '' && ctype_digit($this->state)) {
            $query->where('cs.project_state_id', (int) $this->state);
        }
        $term = trim(mb_substr($this->search, 0, 100));
        if ($term !== '') {
            $number = ProjectNumberAllocator::parse($term);
            if ($number !== null) {
                [$code, $value] = $number;
                $query->where('projects.project_number', $value);
                if ($code !== null) {
                    $query->where('c.code', $code);
                }
            } else {
                // Every word must match the project's name, a part of the beneficiary's name (Arabic letters folded) or the national number.
                foreach (preg_split('/\s+/u', $term) as $word) {
                    $query->where(function ($q) use ($word) {
                        foreach (['projects.name', 'p.first_name', 'p.father_name', 'p.last_name'] as $column) {
                            ArabicSearch::orWhereLike($q, $column, $word);
                        }
                        $q->orWhere('p.national_number', 'like', ArabicSearch::like($word));
                    });
                }
            }
        }

        return $query->orderByDesc('projects.request_date')->orderBy('c.code')->orderByDesc('projects.project_number');
    }

    /** @return array<string, int> group => projects matching every other filter */
    private function pathCounts(): array
    {
        $byState = $this->query(false)->reorder()->toBase()->select('st.name_en')->selectRaw('COUNT(*) as n')->groupBy('st.name_en')->pluck('n', 'name_en');

        return array_map(fn (array $states) => (int) collect($states)->sum(fn ($state) => $byState[$state] ?? 0), ProjectStatus::groups());
    }

    public function render(): View
    {
        $permitted = app(CityContext::class)->cityIds();

        return view('livewire.projects-index', [
            'projects' => $this->query()->paginate(15),
            'pathCounts' => $this->pathCounts(),
            'cityOptions' => City::whereIn('id', $permitted)->orderBy('id')->get(['id', 'code', 'name_ar', 'name_en']),
            'stateOptions' => ProjectState::ordered()->get(['id', 'name_ar', 'name_en']),
        ]);
    }
}
