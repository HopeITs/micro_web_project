<?php

declare(strict_types=1);

namespace App\Services\Assessments;

use App\Services\Transfer\Transforms;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The computed need assessment ("قياس الحاجة") of a project's beneficiary, read-only and never stored: the desktop MySQL view
 * `need_evaluation` (city dumps, ~line 5141) shown by CheckList_Form as criterion / indicator / mark rows with "المجموع:" =
 * TotalMarks, the sum of the 11 marks (a missing mark counts 0). The view's rules are reproduced as written, quirks included:
 * worktype gives 14 when the beneficiary *is* working (P_HasWork = 1); the assets indicator and mark use different joins;
 * illnesses, ages and workers count the household rows whose provider is the beneficiary (the beneficiary's own row included,
 * except for the workers count) and whose person state is of type "Active". Names are compared by Transforms::nameKey, so the
 * lookup rows merged from several cities still match the desktop's literal names.
 */
final class NeedAssessment
{
    /** The view's criteria in its UNION ALL order (CheckList.cs Get_NewChecklist). */
    public const CRITERIA = ['loss', 'worktype', 'maritalstatus', 'num_at_home', 'workers', 'child_elder', 'illness', 'home_type', 'project_place', 'assets', 'power_source'];

    private const PUBLIC_EMPLOYEE = 'موظّف قطاع عام';

    private const PRIVATE_EMPLOYEE = 'موظّف قطاع خاص';

    private const TOTAL_DISABILITY = 'إعاقة كليّة/عقليّة';

    private const MALIGNANT = 'أمراض خبيثة/مستعصية';

    private const OWNED = 'ملك';

    private const MARRIED = 'متزوج/ة';

    private const SINGLE = 'عازب/ة';

    private const MARRIED_RELATIONS = ['زوج/ة', 'ابن/ة'];

    private const SINGLE_RELATIONS = ['أب', 'أم'];

    private const ACTIVE = 'active';

    private bool $en = false;

    /**
     * @return array{rows: list<array{key: string, indicator: string, mark: ?int}>, total: int}|null null without a beneficiary
     */
    public function compute(int $projectId, ?int $personId): ?array
    {
        $project = DB::table('projects')->where('id', $projectId)->first();
        $person = $personId ? DB::table('people')->where('id', $personId)->first() : null;
        if ($project === null || $person === null) {
            return null;
        }
        $this->en = app()->getLocale() === 'en';
        $applicant = DB::table('project_applicants')->where('project_id', $projectId)->where('person_id', $personId)->whereNull('deleted_at')->orderBy('id')->first();
        $marital = $person->marital_status_id ? DB::table('marital_statuses')->where('id', $person->marital_status_id)->first() : null;

        $rows = [
            'loss' => $this->loss($person, $applicant),
            'worktype' => $this->workType($person, $applicant),
            'maritalstatus' => [$marital ? $this->name($marital) : '', $marital?->mark === null ? null : (int) $marital->mark],
            'num_at_home' => $this->numAtHome($person),
            'workers' => $this->workers($person, $applicant),
            'child_elder' => $this->childElder($person),
            'illness' => $this->illness($person, $applicant),
            'home_type' => $this->assetType($person->home_ownership_id, 'home_mark'),
            'project_place' => $this->assetType($project->place_type_id, 'project_mark'),
            'assets' => $this->assets($person, $applicant, $marital),
            'power_source' => $this->powerSource($person),
        ];
        $out = [];
        foreach (self::CRITERIA as $key) {
            [$indicator, $mark] = $rows[$key];
            $out[] = ['key' => $key, 'indicator' => $indicator, 'mark' => $mark];
        }

        return ['rows' => $out, 'total' => array_sum(array_map(fn ($r) => (int) ($r['mark'] ?? 0), $out))];
    }

    // ---------------------------------------------------------------- criteria (each returns [indicator, mark])

