<?php

declare(strict_types=1);

namespace App\Services\Assessments;

use App\Models\WebAccount;
use App\Services\AccountAccess;
use App\Services\AccountAuditTrail;
use App\Services\Applications\ApplicationService;
use App\Services\People\PeopleService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The assessment checklist and scores of an application (M4.7, desktop CheckList_Form and the score block of Application_Form), on the
 * unified schema. The checklist belongs to the project's beneficiary; the scores to the project. Reading needs `assessments.view`,
 * writing, deleting and printing `assessments.manage` (the desktop showed Save/Delete/Print to administrators and data entry only).
 * Each yes/no question opens its own details (`when`); answering No clears them on save, as the desktop emptied them.
 */
final class ChecklistService
{
    /** Member lists: key persons, visitors/team, committee (checklist_positions.type). */
    public const LOCATIONS = ['k', 't', 'c'];

    /** checklists columns. `when` = the yes/no column that opens it; `members` = which member list a reference uses. */
    public const HEADER = [
        'visits' => [
            'has_home_visit' => ['kind' => 'bool'],
            'home_visit_date' => ['kind' => 'date', 'when' => 'has_home_visit'],
            'home_visit_time' => ['kind' => 'text', 'max' => 20, 'when' => 'has_home_visit'],
            'home_visitor_1_id' => ['kind' => 'member', 'members' => 't', 'when' => 'has_home_visit', 'needed' => true],
            'home_visitor_2_id' => ['kind' => 'member', 'members' => 't', 'when' => 'has_home_visit'],
            'home_visit_note' => ['kind' => 'textarea', 'when' => 'has_home_visit'],
            'has_project_visit' => ['kind' => 'bool'],
            'project_visit_date' => ['kind' => 'date', 'when' => 'has_project_visit'],
            'project_visit_time' => ['kind' => 'text', 'max' => 20, 'when' => 'has_project_visit'],
            'project_visitor_1_id' => ['kind' => 'member', 'members' => 't', 'when' => 'has_project_visit', 'needed' => true],
            'project_visitor_2_id' => ['kind' => 'member', 'members' => 't', 'when' => 'has_project_visit'],
            'project_visit_note' => ['kind' => 'textarea', 'when' => 'has_project_visit'],
            'visits_report' => ['kind' => 'textarea'],
        ],
        'experience' => [
            'needs_professional_test' => ['kind' => 'bool'],
            'professional_test_need_note' => ['kind' => 'textarea', 'when' => 'needs_professional_test'],
            'has_professional_test' => ['kind' => 'bool'],
            'professional_test_date' => ['kind' => 'date', 'when' => 'has_professional_test'],
            'professional_tester_id' => ['kind' => 'member', 'members' => null, 'when' => 'has_professional_test', 'needed' => true],
            'professional_test_note' => ['kind' => 'textarea', 'when' => 'has_professional_test', 'needed' => true],
            'professional_test_result' => ['kind' => 'textarea', 'when' => 'has_professional_test'],
            'has_professional_training' => ['kind' => 'bool'],
            'professional_trainer' => ['kind' => 'text', 'max' => 255, 'when' => 'has_professional_training', 'needed' => true],
            'professional_training_place' => ['kind' => 'text', 'max' => 255, 'when' => 'has_professional_training', 'needed' => true],
            'professional_training_start' => ['kind' => 'date', 'when' => 'has_professional_training'],
            'professional_training_end' => ['kind' => 'date', 'when' => 'has_professional_training'],
            'professional_training_note' => ['kind' => 'textarea', 'when' => 'has_professional_training', 'needed' => true],
            'professional_training_result' => ['kind' => 'textarea', 'when' => 'has_professional_training'],
            'has_management_training' => ['kind' => 'bool'],
            'management_trainer' => ['kind' => 'text', 'max' => 255, 'when' => 'has_management_training', 'needed' => true],
            'management_training_place' => ['kind' => 'text', 'max' => 255, 'when' => 'has_management_training', 'needed' => true],
            'management_training_date' => ['kind' => 'date', 'when' => 'has_management_training'],
            'management_training_result' => ['kind' => 'textarea', 'when' => 'has_management_training'],
        ],
        'decision' => [
            'team_date' => ['kind' => 'date'],
            'committee_date' => ['kind' => 'date'],
            'notes' => ['kind' => 'textarea'],
            'rejection_message' => ['kind' => 'bool'],
            'rejection_message_date' => ['kind' => 'date', 'when' => 'rejection_message'],
            'rejection_message_sender_account_id' => ['kind' => 'account', 'when' => 'rejection_message', 'needed' => true],
        ],
    ];

