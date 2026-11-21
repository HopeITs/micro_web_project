<?php

declare(strict_types=1);

namespace App\Services\Field;

use App\Models\WebAccount;
use App\Services\AccountAuditTrail;
use App\Services\Finance\Fields;
use App\Services\Lifecycle\LifecycleService;
use App\Services\Staff\ProjectNotices;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Opening, closing and rehabilitation closing visits of a project (M4.12, desktop `VOpening_*`, `VClosing_Form`, `VClosing_Rehab_Form`,
 * `Visit.cs`, `Office_Monitoring.cs`, `VisitRehab.cs`) on the unified schema: `visits`, `visit_answers`, `visit_visitors`, with the project's
 * place (`projects.street_id`, `address_after_fund`), its tasks, its shared visit notices and its state through LifecycleService.
 *
 * Reading needs `applications.view` + `visits.view` in the project's city (another city: 404); writing `visits.manage`; deleting
 * `visits.delete`. One opening and one closing per project; rehabilitation grant projects only get the rehabilitation closing; vehicle
 * projects the vehicle forms. Owner decisions (21 Nov 2026): at least one visitor (2); the closing visit moves a Financed project to
 * Finished on its first save only (3); deletion is soft, unticks the task and makes the visit's notice unseen again, a Finished state is
 * kept (4); visits written by Kobo are read-only (5); the date is required, not in the future and not before the loan (6).
 */
final class VisitService
{
    public function __construct(private VisitSupport $support, private AccountAuditTrail $audit, private LifecycleService $lifecycle, private ProjectNotices $projectNotices) {}

    // ---------------------------------------------------------------- reading

    /**
     * Read model of the project's visits page: opening / closing / rehabilitation cards (`visits.view`) and the M&E visits per person
     * (`me_visits.view`), with the add buttons the account may use. Needs either capability; `$opened` audits the page visit once.
     */
    public function hub(WebAccount $account, int $projectId, bool $opened = false): array
    {
        $project = $this->lifecycle->project($account, $projectId);
        $city = (int) $project->city_id;
        $canVisits = $this->support->can($account, $city, 'visits.view');
        $canMe = $this->support->can($account, $city, 'me_visits.view');
        if (! $canVisits && ! $canMe) {
            $this->support->require($account, $city, 'visits.view');
        }
        $class = $this->support->classify($project);
        $current = $this->support->current($projectId);
        $visits = $canVisits ? $this->visits($projectId) : collect();
        $me = app(MonitoringVisitService::class);
        if ($opened) {
            $this->audit->record($account->id, $canVisits ? 'visits.view' : 'me_visits.view', 'succeeded', $city, subjectType: 'application_entity', subjectId: $projectId);
        }

        return [
            'project' => $project, 'header' => $this->support->header($project), 'class' => $class, 'current' => $current, 'funded' => $this->support->funded($current),
            'canVisits' => $canVisits, 'canMe' => $canMe, 'cards' => $this->cards($visits), 'addable' => $canVisits ? $this->addable($account, $project, $visits, $class, $current) : [],
            'people' => $canMe ? $me->people($project) : collect(), 'meCreatable' => $canMe && $me->creatable($account, $project, $class),
        ];
    }

    /** The project's visits of every phase (not deleted), oldest first. */
    public function visits(int $projectId): Collection
    {
        return DB::table('visits')->where('project_id', $projectId)->whereNull('deleted_at')->orderBy('id')->get();
    }

    private function answers(array $visitIds): Collection
    {
        return DB::table('visit_answers')->whereIn('visit_id', $visitIds ?: [0])->whereNull('deleted_at')->orderBy('id')->get();
    }

    private function visitorRows(array $visitIds): Collection
    {
        return DB::table('visit_visitors')->whereIn('visit_id', $visitIds ?: [0])->whereNull('deleted_at')->orderBy('id')->get(['id', 'visit_id', 'account_id']);
    }