    private function loss(object $person, ?object $applicant): array
    {
        if ((int) ($person->military_in_war ?? 0) === 1 && (int) ($person->military_period ?? 0) > 4) {
            return [__('checklists.need.military'), 12];
        }
        $has = $this->flag($applicant?->has_loss);
        if ($has === 0) {
            return [__('checklists.need.none'), 4];
        }
        if ($has === null) {
            return [__('checklists.need.incomplete'), 0];
        }
        $rows = DB::table('person_losses as pl')->leftJoin('loss_types as l', 'l.id', '=', 'pl.loss_type_id')->where('pl.person_id', $person->id)->whereNull('pl.deleted_at')
            ->orderBy('pl.id')->get(['l.name_ar', 'l.name_en', 'l.mark']);
        $names = $this->concat($rows, ',');
        $mark = $rows->isEmpty() ? null : ($rows->count() > 1 ? 12 : ($rows[0]->mark === null ? null : (int) $rows[0]->mark));

        return [$names ?? __('checklists.need.incomplete'), $mark ?? 0];
    }

    private function workType(object $person, ?object $applicant): array
    {
        $has = $this->flag($applicant?->has_work);
        // The current jobs (W_Status = yes).
        $jobs = DB::table('person_work_experiences as w')->leftJoin('work_types as t', 't.id', '=', 'w.work_type_id')->where('w.person_id', $person->id)->whereNull('w.deleted_at')
            ->whereRaw('LOWER(w.status) = ?', ['yes'])->orderBy('w.id')->get(['t.name_ar', 't.name_en', 't.mark']);
        $several = $jobs->count() > 1;
        $public = $several && $this->contains($jobs, self::PUBLIC_EMPLOYEE);
        $private = $several && ! $public && $this->contains($jobs, self::PRIVATE_EMPLOYEE);

        $indicator = match (true) {
            $has === 0 => __('checklists.need.not_working'),
            $has === null => __('checklists.need.incomplete'),
            $jobs->isEmpty() => __('checklists.need.incomplete'),
            $public || $private => $this->concat($jobs, ',') ?? __('checklists.need.incomplete'),
            default => ($lowest = $this->lowestMark($jobs)) && $lowest->name_ar !== null ? $this->name($lowest) : __('checklists.need.incomplete'),
        };
        $mark = match (true) {
            $has === 1 => 14, // desktop quirk: 14 for a beneficiary who works
            $has === null => 0,
            $jobs->isEmpty() => 0,
            $public => 6,
            $private => 5,
            default => $jobs->whereNotNull('mark')->isEmpty() ? 0 : (int) $jobs->whereNotNull('mark')->min('mark'),
        };

        return [$indicator, $mark];
    }

    private function numAtHome(object $person): array
    {
        $n = $person->household_size === null ? null : (int) $person->household_size;
        $mark = match (true) {
            $n === null => 0,
            $n > 4 => 8,
            $n === 3, $n === 4 => 6,
            $n === 2 => 4,
            $n === 1 => 2,
            default => null, // 0 or less: the view's CASE has no ELSE
        };

        return [$n === null ? '' : (string) $n, $mark];
    }

    private function workers(object $person, ?object $applicant): array
    {
        $working = ($this->flag($applicant?->has_work) === 1 ? 1 : 0) + $this->household($person)->where('fm.has_work', true)->where('p.id', '<>', $person->id)->count();
        $n = $person->household_size === null ? null : (int) $person->household_size;
        if ($n === null) {
            return ['', null];
        }
        // MySQL: workers / NumAtHome to 4 decimals, then CAST((1 - ratio) * 20 AS SIGNED), which rounds half away from zero.
        $mark = $n === 0 ? null : (int) round((1 - round($working / $n, 4)) * 20);

        return [$working.'/'.$n, $mark];
    }

    private function childElder(object $person): array
    {
        $count = 0;
        foreach ($this->household($person)->pluck('p.birth_date') as $birth) {
            $age = $this->age($birth);
            if ($age !== null && ($age >= 70 || $age <= 3)) {
                $count++;
            }
        }

        return [(string) $count, match (true) {
            $count === 0 => 0, $count === 1 => 2, default => 4,
        }];
    }