    public function __construct(private AccountAccess $access, private AccountAuditTrail $audit, private ApplicationService $applications, private PeopleService $people) {}

    /** @return array<string, array> every header field, flat */
    public static function fields(): array
    {
        return array_merge(...array_values(self::HEADER));
    }

    public function project(WebAccount $account, int $projectId): object
    {
        $project = $this->applications->project($account, $projectId);
        if (! $this->access->check($account, (int) $project->city_id, 'assessments.view')) {
            $this->audit->record($account->id, 'access.denied', 'denied');
            abort(403);
        }

        return $project;
    }

    public function canManage(WebAccount $account, object $project): bool
    {
        return $this->access->check($account, (int) $project->city_id, 'assessments.manage');
    }

    private function writable(WebAccount $account, int $projectId): object
    {
        $project = $this->project($account, $projectId);
        if (! $this->canManage($account, $project)) {
            $this->audit->record($account->id, 'access.denied', 'denied');
            abort(403);
        }

        return $project;
    }

    /** The project's beneficiary (the checklist's person), or null. */
    public function beneficiary(int $projectId): ?object
    {
        return DB::table('project_applicants as a')->join('people as p', 'p.id', '=', 'a.person_id')->where('a.project_id', $projectId)->whereNull('a.deleted_at')
            ->orderByRaw('CASE WHEN a.role = ? THEN 0 ELSE 1 END', [ApplicationService::BENEFICIARY])->orderBy('a.id')->first(['p.*']);
    }

    /** The person's checklist for this project: the one linked to the project, else the person's latest (old records had no project). */
    private function header(int $personId, int $projectId): ?object
    {
        return DB::table('checklists')->where('person_id', $personId)->whereNull('deleted_at')
            ->orderByRaw('CASE WHEN project_id = ? THEN 0 WHEN project_id IS NULL THEN 1 ELSE 2 END', [$projectId])->orderByDesc('id')->first();
    }

    // ---------------------------------------------------------------- lists

    /**
     * Checklist members of the city for a list ('k', 't', 'c', or null for all), with their position as group.
     *
     * @return Collection<int, object{id: int, label: string, group: ?string}>
     */
    public function members(int $city, ?string $location, array $keep = []): Collection
    {
        $en = app()->getLocale() === 'en';

        return DB::table('checklist_members as m')->leftJoin('checklist_positions as p', 'p.id', '=', 'm.checklist_position_id')->where('m.city_id', $city)
            ->where(fn ($q) => $q->where('m.is_active', true)->when($keep !== [], fn ($k) => $k->orWhereIn('m.id', array_map('intval', $keep))))
            ->when($location !== null, fn ($q) => $q->where('p.type', $location))
            ->orderBy('m.sort_order')->orderBy('m.name_ar')->get(['m.id', 'm.name_ar', 'm.name_en', 'p.name_ar as position_ar', 'p.name_en as position_en'])
            ->map(fn ($m) => (object) ['id' => (int) $m->id, 'label' => $en && $m->name_en ? $m->name_en : $m->name_ar, 'group' => $en && $m->position_en ? $m->position_en : $m->position_ar]);
    }