    /** A fingerprint of what the page showed: the project's visits, their answers and visitors, and the project's place. */
    public function version(object $project, Collection $visits): string
    {
        $ids = $visits->pluck('id')->all();
        $plain = fn (Collection $rows) => $rows->map(fn ($r) => collect((array) $r)->map(fn ($v) => $v === null ? null : (string) $v)->all())->values()->all();

        return hash('sha256', json_encode([$plain($visits), $plain($this->answers($ids)), $plain($this->visitorRows($ids)), $project->street_id, $project->address_after_fund], JSON_THROW_ON_ERROR));
    }

    /** The visit of a phase (`Kind` contains op / cl, as the desktop's `Has_Visit`), the first when old data holds two. */
    private function ofPhase(Collection $visits, string $phase): ?object
    {
        $needle = $phase === 'opening' ? 'op' : 'cl';

        return $visits->first(fn ($v) => str_contains((string) $v->kind, $needle));
    }

    /** Which kinds the account may still add to the project (an empty list when it may not write or the project is not funded). */
    public function addable(WebAccount $account, object $project, Collection $visits, array $class, ?object $current): array
    {
        if (! $this->support->can($account, (int) $project->city_id, 'visits.manage') || ! $this->support->funded($current)) {
            return [];
        }
        $out = [];
        foreach (['opening', 'closing'] as $phase) {
            $kind = VisitKinds::kindFor($phase, $class['vehicle'], $class['rehab']);
            if ($kind !== null && $this->ofPhase($visits, $phase) === null) {
                $out[] = $kind;
            }
        }

        return $out;
    }

    /** Cards of the visits page: each visit with its date, visitors (accounts or Kobo names) and the Kobo mark. */
    public function cards(Collection $visits): Collection
    {
        $ids = $visits->pluck('id')->map(fn ($id) => (int) $id)->all();
        $kobo = $this->support->koboVisits($ids);
        $koboNames = $this->support->koboVisitorNames('visits', array_keys($kobo));
        $rows = $this->visitorRows($ids);
        $names = $this->support->names($rows->pluck('account_id')->all());

        return $visits->map(fn ($v) => (object) [
            'id' => (int) $v->id, 'kind' => (string) $v->kind, 'phase' => VisitKinds::phase((string) $v->kind), 'date' => Fields::day($v->visit_date), 'kobo' => isset($kobo[(int) $v->id]),
            'visitors' => isset($kobo[(int) $v->id]) && $rows->where('visit_id', $v->id)->isEmpty() ? ($koboNames[(int) $v->id] ?? [])
                : $rows->where('visit_id', $v->id)->map(fn ($r) => $names[(int) $r->account_id] ?? '#'.$r->account_id)->values()->all(),
            'created_by' => (string) ($v->created_by_name ?? ''),
        ]);
    }

    /** Read model of one questionnaire: the stored visit of the kind's phase, or an empty form when the kind may be added. */
    public function form(WebAccount $account, int $projectId, string $kind): array
    {
        VisitKinds::has($kind) || abort(404);
        $project = $this->support->project($account, $projectId, 'visits.view');
        $city = (int) $project->city_id;
        $visits = $this->visits($projectId);
        $class = $this->support->classify($project);
        $phase = VisitKinds::phase($kind);
        $visit = $visits->first(fn ($v) => $v->kind === $kind) ?? null;
        if ($visit === null) {
            in_array($kind, $this->addable($account, $project, $visits, $class, $this->support->current($projectId)), true) || abort(404);
        }
        $answers = $visit === null ? collect() : $this->answers([(int) $visit->id]);
        $chosen = $visit === null ? [] : $this->visitorRows([(int) $visit->id])->pluck('account_id')->map(fn ($id) => (int) $id)->all();
        $kobo = $visit !== null && $this->support->koboVisits([(int) $visit->id]) !== [];
        $canManage = $this->support->can($account, $city, 'visits.manage');
        $definition = $kind === VisitKinds::REHAB ? null : VisitKinds::definition($kind);

        return [
            'project' => $project, 'header' => $this->support->header($project), 'kind' => $kind, 'phase' => $phase, 'definition' => $definition, 'visit' => $visit,
            'questions' => $this->wording($kind), 'answers' => $this->keyed($answers), 'visitors' => $chosen,
            'visitorOptions' => $this->support->visitorOptions($city, $chosen), 'kobo' => $kobo,
            'koboVisitors' => $kobo ? ($this->support->koboVisitorNames('visits', [(int) $visit->id])[(int) $visit->id] ?? []) : [],
            'canManage' => $canManage && ! $kobo, 'canDelete' => $visit !== null && ! $kobo && $this->support->can($account, $city, 'visits.delete'),
            'version' => $this->version($project, $visits),
            'streets' => ($definition['place'] ?? false) ? DB::table('streets')->where('city_id', $city)->where(fn ($q) => $q->where('is_active', true)->orWhere('id', $project->street_id ?? 0))
                ->orderBy('name_ar')->get(['id', 'name_ar', 'name_en']) : collect(),
        ];
    }

