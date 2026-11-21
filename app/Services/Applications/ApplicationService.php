<?php

declare(strict_types=1);

namespace App\Services\Applications;

use App\Models\WebAccount;
use App\Services\AccountAccess;
use App\Services\AccountAuditTrail;
use App\Services\People\PeopleService;
use App\Services\People\PersonFields;
use App\Services\ProjectNumberAllocator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The application form on the unified schema (M4.5), as the desktop Application_New_Form: one save writes the project, its beneficiary
 * (new or registered, with the duplicate warnings of M4.3), the beneficiary's story and switches, the family grid, the beneficiary's
 * sub-tables and the project's own tables, in one transaction. Rules from Save_Application: a preliminary application needs the project
 * description and starts "On Hold"; a full one also needs the funding type and requested amount, and becoming full (directly or from
 * preliminary) needs the readiness check ({@see ApplicationReadiness}), moves the project to "Waiting" and opens the application-stage tasks.
 * Writing needs `applications.manage` and `people.manage` in the city.
 *
 * The form state is a plain array (see {@see blank()}), so a Livewire component and a draft can hold it as is.
 */
final class ApplicationService
{
    public const BENEFICIARY = 'مستفيد';

    public function __construct(
        private AccountAccess $access, private AccountAuditTrail $audit, private ProjectNumberAllocator $numbers, private PeopleService $people, private ApplicationReadiness $readiness,
    ) {}

    /** @return list<int> cities where the account may write applications */
    public function cities(WebAccount $account): array
    {
        $apps = $this->access->cities($account, 'applications.manage')->pluck('id')->all();
        $people = $this->access->cities($account, 'people.manage')->pluck('id')->all();

        return array_values(array_map('intval', array_intersect($apps, $people)));
    }

    public function canWrite(WebAccount $account, int $city): bool
    {
        return in_array($city, $this->cities($account), true);
    }

    private function deny(WebAccount $account): never
    {
        $this->audit->record($account->id, 'access.denied', 'denied');
        abort(403);
    }

    // ---------------------------------------------------------------- state

    /** An empty form for a city. */
    public function blank(WebAccount $account, int $city, string $stage = 'preliminary'): array
    {
        $this->canWrite($account, $city) || $this->deny($account);
        $state = [
            'city' => $city, 'stage' => $stage, 'number' => (string) $this->numbers->suggest($city),
            'project' => self::strings(ApplicationFields::flat(ApplicationFields::PROJECT, 'full'), []),
            'person_id' => null, 'person' => self::strings(PersonFields::personFlat(), []), 'family_number' => '',
            'applicant' => self::strings(ApplicationFields::flat(ApplicationFields::APPLICANT, 'full'), []),
            'grids' => array_fill_keys(array_keys(ApplicationFields::GRIDS), []), 'removed' => array_fill_keys(array_keys(ApplicationFields::GRIDS), []),
        ];
        $state['project']['request_date'] = now()->format('Y-m-d');

        return $state;
    }

    /** The saved application as form state. */
    public function load(WebAccount $account, int $projectId): array
    {
        $project = $this->project($account, $projectId);
        $city = (int) $project->city_id;
        $link = $this->beneficiaryLink($projectId);
        $person = $link ? DB::table('people')->where('id', $link->person_id)->first() : null;
        $state = [
            'city' => $city, 'stage' => (string) $project->application_stage, 'number' => (string) $project->project_number,
            'project' => self::strings(ApplicationFields::flat(ApplicationFields::PROJECT, 'full'), (array) $project),
            'person_id' => $person ? (int) $person->id : null, 'person' => self::strings(PersonFields::personFlat(), (array) ($person ?? [])),
            'family_number' => '', 'applicant' => self::strings(ApplicationFields::flat(ApplicationFields::APPLICANT, 'full'), (array) ($link ?? [])),
            'grids' => [], 'removed' => array_fill_keys(array_keys(ApplicationFields::GRIDS), []),
        ];
        $family = $person ? $this->familyOf((int) $person->id) : null;
        $state['family_number'] = $family ? (string) $family->family_number : '';
        foreach (array_keys(ApplicationFields::GRIDS) as $kind) {
            $state['grids'][$kind] = $this->gridRows($kind, $projectId, $person ? (int) $person->id : null, $family?->id);
        }

        return $state;
    }

    /** A stored decimal as typed: "150000.00" → "150000", "12.50" → "12.5"; zeros are trimmed only after a decimal point. */
    public static function plainNumber(mixed $value): string
    {
        $text = (string) $value;

        return str_contains($text, '.') ? rtrim(rtrim($text, '0'), '.') : $text;
    }