    private function illness(object $person, ?object $applicant): array
    {
        $has = $this->flag($applicant?->has_illness);
        if ($has === 0) {
            return [__('checklists.need.none'), 0];
        }
        if ($has === null) {
            return [__('checklists.need.incomplete'), 0];
        }
        $rows = DB::table('person_illnesses as pi')->leftJoin('illnesses as i', 'i.id', '=', 'pi.illness_id')
            ->join('family_members as fm', fn ($j) => $j->on('fm.person_id', '=', 'pi.person_id')->whereNull('fm.deleted_at'))
            ->join('people as p', fn ($j) => $j->on('p.id', '=', 'fm.person_id')->whereNull('p.deleted_at'))
            ->join('person_states as s', 's.id', '=', 'p.person_state_id')
            ->where('fm.provider_person_id', $person->id)->whereRaw('LOWER(s.type) = ?', [self::ACTIVE])->whereNull('pi.deleted_at')
            ->orderBy('pi.id')->get(['i.name_ar', 'i.name_en', 'i.mark']);
        if ($rows->isEmpty()) {
            return [__('checklists.need.incomplete'), 0];
        }
        $both = $this->contains($rows, self::TOTAL_DISABILITY) && $this->contains($rows, self::MALIGNANT);
        if ($both) {
            return [$this->concat($rows, ',') ?? __('checklists.need.incomplete'), 10];
        }
        // ORDER BY Mark DESC LIMIT 1 (MySQL puts NULL marks last).
        $highest = $rows->whereNotNull('mark')->sortByDesc('mark')->first() ?? $rows->first();
        $marks = $rows->whereNotNull('mark');

        return [$highest->name_ar !== null ? $this->name($highest) : __('checklists.need.incomplete'), $marks->isEmpty() ? 0 : (int) $marks->max('mark')];
    }

    private function assetType(mixed $id, string $column): array
    {
        $type = $id ? DB::table('asset_types')->where('id', $id)->first() : null;

        return [$type ? $this->name($type) : __('checklists.need.unset'), $type && $type->{$column} !== null ? (int) $type->{$column} : 0];
    }

    private function assets(object $person, ?object $applicant, ?object $marital): array
    {
        $has = $this->flag($applicant?->has_assets);
        if ($has === 0) {
            return [__('checklists.need.none'), 0];
        }
        if ($has === null) {
            return [__('checklists.need.incomplete'), 0];
        }
        $status = Transforms::nameKey($marital?->name_ar);
        $relations = match ($status) {
            Transforms::nameKey(self::MARRIED) => self::MARRIED_RELATIONS,
            Transforms::nameKey(self::SINGLE) => self::SINGLE_RELATIONS,
            default => [],
        };
        $relations = array_map([Transforms::class, 'nameKey'], $relations);
        // The view's two subqueries join the person state through different rows: the indicator through the household row
        // (person_family.Person_ID), the mark through the asset's owner (person_asset.Person_ID).
        $base = fn (string $stateVia) => DB::table('person_assets as pa')
            ->leftJoin('asset_kinds as k', 'k.id', '=', 'pa.asset_kind_id')->leftJoin('asset_types as t', 't.id', '=', 'pa.asset_type_id')
            ->leftJoin('asset_benefits as b', 'b.id', '=', 'pa.asset_benefit_id')
            ->leftJoin('family_members as fm', fn ($j) => $j->on('fm.person_id', '=', 'pa.person_id')->whereNull('fm.deleted_at'))
            ->leftJoin('relations as r', 'r.id', '=', 'fm.relation_id')
            ->leftJoin('people as p', fn ($j) => $j->on('p.id', '=', $stateVia)->whereNull('p.deleted_at'))
            ->leftJoin('person_states as s', 's.id', '=', 'p.person_state_id')
            ->whereNull('pa.deleted_at')->orderBy('pa.id')
            ->get(['pa.person_id', 'pa.year_text', 'fm.provider_person_id', 'k.name_ar as kind_ar', 'k.name_en as kind_en', 't.name_ar as type_ar',
                'b.name_ar as benefit_ar', 'b.name_en as benefit_en', 'b.mark as benefit_mark', 'r.name_ar as relation_ar', 'r.name_en as relation_en', 's.type as state_type'])
            ->filter(fn ($r) => ((int) $r->person_id === (int) $person->id
                    || ($relations !== [] && (int) $r->provider_person_id === (int) $person->id && in_array(Transforms::nameKey($r->relation_ar), $relations, true)))
                && strtolower((string) $r->state_type) === self::ACTIVE && Transforms::nameKey($r->type_ar) === Transforms::nameKey(self::OWNED))->values();

        $lines = $base('fm.person_id')->map(function ($r) {
            $kind = $this->en && $r->kind_en ? $r->kind_en : $r->kind_ar;
            $benefit = $this->en && $r->benefit_en ? $r->benefit_en : $r->benefit_ar;
            $relation = $this->en && $r->relation_en ? $r->relation_en : ($r->relation_ar === null ? null : 'ال'.$r->relation_ar);

            // CONCAT is NULL when any part is NULL, and GROUP_CONCAT skips it.
            return in_array(null, [$kind, $r->year_text, $benefit, $relation], true) ? null : $kind.' - '.$r->year_text.' - '.$benefit.' - '.$relation;
        })->filter()->values();

        $owned = $base('pa.person_id');
        $mark = null;
        if ($owned->count() > 1) {
            $mark = -20;
        } elseif ($owned->count() === 1 && $owned[0]->year_text !== null && $owned[0]->benefit_mark !== null) {
            $year = preg_match('/^\s*(\d+)/', (string) $owned[0]->year_text, $m) ? (int) $m[1] : 0; // CAST(Year AS UNSIGNED)
            $mark = ($year < 2020 ? -5 : -15) + (int) $owned[0]->benefit_mark;
        }

        return [$lines->isEmpty() ? __('checklists.need.incomplete') : $lines->implode("\n"), $mark ?? 0];
    }