    /** Question wording by slot from `visit_questions` (type and number); closing slot 6 is question 7, question 8 the indicators. */
    private function wording(string $kind): array
    {
        if ($kind === VisitKinds::REHAB) {
            return [];
        }
        $definition = VisitKinds::definition($kind);
        $rows = DB::table('visit_questions')->where('type', $definition['wording'])->get(['number', 'name_ar', 'name_en'])->keyBy(fn ($r) => (int) $r->number);
        $out = [];
        foreach (array_keys($definition['questions']) as $slot) {
            $row = $rows->get(VisitKinds::questionNumber($kind, $slot));
            $out[$slot] = $row === null ? __('visits.question', ['n' => VisitKinds::questionNumber($kind, $slot)]) : (app()->getLocale() === 'en' && $row->name_en ? $row->name_en : $row->name_ar);
        }
        if ($definition['phase'] === 'closing') {
            $row = $rows->get(8);
            $out['indicators'] = $row === null ? __('visits.closing.q8') : (app()->getLocale() === 'en' && $row->name_en ? $row->name_en : $row->name_ar);
        }

        return $out;
    }

    /** @return array<string, array<int, object>> form => slot => answer row */
    private function keyed(Collection $answers): array
    {
        $out = [];
        foreach ($answers as $row) {
            $out[$row->form][(int) $row->question_number] ??= $row;
        }

        return $out;
    }

    // ---------------------------------------------------------------- writing