    /** @param  array<string, array>  $fields  @return array<string, string> values as the form holds them */
    public static function strings(array $fields, array $row): array
    {
        $out = [];
        foreach ($fields as $field => $spec) {
            $value = $row[$field] ?? null;
            $out[$field] = match (true) {
                $value === null => '',
                $spec['kind'] === 'bool' => (bool) $value ? '1' : '0',
                $spec['kind'] === 'date' => substr((string) $value, 0, 10),
                $spec['kind'] === 'choice' => strtolower((string) $value),
                $spec['kind'] === 'decimal' => self::plainNumber($value),
                default => (string) $value,
            };
        }

        return $out;
    }

    private function gridRows(string $kind, int $projectId, ?int $personId, ?int $familyId): array
    {
        $grid = ApplicationFields::grid($kind);
        $owner = ApplicationFields::GRIDS[$kind]['owner'];
        if ($owner === 'family') {
            if ($familyId === null || $personId === null) {
                return [];
            }
            $rows = DB::table('family_members as m')->join('people as p', 'p.id', '=', 'm.person_id')->where('m.family_id', $familyId)->where('m.person_id', '<>', $personId)
                ->whereNull('m.deleted_at')->whereNull('p.deleted_at')->orderBy('m.id')
                ->get(['m.id', 'm.relation_id', 'm.education_level_id', 'm.education_specialty_id', 'm.has_work', 'm.work_name', ...array_map(fn ($c) => 'p.'.$c, ApplicationFields::FAMILY_PERSON)]);
        } elseif ($owner === 'person') {
            if ($personId === null) {
                return [];
            }
            $rows = DB::table($grid['table'])->where('person_id', $personId)->whereNull('deleted_at')->orderBy('id')->get();
        } else {
            $rows = DB::table($grid['table'])->where('project_id', $projectId)->whereNull('deleted_at')->orderBy('id')->get();
        }

        return $rows->map(fn ($r) => ['id' => (int) $r->id] + self::strings($grid['fields'], (array) $r))->values()->all();
    }

    // ---------------------------------------------------------------- reading

    /** A project the account may read (applications.view in its city), or 404. */
    public function project(WebAccount $account, int $projectId): object
    {
        $readable = $this->access->cities($account, 'applications.view')->pluck('id')->map(fn ($id) => (int) $id)->all();
        $project = DB::table('projects')->where('id', $projectId)->whereNull('deleted_at')->whereIn('city_id', $readable ?: [0])->first();
        abort_if($project === null, 404);

        return $project;
    }

    private function beneficiaryLink(int $projectId): ?object
    {
        return DB::table('project_applicants')->where('project_id', $projectId)->whereNull('deleted_at')
            ->orderByRaw('CASE WHEN role = ? THEN 0 ELSE 1 END', [self::BENEFICIARY])->orderBy('id')->first();
    }

    private function familyOf(int $personId): ?object
    {
        $familyId = DB::table('family_members')->where('person_id', $personId)->whereNull('deleted_at')->orderBy('id')->value('family_id');

        return $familyId ? DB::table('families')->where('id', $familyId)->first() : null;
    }

    // ---------------------------------------------------------------- validation