    /** Enabled accounts holding a role in the city: who may have sent a rejection message. */
    public function senders(int $city): Collection
    {
        return DB::table('web_accounts as a')->join('account_city_roles as r', 'r.account_id', '=', 'a.id')->where('r.city_id', $city)->whereNull('r.revoked_at')->whereNull('a.disabled_at')
            ->select('a.id', 'a.username')->distinct()->orderBy('a.username')->get()->map(fn ($a) => (object) ['id' => (int) $a->id, 'label' => (string) $a->username]);
    }

    public function opinions(): Collection
    {
        return DB::table('checklist_opinions')->where('is_active', true)->orderBy('sort_order')->orderBy('id')->get(['id', 'name_ar', 'name_en'])
            ->map(fn ($o) => (object) ['id' => (int) $o->id, 'label' => app()->getLocale() === 'en' && $o->name_en ? $o->name_en : $o->name_ar]);
    }

    /** The 10 assessment criteria with their options (value 1, 0 or -1 = N/A). */
    public function criteria(): Collection
    {
        $en = app()->getLocale() === 'en';
        $options = DB::table('score_options')->where('is_active', true)->orderBy('sort_order')->orderByDesc('value')->get()->groupBy('score_criterion_id');

        return DB::table('score_criteria')->where('is_active', true)->orderBy('sort_order')->orderBy('id')->get()->map(fn ($c) => (object) [
            'id' => (int) $c->id, 'label' => $en && $c->name_en ? $c->name_en : $c->name_ar,
            'options' => ($options->get($c->id) ?? collect())->map(fn ($o) => (object) ['value' => (int) $o->value, 'label' => $en && $o->name_en ? $o->name_en : $o->name_ar])->values(),
        ]);
    }

    // ---------------------------------------------------------------- state

    /** The form state of a project's checklist and scores. */
    public function load(WebAccount $account, int $projectId): array
    {
        $project = $this->project($account, $projectId);
        $person = $this->beneficiary($projectId);
        $header = $person ? $this->header((int) $person->id, $projectId) : null;
        $values = [];
        foreach (self::fields() as $field => $spec) {
            $v = $header->{$field} ?? null;
            $values[$field] = match (true) {
                $v === null => '',
                $spec['kind'] === 'bool' => (int) $v === 1 ? '1' : ((int) $v === 0 ? '0' : ''),
                $spec['kind'] === 'date' => substr((string) $v, 0, 10),
                default => (string) $v,
            };
        }
        $members = array_fill_keys(self::LOCATIONS, []);
        if ($person) {
            foreach (DB::table('checklist_member_opinions')->where('person_id', $person->id)->whereNull('deleted_at')->orderBy('id')->get() as $row) {
                $location = in_array($row->location, self::LOCATIONS, true) ? $row->location : 't';
                $members[$location][] = [
                    'id' => (int) $row->id, 'checklist_member_id' => (string) $row->checklist_member_id, 'checklist_opinion_id' => (string) ($row->checklist_opinion_id ?? ''),
                    'notes' => (string) ($row->notes ?? ''), 'name_notes' => (string) ($row->name_notes ?? ''), 'role_notes' => (string) ($row->role_notes ?? ''),
                ];
            }
        }
        $scores = [];
        $saved = DB::table('project_scores')->where('project_id', $projectId)->whereNull('deleted_at')->get()->keyBy('score_criterion_id');
        foreach ($this->criteria() as $c) {
            $row = $saved->get($c->id);
            $scores[$c->id] = ['value' => $row && $row->value !== null ? (string) $row->value : '', 'notes' => (string) ($row->notes ?? '')];
        }
        if ($header !== null || $saved->isNotEmpty()) {
            $this->audit->record($account->id, 'assessments.view', 'succeeded', (int) $project->city_id, subjectType: 'application_entity', subjectId: $projectId);
        }

        return ['header' => $values, 'members' => $members, 'removed' => [], 'scores' => $scores, 'exists' => $header !== null, 'person_id' => $person ? (int) $person->id : null];
    }