    /**
     * Records or edits the visit of a kind. $input: date, vehicle, street_id, place_note, indicators, office_comments,
     * q[slot][value|text], office[slot][value|text], visitors (account ids). Returns the visit id.
     */
    public function save(WebAccount $account, int $projectId, string $kind, array $input, string $version): int
    {
        VisitKinds::has($kind) || abort(404);
        $project = $this->support->project($account, $projectId, 'visits.view');
        $this->support->require($account, (int) $project->city_id, 'visits.manage');

        return DB::transaction(function () use ($account, $projectId, $kind, $input, $version) {
            $project = $this->support->lock($projectId);
            $city = (int) $project->city_id;
            $visits = $this->visits($projectId);
            hash_equals($this->version($project, $visits), $version) || $this->support->fail('version', 'stale');
            $visit = $visits->first(fn ($v) => $v->kind === $kind);
            $current = $this->support->current($projectId);
            if ($visit === null) {
                $class = $this->support->classify($project);
                $this->support->funded($current) || $this->support->fail('visit', 'not_funded');
                $phase = VisitKinds::phase($kind);
                if ($class['rehab'] && $kind !== VisitKinds::REHAB) {
                    $this->support->fail('visit', $phase === 'opening' ? 'rehab_opening' : 'rehab_only');
                }
                VisitKinds::kindFor($phase, $class['vehicle'], $class['rehab']) === $kind || $this->support->fail('visit', 'kind');
                $this->ofPhase($visits, $phase) === null || $this->support->fail('visit', $phase === 'opening' ? 'opening_exists' : 'closing_exists');
            } elseif ($this->support->koboVisits([(int) $visit->id]) !== []) {
                $this->support->fail('visit', 'kobo');
            }
            $stored = $visit === null ? [] : $this->keyed($this->answers([(int) $visit->id]));
            $keptVisitors = $visit === null ? [] : $this->visitorRows([(int) $visit->id])->pluck('account_id')->map(fn ($id) => (int) $id)->all();
            $date = $this->support->date($input['date'] ?? null, $projectId);
            $visitors = $this->support->visitors($city, (array) ($input['visitors'] ?? []), VisitKinds::MAX_VISITORS, $keptVisitors);
            [$columns, $answers] = $kind === VisitKinds::REHAB ? $this->rehabPayload($input, $stored) : $this->payload(VisitKinds::definition($kind), $input, $stored);
            $columns['visit_date'] = $date;
            $actor = mb_substr((string) $account->username, 0, 100);
            $now = now();
            $details = [];

            if ($visit === null) {
                $visitId = (int) DB::table('visits')->insertGetId($columns + ['city_id' => $city, 'project_id' => $projectId, 'kind' => $kind, 'type' => null,
                    'created_by_name' => $actor, 'created_at' => $now, 'updated_at' => $now]);
                $details = AccountAuditTrail::changes([], $columns) ?? [];
            } else {
                $visitId = (int) $visit->id;
                $before = collect((array) $visit)->only(array_keys($columns))->map(fn ($v, $k) => $k === 'visit_date' ? Fields::day($v) : $v)->all();
                $details = AccountAuditTrail::changes($before, $columns) ?? [];
            }
            $details = [...$details, ...$this->writeAnswers($city, $visitId, $kind, $answers, $stored)];
            $details = [...$details, ...$this->writeVisitors($city, $visitId, $visitors, $keptVisitors)];
            if (($kind === 'O-op') && $this->place($project, $input, $details)) {
                $project = DB::table('projects')->where('id', $projectId)->first();
            }
            if ($visit === null) {
                $details = [...$details, ...$this->created($account, $project, $kind, $current)];
            } elseif ($details === []) {
                return $visitId;
            } else {
                DB::table('visits')->where('id', $visitId)->update($columns + ['edited_by_name' => $actor, 'updated_at' => $now]);
            }
            $details = [['field' => 'visit', 'before' => null, 'after' => __('visits.kinds.'.$kind, [], 'ar')], ...$details];
            $this->audit->record($account->id, 'visits.saved', 'succeeded', $city, subjectType: 'application_entity', subjectId: $projectId, details: $details);

            return $visitId;
        });
    }

    /** Effects of a new visit: the task, the notices and (closing) the state. Returns audit details. */
    private function created(WebAccount $account, object $project, string $kind, ?object $current): array
    {
        $details = [];
        $letter = VisitKinds::letter($kind);
        if (VisitKinds::phase($kind) === 'opening') {
            if ($this->support->task($project, VisitKinds::OPENING_TASK, true)) {
                $details[] = ['field' => 'task', 'before' => null, 'after' => VisitKinds::OPENING_TASK];
            }
            $seen = $this->support->notices($project, ['Visit Opening-'.$letter, 'Visit Opening-'.$letter.' (Solar)'], 1);
        } else {
            if ($this->support->task($project, VisitKinds::CLOSING_TASK, true)) {
                $details[] = ['field' => 'task', 'before' => null, 'after' => VisitKinds::CLOSING_TASK];
            }
            $seen = $this->support->clearProject($project);
            // Owner decision 3: only a Financed project moves to Finished, and only on this first save.
            if ($current?->state_en === LifecycleService::FINANCED && $this->lifecycle->enterNamed($project, LifecycleService::FINISHED)) {
                $details[] = ['field' => 'state', 'before' => $current->state_ar, 'after' => $this->support->stateName(LifecycleService::FINISHED)];
                $state = DB::table('project_states')->where('name_en', LifecycleService::FINISHED)->first();
                $state !== null && $this->projectNotices->stateChanged($project, $state, $account->id);
            }
        }
        if ($seen > 0) {
            $details[] = ['field' => 'notices_seen', 'before' => null, 'after' => $seen];
        }

        return $details;
    }