    private function powerSource(object $person): array
    {
        $rows = DB::table('person_power_sources as pp')->leftJoin('power_sources as s', 's.id', '=', 'pp.power_source_id')->where('pp.person_id', $person->id)->whereNull('pp.deleted_at')
            ->orderBy('pp.id')->get(['s.name_ar', 's.name_en', 's.mark']);
        if ($rows->isEmpty()) {
            return [__('checklists.need.incomplete'), 0];
        }
        $lowest = $this->lowestMark($rows);
        $marks = $rows->whereNotNull('mark');

        return [$lowest->name_ar !== null ? $this->name($lowest) : __('checklists.need.incomplete'), $marks->isEmpty() ? 0 : (int) $marks->min('mark')];
    }

    // ---------------------------------------------------------------- helpers

    /** Household rows whose provider is the person and whose person state is Active (person_family ⨝ person ⨝ personstate). */
    private function household(object $person): Builder
    {
        return DB::table('family_members as fm')->join('people as p', fn ($j) => $j->on('p.id', '=', 'fm.person_id')->whereNull('p.deleted_at'))
            ->join('person_states as s', 's.id', '=', 'p.person_state_id')
            ->where('fm.provider_person_id', $person->id)->whereNull('fm.deleted_at')->whereRaw('LOWER(s.type) = ?', [self::ACTIVE]);
    }

    /** 1, 0 or null (the desktop's -1 "not answered" is null in the unified schema). */
    private function flag(mixed $value): ?int
    {
        return $value === null ? null : ((int) $value === 1 ? 1 : 0);
    }

    /** TIMESTAMPDIFF(YEAR, birth, NOW()). */
    private function age(mixed $birth): ?int
    {
        if ($birth === null || $birth === '') {
            return null;
        }
        $b = Carbon::parse((string) $birth);
        $now = now();
        $years = $now->year - $b->year;
        if ($now->format('md His') < $b->format('md His')) {
            $years--;
        }

        return $years;
    }

    private function name(object $row): string
    {
        return $this->en && ($row->name_en ?? null) ? (string) $row->name_en : (string) $row->name_ar;
    }

    /** GROUP_CONCAT of the names (NULL names skipped; NULL when none). */
    private function concat(Collection $rows, string $separator): ?string
    {
        $names = $rows->filter(fn ($r) => $r->name_ar !== null)->map(fn ($r) => $this->name($r))->values();

        return $names->isEmpty() ? null : $names->implode($separator);
    }

    /** GROUP_CONCAT(Name) LIKE '%needle%'. */
    private function contains(Collection $rows, string $needle): bool
    {
        $key = Transforms::nameKey($needle);

        return $rows->contains(fn ($r) => $r->name_ar !== null && str_contains(Transforms::nameKey($r->name_ar), $key));
    }

    /** ORDER BY Mark LIMIT 1 (MySQL puts NULL marks first). */
    private function lowestMark(Collection $rows): ?object
    {
        return $rows->first(fn ($r) => $r->mark === null) ?? $rows->sortBy('mark')->first();
    }
}