    // ---------------------------------------------------------------- saving

    public function save(WebAccount $account, int $projectId, array $state): void
    {
        $project = $this->writable($account, $projectId);
        $city = (int) $project->city_id;
        $person = $this->beneficiary($projectId) ?? throw ValidationException::withMessages(['header' => __('checklists.errors.no_beneficiary')]);
        $current = $this->header((int) $person->id, $projectId);
        $errors = [];
        $header = $this->validateHeader($state['header'] ?? [], $city, (array) ($current ?? []), $errors);
        $rows = $this->validateMembers($state['members'] ?? [], $city, (int) $person->id, $errors);
        $scores = $this->validateScores($state['scores'] ?? [], $errors);
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        DB::transaction(function () use ($account, $project, $city, $person, $current, $header, $rows, $scores, $state) {
            $now = now();
            // v1.3 change details: header fields, member rows per list, and each score by its criterion and option names.
            $details = AccountAuditTrail::changes((array) ($current ?? []), $header) ?? [];
            $memberCounts = [];
            $oldScores = DB::table('project_scores')->where('project_id', $project->id)->whereNull('deleted_at')->get()->keyBy('score_criterion_id');
            $criteria = $this->criteria()->keyBy('id');
            $scoreText = fn (int $criterion, ?int $value, ?string $notes) => trim(($value === null ? '' : ($criteria[$criterion]->options->firstWhere('value', $value)->label ?? (string) $value)).($notes ? ' · '.$notes : '')) ?: null;
            foreach ($criteria as $id => $c) {
                $old = $oldScores->get($id);
                $before = $old ? $scoreText($id, $old->value === null ? null : (int) $old->value, $old->notes) : null;
                $after = isset($scores[$id]) ? $scoreText($id, $scores[$id]['value'], $scores[$id]['notes']) : null;
                if ($before !== $after) {
                    $details[] = ['field' => 'score.c'.$id, 'before' => $before, 'after' => $after];
                }
            }
            if ($current === null) {
                DB::table('checklists')->insert($header + ['city_id' => $city, 'person_id' => $person->id, 'project_id' => $project->id, 'created_at' => $now, 'updated_at' => $now]);
            } else {
                DB::table('checklists')->where('id', $current->id)->update($header + ['project_id' => $current->project_id ?? $project->id, 'updated_at' => $now]);
            }
            foreach ($rows as $row) {
                $id = $row['id'];
                unset($row['id']);
                $memberCounts[$row['location']] ??= [0, 0, 0];
                if ($id === null) {
                    DB::table('checklist_member_opinions')->insert($row + ['city_id' => $city, 'person_id' => $person->id, 'recorded_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
                    $memberCounts[$row['location']][0]++;
                } else {
                    $old = DB::table('checklist_member_opinions')->where('id', $id)->first();
                    if (AccountAuditTrail::changes($old, $row) !== null) {
                        $memberCounts[$row['location']][1]++;
                    }
                    DB::table('checklist_member_opinions')->where('id', $id)->update($row + ['updated_at' => $now]);
                }
            }
            $removed = array_map('intval', $state['removed'] ?? []);
            if ($removed !== []) {
                foreach (DB::table('checklist_member_opinions')->whereIn('id', $removed)->where('person_id', $person->id)->whereNull('deleted_at')->pluck('location') as $location) {
                    $memberCounts[$location] ??= [0, 0, 0];
                    $memberCounts[$location][2]++;
                }
                DB::table('checklist_member_opinions')->whereIn('id', $removed)->where('person_id', $person->id)->whereNull('deleted_at')->update(['deleted_at' => $now, 'updated_at' => $now]);
            }
            foreach ($memberCounts as $location => $c) {
                if ($c !== [0, 0, 0]) {
                    $details[] = ['field' => 'members.'.$location, 'before' => null, 'after' => '+'.$c[0].' ~'.$c[1].' -'.$c[2]];
                }
            }
            // Scores replace the project's previous ones (the desktop deleted and re-inserted them).
            DB::table('project_scores')->where('project_id', $project->id)->whereNull('deleted_at')->update(['deleted_at' => $now, 'updated_at' => $now]);
            foreach ($scores as $criterion => $score) {
                DB::table('project_scores')->insert(['city_id' => $city, 'project_id' => $project->id, 'score_criterion_id' => $criterion, 'value' => $score['value'], 'notes' => $score['notes'], 'created_at' => $now, 'updated_at' => $now]);
            }
            $this->audit->record($account->id, 'assessments.saved', 'succeeded', $city, subjectType: 'application_entity', subjectId: (int) $project->id,
                details: $details === [] ? null : $details);
        });
    }

    /** Deletes the beneficiary's checklist, its member opinions and the project's scores (the desktop Delete button). */
    public function delete(WebAccount $account, int $projectId): void
    {
        $project = $this->writable($account, $projectId);
        $person = $this->beneficiary($projectId);
        DB::transaction(function () use ($account, $project, $person, $projectId) {
            $now = now();
            if ($person) {
                $header = $this->header((int) $person->id, $projectId);
                if ($header) {
                    DB::table('checklists')->where('id', $header->id)->update(['deleted_at' => $now, 'updated_at' => $now]);
                }
                DB::table('checklist_member_opinions')->where('person_id', $person->id)->whereNull('deleted_at')->update(['deleted_at' => $now, 'updated_at' => $now]);
            }
            DB::table('project_scores')->where('project_id', $projectId)->whereNull('deleted_at')->update(['deleted_at' => $now, 'updated_at' => $now]);
            $this->audit->record($account->id, 'assessments.saved', 'succeeded', (int) $project->city_id, subjectType: 'application_entity', subjectId: (int) $project->id,
                details: [['field' => 'checklist', 'before' => 'Checklist', 'after' => null]]);
        });
    }

    private function validateHeader(array $input, int $city, array $current, array &$errors): array
    {
        $fields = self::fields();
        $plain = [];
        foreach ($fields as $field => $spec) {
            $plain[$field] = match ($spec['kind']) {
                'member' => ['kind' => 'lookup', 'table' => 'checklist_members', 'city' => true] + array_intersect_key($spec, ['when' => 1]),
                'account' => ['kind' => 'int', 'min' => 1, 'max' => PHP_INT_MAX] + array_intersect_key($spec, ['when' => 1]),
                default => array_diff_key($spec, ['needed' => 1, 'members' => 1]),
            };
        }
        try {
            $values = $this->people->validate($plain, $input, $city, $current, 'header.');
        } catch (ValidationException $e) {
            $errors += $e->errors();

            return [];
        }
        foreach ($fields as $field => $spec) {
            // A member must belong to its list (visitors are the team list).
            if ($spec['kind'] === 'member' && $values[$field] !== null && ($spec['members'] ?? null) !== null
                && $values[$field] !== (int) ($current[$field] ?? 0) && ! $this->members($city, $spec['members'])->contains('id', $values[$field])) {
                $errors['header.'.$field] = [__('people.errors.choice')];
            }
            if ($spec['kind'] === 'account' && $values[$field] !== null && $values[$field] !== (int) ($current[$field] ?? 0) && ! $this->senders($city)->contains('id', $values[$field])) {
                $errors['header.'.$field] = [__('people.errors.choice')];
            }
            // The details a "Yes" needs (desktop check_data).
            if (($spec['needed'] ?? false) && ($values[$spec['when']] ?? null) === true && ($values[$field] === null || $values[$field] === '')) {
                $errors['header.'.$field] = [__('checklists.errors.needed')];
            }
        }
        $values['rejection_message'] = $values['rejection_message'] === null ? null : (int) $values['rejection_message'];

        return $values;
    }

    /** @return list<array<string, mixed>> */
    private function validateMembers(array $lists, int $city, int $personId, array &$errors): array
    {
        $rows = [];
        $opinions = $this->opinions()->pluck('id')->all();
        foreach (self::LOCATIONS as $location) {
            $allowed = $this->members($city, $location, array_column($lists[$location] ?? [], 'checklist_member_id'))->pluck('id')->all();
            foreach (array_values($lists[$location] ?? []) as $i => $row) {
                $key = "members.$location.$i.";
                $member = (int) ($row['checklist_member_id'] ?? 0);
                if ($member === 0 && trim(implode('', array_map('strval', array_diff_key($row, ['id' => 0])))) === '') {
                    continue; // an untouched empty row
                }
                if (! in_array($member, $allowed, true)) {
                    $errors[$key.'checklist_member_id'] = [__('people.errors.choice')];
                }
                $opinion = ($row['checklist_opinion_id'] ?? '') === '' ? null : (int) $row['checklist_opinion_id'];
                if ($location !== 'k' && $opinion !== null && ! in_array($opinion, $opinions, true)) {
                    $errors[$key.'checklist_opinion_id'] = [__('people.errors.choice')];
                }
                $id = ($row['id'] ?? null) ? (int) $row['id'] : null;
                if ($id !== null && ! DB::table('checklist_member_opinions')->where('id', $id)->where('person_id', $personId)->whereNull('deleted_at')->exists()) {
                    abort(404);
                }
                $text = fn (string $f, int $max) => mb_substr(trim((string) ($row[$f] ?? '')), 0, $max) ?: null;
                $rows[] = [
                    'id' => $id, 'location' => $location, 'checklist_member_id' => $member, 'checklist_opinion_id' => $location === 'k' ? null : $opinion,
                    'notes' => $text('notes', 65535), 'name_notes' => $location === 'k' ? $text('name_notes', 255) : null, 'role_notes' => $location === 'k' ? $text('role_notes', 255) : null,
                ];
            }
        }

        return $rows;
    }

    /** @return array<int, array{value: ?int, notes: ?string}> answered criteria only */
    private function validateScores(array $scores, array &$errors): array
    {
        $out = [];
        foreach ($this->criteria() as $c) {
            $value = trim((string) ($scores[$c->id]['value'] ?? $scores[(string) $c->id]['value'] ?? ''));
            $notes = trim((string) ($scores[$c->id]['notes'] ?? $scores[(string) $c->id]['notes'] ?? ''));
            if ($value === '' && $notes === '') {
                continue;
            }
            if ($value !== '' && ! $c->options->contains('value', (int) $value)) {
                $errors["scores.{$c->id}.value"] = [__('people.errors.choice')];
            }
            $out[$c->id] = ['value' => $value === '' ? null : (int) $value, 'notes' => $notes === '' ? null : mb_substr($notes, 0, 65535)];
        }

        return $out;
    }

    /** Everything the printable checklist needs (cl.docx layout). */
    public function printable(WebAccount $account, int $projectId): array
    {
        $project = $this->writable($account, $projectId);
        $state = $this->load($account, $projectId);
        $city = DB::table('cities')->where('id', $project->city_id)->first();
        $names = DB::table('checklist_members as m')->leftJoin('checklist_positions as p', 'p.id', '=', 'm.checklist_position_id')->where('m.city_id', $project->city_id)
            ->get(['m.id', 'm.name_ar', 'p.name_ar as position'])->keyBy('id');
        $this->audit->record($account->id, 'assessments.printed', 'succeeded', (int) $project->city_id, subjectType: 'application_entity', subjectId: $projectId);

        return [
            'project' => $project, 'city' => $city, 'person' => $this->beneficiary($projectId), 'state' => $state, 'names' => $names,
            'opinions' => $this->opinions()->keyBy('id'), 'criteria' => $this->criteria(),
            'sender' => $state['header']['rejection_message_sender_account_id'] ? DB::table('web_accounts')->where('id', $state['header']['rejection_message_sender_account_id'])->value('username') : null,
        ];
    }
}