    /** A stored choice: blank is unanswered (-1); otherwise it must belong to the domain, except an unchanged stored value. */
    private function choice(mixed $raw, ?array $domain, ?int $stored, string $field, bool $required = false): int
    {
        if ($domain === null || Fields::blank($raw)) {
            $required && $this->support->fail($field, 'choice_required');

            return VisitKinds::UNSET;
        }
        $value = is_int($raw) || is_string($raw) ? trim((string) $raw) : '';
        if ($stored !== null && $value === (string) $stored) {
            return $stored;
        }
        if (preg_match('/\A-?\d+\z/', $value) !== 1 || ! array_key_exists((int) $value, $domain)) {
            $this->support->fail($field, 'choice');
        }

        return (int) $value;
    }

    private function text(mixed $raw, string $field, int $max = 65535): string
    {
        $value = trim(Fields::text($raw));
        mb_strlen($value) <= $max || $this->support->fail($field, 'long');

        return $value;
    }

    /** @return array{0: array<string, mixed>, 1: array<string, array<int, array{text: ?string, value: ?int}>>} visit columns and answers by form/slot */
    private function payload(array $definition, array $input, array $stored): array
    {
        $columns = ['indicators' => $this->text($input['indicators'] ?? '', 'indicators') ?: null];
        if ($definition['vehicle']) {
            $plate = $this->text($input['vehicle'] ?? '', 'vehicle', 30);
            $plate !== '' || $this->support->fail('vehicle', 'vehicle');
            $columns['vehicle_number'] = $plate;
        }
        if ($definition['comments']) {
            $columns['office_comments'] = $this->text($input['office_comments'] ?? '', 'office_comments') ?: null;
        }
        $answers = ['visit' => [], 'office_monitoring' => []];
        foreach ($definition['questions'] as $slot => $domain) {
            $old = $stored['visit'][$slot] ?? null;
            $answers['visit'][$slot] = [
                'text' => $this->text($input['q'][$slot]['text'] ?? '', 'q.'.$slot.'.text') ?: null,
                'value' => $this->choice($input['q'][$slot]['value'] ?? null, $domain, $old?->answer_value === null ? null : (int) $old->answer_value, 'q.'.$slot.'.value'),
            ];
        }
        for ($slot = 1; $slot <= $definition['office']; $slot++) {
            $old = $stored['office_monitoring'][$slot] ?? null;
            $answers['office_monitoring'][$slot] = [
                'text' => $this->text($input['office'][$slot]['text'] ?? '', 'office.'.$slot.'.text') ?: null,
                'value' => $this->choice($input['office'][$slot]['value'] ?? null, VisitKinds::RATING, $old?->answer_value === null ? null : (int) $old->answer_value, 'office.'.$slot.'.value'),
            ];
        }

        return [$columns, $answers];
    }

    /** The rehabilitation closing: every choice is required ("N/A" is a choice), each with a note. */
    private function rehabPayload(array $input, array $stored): array
    {
        $answers = ['rehab' => []];
        $missing = false;
        foreach (VisitKinds::REHAB_QUESTIONS as $slot => $domain) {
            $raw = $input['q'][$slot]['value'] ?? null;
            if (Fields::blank($raw)) {
                $missing = true;

                continue;
            }
            $old = $stored['rehab'][$slot] ?? null;
            $answers['rehab'][$slot] = [
                'text' => $this->text($input['q'][$slot]['text'] ?? '', 'q.'.$slot.'.text') ?: null,
                'value' => $this->choice($raw, $domain, $old?->answer_value === null ? null : (int) $old->answer_value, 'q.'.$slot.'.value'),
            ];
        }
        $missing && $this->support->fail('answers', 'rehab_choices');

        return [[], $answers];
    }

