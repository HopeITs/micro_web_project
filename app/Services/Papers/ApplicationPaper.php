<?php

declare(strict_types=1);

namespace App\Services\Papers;

use App\Models\WebAccount;
use App\Services\AccountAuditTrail;
use App\Services\Applications\ApplicationFields;
use App\Services\Applications\ApplicationService;
use App\Services\ProjectNumberAllocator;
use Illuminate\Support\Facades\DB;

/**
 * M4.20: everything the printed application form needs (استمارة المشروع F03 and the preliminary form F02), read from the saved
 * application through {@see ApplicationService::load} so the permission is the same as for opening it (applications.view in the
 * city). Every list value is resolved to its name in the page language; the print never shows an id.
 */
final class ApplicationPaper
{
    public function __construct(private ApplicationService $applications, private AccountAuditTrail $audit) {}

    /**
     * @return array<string, mixed>
     */
    public function forPrint(WebAccount $account, int $projectId, ?string $place = null): array
    {
        $data = $this->build($account, $projectId, $place);
        $this->audit->record($account->id, 'papers.printed', 'succeeded', (int) $data['city']['id'], subjectType: 'application_entity', subjectId: $projectId,
            decision: 'paper:'.($data['preliminary'] ? 'F02' : 'F03'));

        return $data;
    }

    /** @return array<string, mixed> */
    public function build(WebAccount $account, int $projectId, ?string $place = null): array
    {
        $state = $this->applications->load($account, $projectId);
        $project = $this->applications->project($account, $projectId);
        $city = DB::table('cities')->where('id', (int) $state['city'])->first();
        $stage = $state['stage'] === 'preliminary' ? 'preliminary' : 'full';
        $ar = app()->isLocale('ar');

        $specs = [
            'project' => ApplicationFields::flat(ApplicationFields::PROJECT, 'full'),
            'person' => ApplicationFields::person('full'),
            'applicant' => ApplicationFields::flat(ApplicationFields::APPLICANT, 'full'),
        ];
        $grids = [];
        foreach (array_keys(ApplicationFields::GRIDS) as $kind) {
            $grids[$kind] = ApplicationFields::grid($kind)['fields'];
        }
        $labels = $this->labels($state, $specs, $grids);

        $out = [
            'city' => ['id' => (int) $city->id, 'code' => (string) $city->code, 'name' => $ar ? $city->name_ar : $city->name_en],
            'place' => $place !== null && in_array($place, PaperCity::places((string) $city->code), true) ? $place : (string) $city->code,
            'number' => ProjectNumberAllocator::display((string) $city->code, (int) $state['number']),
            'stage' => $stage, 'preliminary' => $stage === 'preliminary',
            'family_number' => (string) $state['family_number'],
            'registrars' => [
                'paper' => $this->username($project->paper_registrar_account_id ?? null),
                'program' => $this->username($project->program_registrar_account_id ?? null),
            ],
            'project' => $this->display($specs['project'], $state['project'], $labels),
            'person' => $this->display($specs['person'], $state['person'], $labels) + $this->householdCounts($state['person_id']),
            'applicant' => $this->display($specs['applicant'], $state['applicant'], $labels),
            'grids' => [],
            'goals' => $this->goals($state),
            'fund_types' => $this->fundTypes((string) ($state['project']['fund_type_id'] ?? '')),
        ];
        foreach ($grids as $kind => $fields) {
            $out['grids'][$kind] = array_map(fn (array $row) => $this->display($fields, $row, $labels), $state['grids'][$kind] ?? []);
        }
        $out['family_members'] = $out['grids']['family'];

        return $out;
    }

    /** Every goal in its list order, with whether this beneficiary ticked it. */
    private function goals(array $state): array
    {
        $ticked = collect($state['grids']['goals'] ?? [])->pluck('goal_id')->map(fn ($id) => (int) $id)->all();

        return DB::table('goals')->orderBy('id')->get(['id', 'name_ar', 'name_en'])
            ->map(fn ($goal) => ['id' => (int) $goal->id, 'label' => app()->isLocale('ar') ? ($goal->name_ar ?: $goal->name_en) : ($goal->name_en ?: $goal->name_ar), 'ticked' => in_array((int) $goal->id, $ticked, true)])->all();
    }

    /** Every funding type (loan, grant…) with whether this project has it. */
    private function fundTypes(string $chosen): array
    {
        $ar = app()->isLocale('ar');

        return DB::table('fund_types')->orderBy('id')->get(['id', 'name_ar', 'name_en'])
            ->map(fn ($row) => ['id' => (int) $row->id, 'label' => (string) ($ar ? ($row->name_ar ?: $row->name_en) : ($row->name_en ?: $row->name_ar)), 'ticked' => (string) $row->id === $chosen])->all();
    }

