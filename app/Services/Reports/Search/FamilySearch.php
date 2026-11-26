<?php

declare(strict_types=1);

namespace App\Services\Reports\Search;

use App\Support\ArabicSearch;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * "Family members" (desktop `FamilyMember_bind`): every person with the family book, relation, residence state, work and the project of the
 * family's provider. As in the desktop's left joins a person with several family links or projects appears once for each. No project
 * filters apply (the rows are people); the text box searches names, the national number, the family number and the person number.
 */
final class FamilySearch extends SearchMode
{
    public function key(): string
    {
        return 'family';
    }

    public function group(): string
    {
        return 'applications';
    }

    public function capabilities(): array
    {
        return ['people.view'];
    }

    public function filterKind(): string
    {
        return 'none';
    }

    public function hasProject(): bool
    {
        return false;
    }

    public function columns(): array
    {
        return ['person_number', 'family_number', 'person', 'father', 'mother', 'relation', 'national_number', 'sex', 'birth_date', 'person_state', 'works', 'work', 'project_number'];
    }

    public function numeric(): array
    {
        return [1];
    }

    public function sensitive(): array
    {
        return [7];
    }

    public function linkColumn(): int
    {
        return 3;
    }

    public function query(array $cityIds, array $filters): Builder
    {
        $q = DB::table('family_members as fm')->join('people as pe', 'pe.id', '=', 'fm.person_id')->join('families as f', 'f.id', '=', 'fm.family_id')
            ->join('cities as ci', 'ci.id', '=', 'pe.city_id')
            ->leftJoin('project_applicants as a', fn ($j) => $j->on('a.person_id', '=', DB::raw('COALESCE(fm.provider_person_id, fm.person_id)'))->whereNull('a.deleted_at'))
            ->leftJoin('projects as p', fn ($j) => $j->on('p.id', '=', 'a.project_id')->whereNull('p.deleted_at'))
            ->whereIn('pe.city_id', $cityIds === [] ? [0] : $cityIds)->whereNull('fm.deleted_at')->whereNull('pe.deleted_at')->whereNull('f.deleted_at')
            ->addSelect(['fm.id as member_id', 'p.id as project_id', 'pe.city_id', 'pe.id as person_id', 'p.project_number', 'ci.code', 'ci.name_ar as city_ar', 'ci.name_en as city_en',
                'f.family_number', 'pe.first_name', 'pe.last_name', 'pe.father_name', 'pe.mother_name', 'pe.national_number', 'pe.sex', 'pe.birth_date', 'fm.has_work', 'fm.work_name']);
        $this->lookup($q, 'relations', 'rl', 'fm.relation_id', 'relation');
        $this->lookup($q, 'person_states', 'ps', 'pe.person_state_id', 'pstate');

        $term = (string) ($filters['q'] ?? '');
        if ($term !== '') {
            $q->where(function ($w) use ($term) {
                foreach (array_slice(preg_split('/\s+/u', $term, -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, 5) as $word) {
                    $w->where(function ($n) use ($word) {
                        foreach (['pe.first_name', 'pe.last_name', 'pe.father_name', 'pe.mother_name'] as $column) {
                            ArabicSearch::orWhereLike($n, $column, $word);
                        }
                        $n->orWhere('pe.national_number', 'like', '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $word).'%')
                            ->orWhere('f.family_number', 'like', '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $word).'%');
                        if (ctype_digit($word) && strlen($word) <= 10) {
                            $n->orWhere('pe.id', (int) $word);
                        }
                    });
                }
            });
        }

        return $q;
    }

    public function sorts(): array
    {
        return [
            'city' => [$this->byName('ci')], 'person_number' => ['pe.id'], 'family_number' => ['f.family_number'], 'person' => ['pe.first_name', 'pe.last_name'], 'father' => ['pe.father_name'],
            'mother' => ['pe.mother_name'], 'relation' => [$this->byName('rl')], 'sex' => ['pe.sex'], 'birth_date' => ['pe.birth_date'], 'person_state' => [$this->byName('ps')],
            'works' => ['fm.has_work'], 'work' => ['fm.work_name'], 'project_number' => ['p.project_number'],
        ];
    }

    public function order(Builder $query): Builder
    {
        return $query->orderBy('pe.city_id')->orderBy('pe.id')->orderBy('fm.id')->orderBy('a.id');
    }

    public function lines(Collection $rows): array
    {
        $sex = ['male' => __('search.sex.male'), 'female' => __('search.sex.female')];

        return $rows->map(fn ($r) => [
            $r->person_id, (string) $r->family_number, $this->personName($r), (string) $r->father_name, (string) $r->mother_name, $this->name($r, 'relation'),
            (string) $r->national_number, $sex[$r->sex] ?? '', $this->day($r->birth_date), $this->name($r, 'pstate'),
            $r->has_work === null ? '' : ((int) $r->has_work === 1 ? __('search.yes') : __('search.no')), (string) $r->work_name,
            $r->project_id === null ? '' : $this->number($r),
        ])->all();
    }

    public function target(object $row): ?array
    {
        return ['person', (int) $row->person_id];
    }
}