    /**
     * Writes the answers that changed: a slot with neither note nor choice has no row (as the transfer stored them). For rehabilitation,
     * "not applicable" (-1) is a chosen answer and keeps its row. Returns audit details by question number.
     */
    private function writeAnswers(int $city, int $visitId, string $kind, array $answers, array $stored): array
    {
        $details = [];
        $now = now();
        foreach ($answers as $form => $slots) {
            foreach ($slots as $slot => $answer) {
                $old = $stored[$form][$slot] ?? null;
                $empty = $answer['text'] === null && $answer['value'] === VisitKinds::UNSET && $form !== 'rehab';
                $oldValue = $old === null ? VisitKinds::UNSET : (int) ($old->answer_value ?? VisitKinds::UNSET);
                $oldText = $old === null ? null : (($old->answer_text ?? '') === '' ? null : $old->answer_text);
                if (($old !== null || $empty) && $oldValue === $answer['value'] && $oldText === $answer['text']) {
                    continue;
                }
                if ($empty) {
                    $old !== null && DB::table('visit_answers')->where('id', $old->id)->update(['deleted_at' => $now, 'updated_at' => $now]);
                } elseif ($old === null) {
                    DB::table('visit_answers')->insert(['city_id' => $city, 'visit_id' => $visitId, 'form' => $form, 'question_number' => $slot, 'answer_text' => $answer['text'],
                        'answer_value' => $answer['value'], 'created_at' => $now, 'updated_at' => $now]);
                } else {
                    DB::table('visit_answers')->where('id', $old->id)->update(['answer_text' => $answer['text'], 'answer_value' => $answer['value'], 'updated_at' => $now]);
                }
                $details[] = ['field' => $form === 'office_monitoring' ? 'office' : 'answer',
                    'before' => $old === null ? null : $this->describe($kind, $form, $slot, $oldValue, $oldText),
                    'after' => $empty ? null : $this->describe($kind, $form, $slot, $answer['value'], $answer['text'])];
            }
        }

        return $details;
    }

    /** "3) نعم · note" for the audit details, with the option's name. */
    private function describe(string $kind, string $form, int $slot, int $value, ?string $text): string
    {
        $domain = match ($form) {
            'rehab' => VisitKinds::REHAB_QUESTIONS[$slot] ?? [],
            'office_monitoring' => VisitKinds::RATING,
            default => VisitKinds::KINDS[$kind]['questions'][$slot] ?? [],
        };
        $choice = $value === VisitKinds::UNSET && ! isset($domain[-1]) ? null : (isset($domain[$value]) ? __('visits.choice.'.$domain[$value], [], 'ar') : (string) $value);
        $number = $form === 'visit' ? VisitKinds::questionNumber($kind, $slot) : $slot;

        return trim($number.') '.implode(' · ', array_filter([$choice, $text], fn ($v) => $v !== null && $v !== '')));
    }

    /** Replaces the visitors where they changed (removed rows are soft-deleted). Returns audit details. */
    private function writeVisitors(int $city, int $visitId, array $visitors, array $kept): array
    {
        if ($visitors === $kept) {
            return [];
        }
        $now = now();
        $removed = array_values(array_diff($kept, $visitors));
        $added = array_values(array_diff($visitors, $kept));
        if ($removed !== []) {
            DB::table('visit_visitors')->where('visit_id', $visitId)->whereIn('account_id', $removed)->whereNull('deleted_at')->update(['deleted_at' => $now, 'updated_at' => $now]);
        }
        foreach ($added as $id) {
            DB::table('visit_visitors')->insert(['city_id' => $city, 'visit_id' => $visitId, 'account_id' => $id, 'created_at' => $now, 'updated_at' => $now]);
        }
        $names = $this->support->names([...$kept, ...$visitors]);
        $list = fn (array $ids) => $ids === [] ? null : implode('، ', array_map(fn ($id) => $names[$id] ?? '#'.$id, $ids));

        return $removed === [] && $added === [] ? [] : [['field' => 'visitors', 'before' => $list($kept), 'after' => $list($visitors)]];
    }