    /**
     * Validates the whole form without writing. Returns the cleaned values; throws one ValidationException listing every field.
     *
     * @return array{project: array, person: array, applicant: array, grids: array<string, list<array>>}
     */
    public function validateState(array $state, ?int $projectId, bool $stageRules = true): array
    {
        $city = (int) $state['city'];
        $stage = $state['stage'] === 'full' ? 'full' : 'preliminary';
        $errors = [];
        $collect = function (callable $fn) use (&$errors) {
            try {
                return $fn();
            } catch (ValidationException $e) {
                $errors += $e->errors();

                return [];
            }
        };
        $current = $projectId ? (array) DB::table('projects')->where('id', $projectId)->first() : [];
        $project = $collect(fn () => $this->people->validate(ApplicationFields::flat(ApplicationFields::PROJECT, 'full'), $state['project'], $city, $current, 'project.'));
        if ($project !== []) {
            // Type detail ↔ type and career ↔ field are checked by the dependent-list rule of PeopleService::validate.
            if ($stage === 'full' && $stageRules) {
                foreach (['fund_type_id', 'requested_amount'] as $field) {
                    if ($project[$field] === null) {
                        $errors['project.'.$field] = [__('people.errors.required')];
                    }
                }
            }
            $project['project_category_id'] = $project['project_subcategory_id']
                ? (int) DB::table('project_subcategories')->where('id', $project['project_subcategory_id'])->value('project_category_id') : null;
        }
        $personCurrent = $state['person_id'] ? (array) DB::table('people')->where('id', $state['person_id'])->first() : [];
        $person = $collect(fn () => $this->people->validate(PersonFields::personFlat(), $state['person'], $city, $personCurrent, 'person.'));
        $linkCurrent = $projectId ? (array) ($this->beneficiaryLink($projectId) ?? []) : [];
        $applicant = $collect(fn () => $this->people->validate(ApplicationFields::flat(ApplicationFields::APPLICANT, 'full'), $state['applicant'], $city, $linkCurrent, 'applicant.'));
        $grids = [];
        foreach (array_keys(ApplicationFields::GRIDS) as $kind) {
            $grid = ApplicationFields::grid($kind);
            $grids[$kind] = [];
            foreach (array_values($state['grids'][$kind] ?? []) as $i => $row) {
                $values = $collect(fn () => $this->people->validate($grid['fields'], $row, $city, [], "grids.$kind.$i."));
                if ($values !== [] && array_filter($values, fn ($v) => $v !== null) === []) {
                    continue; // an untouched empty row
                }
                $grids[$kind][] = ['id' => isset($row['id']) && $row['id'] ? (int) $row['id'] : null] + $values;
            }
        }
        // A "No" answer cannot be saved together with the rows or fields it closes (ApplicationFields::SWITCHES).
        foreach (ApplicationFields::SWITCHES as $switch => $rule) {
            if (($applicant[$switch] ?? null) !== false) {
                continue;
            }
            foreach (isset($rule['grid']) ? array_values($state['grids'][$rule['grid']] ?? []) : [] as $i => $row) {
                if (ApplicationFields::governed($switch, $row) && array_filter(array_diff_key($row, ['id' => 0]), fn ($v) => $v !== '' && $v !== null) !== []) {
                    $errors["grids.{$rule['grid']}.$i.".array_key_first(ApplicationFields::grid($rule['grid'])['fields'])] = [__('applications.errors.switch_no', ['question' => __('applications.fields.'.$switch)])];
                }
            }
            foreach ($rule['fields'] ?? [] as $field) {
                if (($applicant[$field] ?? null) !== null) {
                    $errors['applicant.'.$field] = [__('applications.errors.switch_no', ['question' => __('applications.fields.'.$switch)])];
                }
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return ['project' => $project, 'person' => $person, 'applicant' => $applicant, 'grids' => $grids];
    }

    /** Readiness issues of the form for a full application (empty when ready). @return list<string> */
    public function readiness(array $state, array $values): array
    {
        return $this->readiness->messages($this->readiness->check($values, $state['family_number'] ?? ''));
    }

    // ---------------------------------------------------------------- saving

    /**
     * Saves the whole form in one transaction and returns the project id. `$confirmed` accepts found duplicate people.
     */
    public function save(WebAccount $account, array $state, ?int $projectId, bool $confirmed): int
    {
        $city = (int) $state['city'];
        $existing = null;
        if ($projectId !== null) {
            $existing = $this->project($account, $projectId);
            $city = (int) $existing->city_id;
            $state['city'] = $city;
        }
        $this->canWrite($account, $city) || $this->deny($account);
        $stage = $state['stage'] === 'full' ? 'full' : 'preliminary';
        if ($existing && $existing->application_stage === 'full') {
            $stage = 'full'; // a full application never goes back to preliminary
        }
        $state['stage'] = $stage;
        $values = $this->validateState($state, $projectId);
        $becomesFull = $stage === 'full' && ($existing === null || $existing->application_stage !== 'full');
        if ($becomesFull && ($issues = $this->readiness($state, $values)) !== []) {
            throw ValidationException::withMessages(['readiness' => $issues]);
        }
        $requested = trim((string) $state['number']);
        if ($requested !== '' && ! ctype_digit($requested)) {
            throw ValidationException::withMessages(['number' => __('applications.errors.number')]);
        }
        $number = $requested === '' ? null : (int) $requested;

        return DB::transaction(function () use ($account, $state, $values, $projectId, $existing, $city, $stage, $becomesFull, $number, $confirmed) {
            // The beneficiary.
            if ($state['person_id']) {
                $this->people->update($account, (int) $state['person_id'], $state['person'], $confirmed);
                $personId = (int) $state['person_id'];
                if ($this->familyOf($personId) === null) {
                    $this->people->joinFamily($personId, $city, (string) $state['family_number']);
                }
            } else {
                $personId = $this->people->create($account, $city, $state['person'], (string) $state['family_number'], $confirmed);
            }
            $now = now();
            $project = $values['project'];
            $project['application_stage'] = $stage;
            if ($existing === null) {
                $projectId = $this->numbers->claim($city, $number, fn (int $n) => (int) DB::table('projects')->insertGetId($project + [
                    'city_id' => $city, 'project_number' => $n, 'program_registrar_account_id' => $account->id, 'created_at' => $now, 'updated_at' => $now,
                ]));
                DB::table('project_applicants')->insert($values['applicant'] + ['city_id' => $city, 'project_id' => $projectId, 'person_id' => $personId, 'role' => self::BENEFICIARY, 'created_at' => $now, 'updated_at' => $now]);
                $this->setState($city, $projectId, $stage === 'full' ? 'Waiting' : 'On Hold');
            } else {
                if ($number !== null && $number !== (int) $existing->project_number) {
                    $this->renumber($city, $projectId, $number);
                }
                DB::table('projects')->where('id', $projectId)->update($project + ['edited_by_account_id' => $account->id, 'updated_at' => $now]);
                $link = $this->beneficiaryLink($projectId);
                if ($link === null) {
                    DB::table('project_applicants')->insert($values['applicant'] + ['city_id' => $city, 'project_id' => $projectId, 'person_id' => $personId, 'role' => self::BENEFICIARY, 'created_at' => $now, 'updated_at' => $now]);
                } else {
                    DB::table('project_applicants')->where('id', $link->id)->update($values['applicant'] + ['person_id' => $personId, 'updated_at' => $now]);
                }
                if ($becomesFull) {
                    $this->setState($city, $projectId, 'Waiting');
                }
            }
            if ($becomesFull) {
                $this->openApplicationTasks($city, $projectId);
            }
            $rowCounts = $this->saveGrids($city, $projectId, $personId, $values['grids'], $state['removed'] ?? []);
            // v1.3 change details: project and story fields that changed (with the number and stage), then rows added/changed/removed per table.
            // The beneficiary's own fields are recorded by PeopleService on its people.updated / people.created event.
            $before = $existing ? (array) $existing + ['applicant' => (array) ($link ?? [])] : [];
            $after = $project + ['project_number' => (int) DB::table('projects')->where('id', $projectId)->value('project_number')];
            $details = AccountAuditTrail::changes($before, $after) ?? [];
            foreach (AccountAuditTrail::changes((array) ($before['applicant'] ?? []), $values['applicant']) ?? [] as $item) {
                $details[] = ['field' => 'applicant.'.$item['field']] + $item;
            }
            foreach ($rowCounts as $kind => $counts) {
                $details[] = ['field' => 'rows.'.$kind, 'before' => null, 'after' => $counts];
            }
            $this->audit->record($account->id, $existing ? 'applications.updated' : 'applications.created', 'succeeded', $city, subjectType: 'application_entity', subjectId: $projectId,
                details: $details === [] ? null : $details);

            return $projectId;
        });
    }

    /** Changes the project number under the city's sequence lock (the desktop let staff correct MP_ID). */
    private function renumber(int $city, int $projectId, int $number): void
    {
        DB::table('project_number_sequences')->where('city_id', $city)->lockForUpdate()->first();
        if ($number < 1 || $number > 2147483647) {
            throw ValidationException::withMessages(['number' => __('applications.errors.number')]);
        }
        if (DB::table('projects')->where('city_id', $city)->where('project_number', $number)->where('id', '<>', $projectId)->exists()) {
            throw ValidationException::withMessages(['number' => __('projects.number_taken', ['next' => $this->numbers->suggest($city)])]);
        }
        DB::table('projects')->where('id', $projectId)->update(['project_number' => $number]);
        DB::table('project_number_sequences')->where('city_id', $city)->where('next_number', '<=', $number)->update(['next_number' => $number + 1, 'updated_at' => now()]);
    }

    /** Ends the current state and records the new one (by its English name, as the desktop's `state.Name_en`). */
    private function setState(int $city, int $projectId, string $stateName): void
    {
        $state = DB::table('project_states')->where('name_en', $stateName)->value('id');
        if ($state === null) {
            return;
        }
        DB::table('project_state_history')->where('project_id', $projectId)->where('is_current', true)->update(['is_current' => false, 'updated_at' => now()]);
        DB::table('project_state_history')->insert(['city_id' => $city, 'project_id' => $projectId, 'project_state_id' => $state, 'changed_on' => now()->format('Y-m-d'), 'is_current' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    /** The application-stage tasks (task type "a"), not done, as the desktop inserts them for a full application. */
    private function openApplicationTasks(int $city, int $projectId): void
    {
        $have = DB::table('project_tasks')->where('project_id', $projectId)->whereNull('deleted_at')->pluck('task_definition_id')->map(fn ($id) => (int) $id)->all();
        foreach (DB::table('task_definitions')->where('type', 'a')->where('is_active', true)->pluck('id') as $task) {
            if (! in_array((int) $task, $have, true)) {
                DB::table('project_tasks')->insert(['city_id' => $city, 'project_id' => $projectId, 'task_definition_id' => $task, 'state' => 0, 'task_date' => now(), 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    /**
     * @param  array<string, list<array>>  $grids
     * @param  array<string, list<int>>  $removed
     * @return array<string, string> per table with any change: "+added ~changed -removed" (audit details)
     */
    private function saveGrids(int $city, int $projectId, int $personId, array $grids, array $removed): array
    {
        $now = now();
        $counts = [];
        $count = function (string $kind, int $i) use (&$counts): void {
            $counts[$kind] ??= [0, 0, 0];
            $counts[$kind][$i]++;
        };
        $family = $this->familyOf($personId);
        foreach ($grids as $kind => $rows) {
            $grid = ApplicationFields::grid($kind);
            $owner = ApplicationFields::GRIDS[$kind]['owner'];
            $ownerColumn = $owner === 'project' ? ['project_id' => $projectId] : ['person_id' => $personId];
            foreach ($rows as $row) {
                $id = $row['id'];
                unset($row['id']);
                if ($owner === 'family') {
                    $personValues = array_intersect_key($row, array_flip(ApplicationFields::FAMILY_PERSON));
                    $memberValues = array_intersect_key($row, PersonFields::MEMBER);
                    if ($id === null) {
                        $member = (int) DB::table('people')->insertGetId($personValues + ['city_id' => $city, 'created_at' => $now, 'updated_at' => $now]);
                        DB::table('family_members')->insert($memberValues + ['city_id' => $city, 'family_id' => $family->id, 'person_id' => $member, 'provider_person_id' => $personId, 'created_at' => $now, 'updated_at' => $now]);
                        $count($kind, 0);
                    } else {
                        $memberRow = DB::table('family_members')->where('id', $id)->where('family_id', $family->id)->whereNull('deleted_at')->first() ?? abort(404);
                        $personBefore = DB::table('people')->where('id', $memberRow->person_id)->first();
                        if (AccountAuditTrail::changes($personBefore, $personValues) !== null || AccountAuditTrail::changes($memberRow, $memberValues) !== null) {
                            $count($kind, 1);
                        }
                        DB::table('people')->where('id', $memberRow->person_id)->update($personValues + ['updated_at' => $now]);
                        DB::table('family_members')->where('id', $id)->update($memberValues + ['updated_at' => $now]);
                    }

                    continue;
                }
                if ($id === null) {
                    DB::table($grid['table'])->insert($row + $ownerColumn + ['city_id' => $city, 'created_at' => $now, 'updated_at' => $now]);
                    $count($kind, 0);
                } else {
                    $mine = DB::table($grid['table'])->where('id', $id)->where($ownerColumn)->whereNull('deleted_at');
                    $old = (clone $mine)->first() ?? abort(404);
                    if (AccountAuditTrail::changes($old, $row) !== null) {
                        $count($kind, 1);
                    }
                    $mine->update($row + ['updated_at' => $now]);
                }
            }
        }
        foreach ($removed as $kind => $ids) {
            if (! isset(ApplicationFields::GRIDS[$kind]) || $ids === []) {
                continue;
            }
            $owner = ApplicationFields::GRIDS[$kind]['owner'];
            $query = DB::table(ApplicationFields::grid($kind)['table'])->whereIn('id', array_map('intval', $ids))->whereNull('deleted_at');
            match ($owner) {
                'family' => $query->where('family_id', $family?->id ?? 0)->where('person_id', '<>', $personId),
                'person' => $query->where('person_id', $personId),
                default => $query->where('project_id', $projectId),
            };
            $gone = $query->update(['deleted_at' => $now, 'updated_at' => $now]);
            for ($i = 0; $i < $gone; $i++) {
                $count($kind, 2);
            }
        }

        return array_map(fn (array $c) => '+'.$c[0].' ~'.$c[1].' -'.$c[2], $counts);
    }
}