    /** The family and household counts live on the person but are not form fields, so they are read directly. @return array<string, string> */
    private function householdCounts(?int $personId): array
    {
        $row = $personId ? DB::table('people')->where('id', $personId)->first(['family_size', 'household_size']) : null;

        return ['family_size' => (string) ($row->family_size ?? ''), 'household_size' => (string) ($row->household_size ?? '')];
    }

    private function username(mixed $id): ?string
    {
        return $id ? DB::table('web_accounts')->where('id', (int) $id)->value('username') : null;
    }

    /**
     * The name of every list value used, by table and id, in one query per table.
     *
     * @param  array<string, array<string, array>>  $grids
     * @return array<string, array<int, string>>
     */
    private function labels(array $state, array $specs, array $grids): array
    {
        $ids = [];
        $collect = function (array $fields, array $row) use (&$ids): void {
            foreach ($fields as $field => $spec) {
                if (($spec['kind'] ?? '') === 'lookup' && ($row[$field] ?? '') !== '') {
                    $ids[$spec['table']][(int) $row[$field]] = true;
                }
            }
        };
        foreach (['project', 'person', 'applicant'] as $part) {
            $collect($specs[$part], $state[$part]);
        }
        foreach ($grids as $kind => $fields) {
            foreach ($state['grids'][$kind] ?? [] as $row) {
                $collect($fields, $row);
            }
        }
        $ar = app()->isLocale('ar');
        $labels = [];
        $groupOf = [];
        $collectGroups = function (array $fields, array $row) use (&$groupOf): void {
            foreach ($fields as $field => $spec) {
                if (($spec['kind'] ?? '') === 'lookup' && isset($spec['group']) && ($row[$field] ?? '') !== '') {
                    $groupOf[$spec['table']] = [$spec['group'], $groupOf[$spec['table']][1] ?? []];
                    $groupOf[$spec['table']][1][(int) $row[$field]] = true;
                }
            }
        };
        foreach (['project', 'person', 'applicant'] as $part) {
            $collectGroups($specs[$part], $state[$part]);
        }
        foreach ($grids as $kind => $fields) {
            foreach ($state['grids'][$kind] ?? [] as $row) {
                $collectGroups($fields, $row);
            }
        }
        foreach ($groupOf as $table => [$group, $set]) {
            $parents = DB::table($table)->whereIn('id', array_keys($set))->pluck($group['column'], 'id');
            $names = DB::table($group['table'])->whereIn('id', $parents->filter()->unique()->all())->get(['id', 'name_ar', 'name_en'])->keyBy('id');
            foreach ($parents as $id => $parent) {
                $row = $names[$parent] ?? null;
                $labels['__group'][$table][(int) $id] = $row ? (string) ($ar ? ($row->name_ar ?: $row->name_en) : ($row->name_en ?: $row->name_ar)) : '';
            }
        }
        foreach ($ids as $table => $set) {
            foreach (DB::table($table)->whereIn('id', array_keys($set))->get(['id', 'name_ar', 'name_en']) as $row) {
                $labels[$table][(int) $row->id] = (string) ($ar ? ($row->name_ar ?: $row->name_en) : ($row->name_en ?: $row->name_ar));
            }
        }

        return $labels;
    }

    /**
     * Form values as text for the page: lists by name, yes/no as the words, sex as the words; empty stays empty.
     *
     * @param  array<string, array>  $fields
     * @param  array<string, string>  $row
     * @return array<string, string>
     */
    private function display(array $fields, array $row, array $labels): array
    {
        $out = [];
        foreach ($fields as $field => $spec) {
            $value = (string) ($row[$field] ?? '');
            $out[$field] = match (true) {
                $value === '' => '',
                ($spec['kind'] ?? '') === 'lookup' => $labels[$spec['table']][(int) $value] ?? '',
                ($spec['kind'] ?? '') === 'bool' => $value === '1' ? 'yes' : 'no',
                ($spec['kind'] ?? '') === 'sex' => $value === 'female' ? 'female' : 'male',
                default => $value,
            };
        }
        foreach ($fields as $field => $spec) {
            if (($spec['kind'] ?? '') === 'lookup' && isset($spec['group']) && (string) ($row[$field] ?? '') !== '') {
                $out[$field.'_group'] = $labels['__group'][$spec['table']][(int) $row[$field]] ?? '';
            }
        }
        // Keep the keys the sub-table rows carry besides their columns (family members join the person columns).
        foreach ($row as $key => $value) {
            $out[$key] ??= (string) $value;
        }

        return $out;
    }
}