    /** `Update_MP_Place`: the project's street and detailed address (opening visit of other projects), written only when changed. */
    private function place(object $project, array $input, array &$details): bool
    {
        $street = Fields::blank($input['street_id'] ?? null) ? null : filter_var($input['street_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $street === false && $this->support->fail('street_id', 'street');
        if ($street !== null) {
            DB::table('streets')->where('id', $street)->where('city_id', $project->city_id)->exists() || $this->support->fail('street_id', 'street');
        }
        $address = $this->text($input['place_note'] ?? '', 'place_note', 255);
        $address = $address === '' ? null : $address;
        $payload = [];
        if ($street !== ($project->street_id === null ? null : (int) $project->street_id)) {
            $payload['street_id'] = $street;
            $name = fn ($id) => $id === null ? null : DB::table('streets')->where('id', $id)->value('name_ar');
            $details[] = ['field' => 'street', 'before' => $name($project->street_id), 'after' => $name($street)];
        }
        if ($address !== (($project->address_after_fund ?? '') === '' ? null : $project->address_after_fund)) {
            $payload['address_after_fund'] = $address;
            $details[] = ['field' => 'address_after_fund', 'before' => $project->address_after_fund, 'after' => $address];
        }
        if ($payload === []) {
            return false;
        }
        DB::table('projects')->where('id', $project->id)->update($payload + ['updated_at' => now()]);

        return true;
    }

    /**
     * Owner decision 4: soft-deletes the visit with its answers and visitors, unticks its task and makes its schedule notice unseen again.
     * A Finished state set by a closing visit stays.
     */
    public function delete(WebAccount $account, int $projectId, string $kind, string $version): void
    {
        VisitKinds::has($kind) || abort(404);
        $project = $this->support->project($account, $projectId, 'visits.view');
        $this->support->require($account, (int) $project->city_id, 'visits.delete');
        DB::transaction(function () use ($account, $projectId, $kind, $version) {
            $project = $this->support->lock($projectId);
            $visits = $this->visits($projectId);
            hash_equals($this->version($project, $visits), $version) || $this->support->fail('version', 'stale');
            $visit = $visits->first(fn ($v) => $v->kind === $kind);
            $visit === null && abort(404);
            $this->support->koboVisits([(int) $visit->id]) === [] || $this->support->fail('visit', 'kobo');
            $now = now();
            DB::table('visit_answers')->where('visit_id', $visit->id)->whereNull('deleted_at')->update(['deleted_at' => $now, 'updated_at' => $now]);
            DB::table('visit_visitors')->where('visit_id', $visit->id)->whereNull('deleted_at')->update(['deleted_at' => $now, 'updated_at' => $now]);
            DB::table('visits')->where('id', $visit->id)->update(['deleted_at' => $now, 'updated_at' => $now]);
            $letter = VisitKinds::letter($kind);
            $opening = VisitKinds::phase($kind) === 'opening';
            $details = [['field' => 'visit', 'before' => __('visits.kinds.'.$kind, [], 'ar').' · '.Fields::day($visit->visit_date), 'after' => null]];
            if ($this->support->task($project, $opening ? VisitKinds::OPENING_TASK : VisitKinds::CLOSING_TASK, false)) {
                $details[] = ['field' => 'task', 'before' => $opening ? VisitKinds::OPENING_TASK : VisitKinds::CLOSING_TASK, 'after' => null];
            }
            $unseen = $this->support->notices($project, $opening ? ['Visit Opening-'.$letter, 'Visit Opening-'.$letter.' (Solar)'] : ['Visit Closing-'.$letter], 0);
            if ($unseen > 0) {
                $details[] = ['field' => 'notices_unseen', 'before' => null, 'after' => $unseen];
            }
            $this->audit->record($account->id, 'visits.deleted', 'succeeded', (int) $project->city_id, subjectType: 'application_entity', subjectId: $projectId, details: $details);
        });
    }
}
