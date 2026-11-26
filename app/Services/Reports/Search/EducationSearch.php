<?php

declare(strict_types=1);

namespace App\Services\Reports\Search;

use App\Services\Reports\ProjectReportFilters;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * "Educations" (desktop `bind_Educations`): every family member with relation and education, under the project of the family's provider.
 * The education is the member's own family row or, for the provider's own row, the provider's `person_educations` rows (a provider with several
 * rows appears once for each).
 */
final class EducationSearch extends SearchMode
{
    public function key(): string
    {
        return 'educations';
    }

    public function group(): string
    {
        return 'people';
    }

    public function capabilities(): array
    {
        return ['people.view', 'applications.view'];
    }

    public function columns(): array
    {
        return ['number', 'person', 'relation', 'education_level', 'degree', 'specialization'];
    }

    public function query(array $cityIds, array $filters): Builder
    {
        $q = DB::table('family_members as fm')->whereNull('fm.deleted_at')
            ->join('people as pe', fn ($j) => $j->on('pe.id', '=', 'fm.person_id')->whereNull('pe.deleted_at'))
            ->join('project_applicants as a', fn ($j) => $j->on('a.person_id', '=', DB::raw('COALESCE(fm.provider_person_id, fm.person_id)'))->whereNull('a.deleted_at'))
            ->leftJoin('person_educations as ed', fn ($j) => $j->on('ed.person_id', '=', 'fm.person_id')->whereNull('ed.deleted_at')
                ->whereRaw('(fm.provider_person_id IS NULL OR fm.provider_person_id = fm.person_id)'))
            ->leftJoin('relations as rl', 'rl.id', '=', 'fm.relation_id')
            ->leftJoin('education_levels as el', 'el.id', '=', DB::raw('COALESCE(fm.education_level_id, ed.education_level_id)'))
            ->leftJoin('education_specialties as es', 'es.id', '=', DB::raw('COALESCE(fm.education_specialty_id, ed.education_specialty_id)'))
            ->leftJoin('education_types as et', 'et.id', '=', 'es.education_type_id');
        $this->joinProject($q, 'a.project_id', $cityIds);
        $q->addSelect([...$this->baseColumns(), 'fm.id as member_id', 'pe.id as person_id', 'pe.first_name', 'pe.last_name', 'rl.name_ar as relation_ar', 'rl.name_en as relation_en',
            'el.name_ar as level_ar', 'el.name_en as level_en', 'et.name_ar as degree_ar', 'et.name_en as degree_en', 'es.name_ar as spec_ar', 'es.name_en as spec_en']);

        return ProjectReportFilters::apply($q, $filters);
    }

    public function sorts(): array
    {
        return $this->projectSorts([
            'person' => ['pe.first_name', 'pe.last_name'], 'relation' => 'rl', 'education_level' => 'el', 'degree' => 'et', 'specialization' => 'es',
        ]);
    }

    public function order(Builder $query): Builder
    {
        return $query->orderBy('p.project_number')->orderBy('p.city_id')->orderBy('fm.id')->orderBy('ed.id')->orderBy('a.id');
    }

    public function lines(Collection $rows): array
    {
        return $rows->map(fn ($r) => [$this->number($r), $this->personName($r), $this->name($r, 'relation'), $this->name($r, 'level'), $this->name($r, 'degree'), $this->name($r, 'spec')])->all();
    }
}
