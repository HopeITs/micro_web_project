<?php

declare(strict_types=1);

namespace App\Services\Kobo;

use App\Models\WebAccount;
use App\Services\AccountAuditTrail;
use App\Services\Field\VisitKinds;
use App\Services\Field\VisitSupport;
use App\Services\Finance\Fields;
use App\Services\ProjectNumberAllocator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * M4.14b — every Kobo visit form entered, edited and deleted on the web (owner decision 21 Nov 2026), so the desktop sync can be retired.
 *
 * A web Kobo visit is stored exactly as the sync stored one, so the results, schedule, statistics and review pages need no change: a
 * `kobo_submissions` row (answers keyed by the form's field paths in `raw_json`, `kobo_submission_id` empty = written on the web), the
 * visit it stands for (`visits` for an opening form, `me_visits` for a monitoring or final form), the `kobo_monitoring_records` identity of
 * a monitoring visit, the visitors (`kobo_visit_visitors` and the visit's own visitor rows, always city accounts holding `visits.visitor`),
 * and the effects: the visit task is ticked and the nearest schedule reminder settled; agriculture and livestock forms derive their stage
 * and plan the next reminder ({@see KoboSchedule}).
 *
 * Form definitions come from `kobo_forms` (city, type, category, fixed stage, the project-number / stage / vehicle fields) and the question
 * catalogue ({@see KoboCatalog}). Four kinds of field are not asked: the project number and the beneficiary name (taken from the project),
 * and the companion lists (replaced by the visitor accounts). Form errors are keyed `a.{index}` (flat field) and `r.{group}.{row}.{index}`
 * (repeat group) so the page can show them beside the field; indexes are the field's position in {@see spec()}.
 *
 * Capabilities per city: `visits.manage` / `visits.delete` for opening forms, `me_visits.manage` / `me_visits.delete` for monitoring and
 * final forms. Visits the sync wrote (`kobo_submission_id` set) stay read-only until `kobo.web_edit_synced` is switched on at cut-over.
 */
final class KoboVisitService
{
    public const MAX_VISITORS = 4;

    private const BENEFICIARY_FIELD = 'group_mo5jr41/bene_name';

    /** Desktop `IsNetProfit`: the three wordings of the monthly net income question. */
    private const NET_INCOME = ['ما هو متوسط صافي الدخل الشهري خلال الفترة السابقة ؟', 'ما هو متوسط صافي الدخل الشهري خلال ال الفترة السابقة ؟', 'ما هو صافي الدخل الشهري خلال ال 6 اشهر الماضية؟'];

    public function __construct(private VisitSupport $support, private AccountAuditTrail $audit, private KoboSchedule $schedule) {}

    // ---------------------------------------------------------------- the form

    /** The city's active forms; those matching the project first. */
    public function forms(object $project): array
    {
        $suggested = $this->category($project);
        $rows = DB::table('kobo_forms')->where('city_id', $project->city_id)->where('is_active', true)->whereNull('deleted_at')->get(['form_uid', 'form_name', 'visit_type', 'visit_category']);
        $vehicle = $this->support->classify($project)['vehicle'];

        return $rows->filter(fn ($f) => $this->fits($f, $vehicle))->sortBy(fn ($f) => [$f->visit_category === $suggested ? 0 : 1, ['opening' => 0, 'monitoring' => 1, 'final' => 2][$f->visit_type] ?? 3, $f->form_name])
            ->map(fn ($f) => (object) ['uid' => $f->form_uid, 'name' => (string) $f->form_name, 'type' => (string) $f->visit_type, 'category' => (string) $f->visit_category, 'suggested' => $f->visit_category === $suggested])->values()->all();
    }

    /** Vehicle forms belong to vehicle projects and the other forms to the rest (the desktop's V/O visit kinds). */
    private function fits(object $form, bool $vehicle): bool
    {
        return ($form->visit_category === 'vehicle') === $vehicle;
    }

    /** The category a project's visits usually use: vehicle, agriculture, livestock, energy or general. */
    public function category(object $project): string
    {
        if ($this->support->classify($project)['vehicle']) {
            return 'vehicle';
        }
        $type = (string) (DB::table('project_types')->where('id', $project->project_type_id ?? 0)->value('name_ar') ?? '');

        return KoboSchedule::productionType($type) ?? (str_contains($type, 'طاقة شمسية') ? 'energy' : 'general');
    }

    /**
     * The form's questions in order. Each: index, path, label, kind, choices, section, group (repeat group path or null), role
     * (null = asked; 'project', 'person', 'visitor' = filled by the page; 'plate', 'stage' = asked and used).
     *
     * @return array{fields: list<array<string, mixed>>, groups: array<string, int>}
     */
    public function spec(object $form): array
    {
        $fields = [];
        $groups = [];
        foreach (KoboCatalog::forms()[$form->form_uid]['fields'] ?? [] as $index => $field) {
            $path = (string) $field['path'];
            $kind = KoboCatalog::kind($field);
            $role = match (true) {
                $path === $form->project_number_field => 'project',
                $path === self::BENEFICIARY_FIELD => 'person',
                $path === $form->visitor_choice_field, $path === $form->visitor_text_field, str_contains((string) $field['type'], ' Names_') => 'visitor',
                $path === $form->vehicle_number_field => 'plate',
                $path === $form->stage_field => 'stage',
                default => null,
            };
            $group = null;
            if (! empty($field['repeat'])) {
                $group = substr($path, 0, (int) strrpos($path, '/'));
                $groups[$group] ??= count($groups);
            }
            $fields[] = ['index' => $index, 'path' => $path, 'label' => (string) $field['label'], 'kind' => $kind, 'choices' => $field['choices'] ?? [], 'section' => (string) ($field['section'] ?? ''),
                'group' => $group, 'role' => $role];
        }

        return ['fields' => $fields, 'groups' => $groups];
    }

    private function formRow(int $city, string $uid): object
    {
        $form = DB::table('kobo_forms')->where('city_id', $city)->where('form_uid', $uid)->where('is_active', true)->whereNull('deleted_at')->first();
        $form === null && $this->fail('form', 'form');

        return $form;
    }

    // ---------------------------------------------------------------- reading

    /** The project a stored web/Kobo visit belongs to (for the edit route). */
    public function projectOf(WebAccount $account, int $submissionId): int
    {
        $row = DB::table('kobo_submissions as s')->leftJoin('kobo_monitoring_records as r', fn ($j) => $j->on('r.kobo_submission_id', '=', 's.id')->whereNull('r.deleted_at'))
            ->leftJoin('visits as v', fn ($j) => $j->on('v.id', '=', 's.local_record_id')->where('s.local_table', '=', 'visits'))->where('s.id', $submissionId)->whereNull('s.deleted_at')
            ->first(['s.city_id', DB::raw('COALESCE(r.project_id, v.project_id) as project_id')]);
        ($row === null || $row->project_id === null) && abort(404);

        return (int) $row->project_id;
    }

    /**
     * Everything the page needs. $submissionId opens a stored visit; otherwise $formUid picks the form of a new one (null = not chosen yet).
     *
     * @return array<string, mixed>
     */
    public function form(WebAccount $account, int $projectId, ?int $submissionId, ?string $formUid = null): array
    {
        $project = $this->support->project($account, $projectId, 'visits.view');
        $city = (int) $project->city_id;
        $submission = null;
        if ($submissionId !== null) {
            $submission = DB::table('kobo_submissions')->where('id', $submissionId)->where('city_id', $city)->whereNull('deleted_at')->first();
            $submission === null && abort(404);
            $formUid = (string) $submission->form_uid;
        }
        $form = $formUid === null || $formUid === '' ? null : $this->formRow($city, $formUid);
        $raw = $submission === null ? [] : (json_decode((string) $submission->raw_json, true) ?: []);
        $record = $submission === null ? null : DB::table('kobo_monitoring_records')->where('kobo_submission_id', $submission->id)->whereNull('deleted_at')->first();
        $opening = $form !== null && $form->visit_type === 'opening';
        $capability = $opening ? 'visits' : 'me_visits';
        $synced = $submission !== null && $submission->kobo_submission_id !== null;
        $locked = $synced && ! config('kobo.web_edit_synced');
        $visitors = $submission === null ? collect() : DB::table('kobo_visit_visitors')->where('kobo_submission_id', $submission->id)->whereNull('deleted_at')->orderBy('visitor_order')->get(['account_id', 'display_name', 'kobo_name']);

        $stored = $record?->visit_date ?? ($submission !== null && $submission->local_table === 'visits' ? DB::table('visits')->where('id', $submission->local_record_id)->value('visit_date') : null);

        return [
            'date' => Fields::day($stored ?? ($raw['today'] ?? null)),
            'project' => $project, 'header' => $this->support->header($project), 'city' => $city, 'forms' => $this->forms($project), 'form' => $form, 'spec' => $form === null ? null : $this->spec($form),
            'people' => $this->support->people($project), 'submission' => $submission, 'raw' => $raw, 'record' => $record, 'synced' => $synced, 'locked' => $locked,
            'canManage' => $this->support->can($account, $city, $capability.'.manage') && ! $locked,
            'canDelete' => $submission !== null && $this->support->can($account, $city, $capability.'.delete') && ! $locked,
            'visitors' => $visitors, 'visitorOptions' => $this->support->visitorOptions($city, $visitors->pluck('account_id')->filter()->map(fn ($id) => (int) $id)->all()),
            'version' => $this->version($projectId, $submission),
        ];
    }

    private function version(int $projectId, ?object $submission): string
    {
        $count = DB::table('kobo_submissions as s')->join('kobo_monitoring_records as r', 'r.kobo_submission_id', '=', 's.id')->where('r.project_id', $projectId)->whereNull('s.deleted_at')->count();
        $visits = DB::table('visits')->where('project_id', $projectId)->whereNull('deleted_at')->count();

        return sha1(json_encode([$count, $visits, $submission?->id, $submission?->updated_at, $submission?->raw_json_hash]));
    }

    // ---------------------------------------------------------------- writing

    /**
     * Records or edits a Kobo visit. $input: form (uid), person (id, monitoring only), date, a (flat answers by field index), r (repeat rows by
     * group index, each a list of answers by field index), visitors (account ids). Returns the submission id.
     */
    public function save(WebAccount $account, int $projectId, ?int $submissionId, array $input, string $version): int
    {
        $project = $this->support->project($account, $projectId, 'visits.view');
        $city = (int) $project->city_id;
        $existing = $submissionId === null ? null : DB::table('kobo_submissions')->where('id', $submissionId)->where('city_id', $city)->whereNull('deleted_at')->first();
        $submissionId !== null && $existing === null && abort(404);
        $form = $this->formRow($city, $existing !== null ? (string) $existing->form_uid : trim(Fields::text($input['form'] ?? '')));
        $opening = $form->visit_type === 'opening';
        $this->support->require($account, $city, ($opening ? 'visits' : 'me_visits').'.manage');
        if ($existing !== null && $existing->kobo_submission_id !== null && ! config('kobo.web_edit_synced')) {
            $this->fail('visit', 'synced');
        }

        return DB::transaction(function () use ($account, $projectId, $existing, $input, $version, $form, $opening, $city) {
            $project = $this->support->lock($projectId);
            hash_equals($this->version($projectId, $existing), $version) || $this->fail('version', 'stale');
            $class = $this->support->classify($project);
            $class['rehab'] && $this->fail('visit', 'rehab');
            $this->fits($form, $class['vehicle']) || $this->fail('form', 'form_kind');
            $current = $this->support->current($projectId);
            $existing !== null || $this->support->funded($current) || $this->fail('visit', 'not_funded');

            $date = $this->support->date($input['date'] ?? null, $projectId);
            $visitors = $this->support->visitors($city, (array) ($input['visitors'] ?? []), self::MAX_VISITORS,
                $existing === null ? [] : DB::table('kobo_visit_visitors')->where('kobo_submission_id', $existing->id)->whereNull('deleted_at')->pluck('account_id')->filter()->map(fn ($id) => (int) $id)->all());
            $spec = $this->spec($form);
            [$answers, $labelled] = $this->answers($spec, $input);

            $people = $this->support->people($project);
            $person = null;
            $record = $existing === null ? null : DB::table('kobo_monitoring_records')->where('kobo_submission_id', $existing->id)->whereNull('deleted_at')->first();
            if (! $opening) {
                $personId = $record !== null ? (int) $record->person_id : (int) ($input['person'] ?? 0);
                $person = $people->firstWhere('id', $personId);
                $person === null && $this->fail('person', 'person');
            }
            $category = KoboSchedule::finalCategory((string) $form->visit_category, (string) $form->visit_type, (string) (DB::table('project_types')->where('id', $project->project_type_id ?? 0)->value('name_ar') ?? ''));
            $kind = $opening ? ($form->visit_category === 'vehicle' ? 'V-op' : 'O-op') : ($form->visit_category === 'vehicle' ? 'v' : 'o');
            $plate = '';
            foreach ($spec['fields'] as $field) {
                if ($field['role'] === 'plate') {
                    $plate = trim((string) ($answers[$field['path']] ?? ''));
                }
            }
            if ($opening && $kind === 'V-op' && $plate === '') {
                $this->fail('a', 'plate');
            }
            $stage = $opening ? null : $this->stage($form, $spec, $answers, $category, $projectId, (int) $person->id, $date, (int) ($existing->id ?? 0));
            $raw = $this->raw($form, $project, $person, $answers, $date, $existing);
            $actor = mb_substr((string) $account->username, 0, 100);
            $now = now();
            $name = $person->name ?? '';

            // The submission.
            $json = json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $before = $existing === null ? [] : $this->labelled($spec, json_decode((string) $existing->raw_json, true) ?: []);
            if ($existing === null) {
                $submissionId = (int) DB::table('kobo_submissions')->insertGetId(['city_id' => $city, 'root_uuid' => (string) Str::uuid(), 'current_uuid' => null, 'kobo_submission_id' => null, 'form_uid' => $form->form_uid,
                    'form_version' => 'web', 'form_name' => $form->form_name, 'submitted_at' => $now, 'submitted_by' => $actor, 'kobo_status' => null, 'is_deleted_in_kobo' => false,
                    'sync_status' => 'local_inserted', 'local_table' => $opening ? 'visits' : 'me_visits', 'local_record_id' => null, 'raw_json' => $json, 'raw_json_hash' => hash('sha256', $json),
                    'created_at' => $now, 'updated_at' => $now]);
            } else {
                $submissionId = (int) $existing->id;
                DB::table('kobo_submissions')->where('id', $submissionId)->update(['raw_json' => $json, 'raw_json_hash' => hash('sha256', $json), 'sync_status' => 'local_updated', 'error_message' => null, 'updated_at' => $now]);
            }

            // The visit it stands for.
            $details = $this->diff($before, $labelled);
            if ($opening) {
                $this->openingVisit($city, $projectId, $existing, $submissionId, $kind, $date, $plate, $actor, $details);
                $localId = (int) DB::table('kobo_submissions')->where('id', $submissionId)->value('local_record_id');
                $this->writeVisitors('visit_visitors', 'visit_id', $city, $localId, $visitors);
            } else {
                $localId = $this->meVisit($city, $projectId, $record, $submissionId, (int) $person->id, (string) $stage, $kind, $date, $raw, $form, $actor, $details);
                $this->saveRecord($city, $projectId, $record, $submissionId, $person, (string) $stage, $kind, $date, $localId);
                $this->writeVisitors('me_visit_visitors', 'me_visit_id', $city, $localId, $visitors);
            }
            $this->writeKoboVisitors($city, $submissionId, $visitors);
            if ($existing === null || $details !== []) {
                $details = [['field' => 'visit', 'before' => null, 'after' => $this->title($form, $opening, $stage, $name, $date)], ...$details];
                $details = [...$details, ...$this->effects($account, $project, $form, $opening, $kind, $category, $stage, $date, $raw, $person, $submissionId, $existing === null)];
                $this->audit->record($account->id, 'kobo.visit_saved', 'succeeded', $city, subjectType: 'application_entity', subjectId: $projectId, details: $details);
            }

            return $submissionId;
        });
    }

    private function title(object $form, bool $opening, ?int $stage, string $name, string $date): string
    {
        return ($form->form_name ?: $form->form_uid).' · '.($opening ? '' : 'M'.$stage.' · '.$name.' · ').$date;
    }

    /**
     * Cleans the answers. Returns the raw layout (flat paths; repeat groups as lists of repetitions) and a label => display map for the audit.
     *
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    private function answers(array $spec, array $input): array
    {
        $flat = (array) ($input['a'] ?? []);
        $repeats = (array) ($input['r'] ?? []);
        $groupOf = array_flip($spec['groups']);
        $out = [];
        foreach ($spec['fields'] as $field) {
            if ($field['role'] === 'project' || $field['role'] === 'person' || $field['role'] === 'visitor' || $field['group'] !== null) {
                continue;
            }
            $value = $this->clean($field, $flat[$field['index']] ?? null, 'a.'.$field['index']);
            if ($value !== null) {
                $out[$field['path']] = $value;
            }
        }
        foreach ($spec['groups'] as $group => $g) {
            $rows = [];
            foreach (array_values((array) ($repeats[$g] ?? [])) as $i => $row) {
                $repetition = [];
                foreach ($spec['fields'] as $field) {
                    if ($field['group'] !== $group) {
                        continue;
                    }
                    $value = $this->clean($field, ((array) $row)[$field['index']] ?? null, "r.$g.$i.".$field['index']);
                    if ($value !== null) {
                        $repetition[$field['path']] = $value;
                    }
                }
                $repetition === [] || $rows[] = $repetition;
            }
            count($rows) <= 30 || $this->fail('r', 'repeat_max');
            $rows === [] || $out[$group] = $rows;
        }
        unset($groupOf);

        return [$out, $this->labelled($spec, $out)];
    }

    /** @return array<string, array{label: string, text: string}> `q{field index}` => the question and its displayed answer, for the audit comparison */
    private function labelled(array $spec, array $raw): array
    {
        $out = [];
        foreach ($spec['fields'] as $field) {
            if (in_array($field['role'], ['project', 'person', 'visitor'], true)) {
                continue;
            }
            $value = KoboCatalog::lookup($raw, $field['path']);
            if ($value === null || $value === '' || $value === []) {
                continue;
            }
            $out['q'.$field['index']] = ['label' => $field['label'], 'text' => KoboCatalog::display(['type' => $field['kind'], 'choices' => $field['choices']], $value)];
        }

        return $out;
    }

    /**
     * What changed between two answer sets, as audit details (v1.3): the field is `q{index}` (audit field names are plain identifiers) and
     * each side reads "question: answer". Nothing else of the answers is stored.
     *
     * @return list<array<string, mixed>>
     */
    private function diff(array $before, array $after): array
    {
        $out = [];
        foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $key) {
            $old = $before[$key] ?? null;
            $new = $after[$key] ?? null;
            if (($old['text'] ?? null) === ($new['text'] ?? null)) {
                continue;
            }
            $label = (string) (($new ?? $old)['label']);
            $out[] = ['field' => $key, 'before' => $old === null ? null : $label.': '.$old['text'], 'after' => $new === null ? null : $label.': '.$new['text']];
        }

        return AccountAuditTrail::sanitize($out) ?? [];
    }

    private function clean(array $field, mixed $value, string $key): string|null
    {
        $kind = $field['kind'];
        if ($kind === 'select_multiple') {
            $codes = is_array($value) ? $value : (Fields::blank($value) ? [] : (preg_split('/\s+/', trim((string) $value)) ?: []));
            $codes = array_values(array_unique(array_filter(array_map(fn ($c) => trim((string) $c), $codes), fn ($c) => $c !== '')));
            $valid = array_column($field['choices'], 'code');
            foreach ($codes as $code) {
                in_array($code, $valid, true) || $this->fail($key, 'choice');
            }

            return $codes === [] ? null : implode(' ', $codes);
        }
        if (is_array($value) || Fields::blank($value)) {
            return null;
        }
        $text = trim((string) $value);
        switch ($kind) {
            case 'select_one':
                if ($field['choices'] !== []) {
                    in_array($text, array_column($field['choices'], 'code'), true) || $this->fail($key, 'choice');
                }

                return $text;
            case 'integer':
            case 'decimal':
                $text = strtr($text, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9', ',' => '', '٬' => '']);
                preg_match($kind === 'integer' ? '/\A-?\d{1,15}\z/' : '/\A-?\d{1,15}(\.\d{1,4})?\z/', $text) === 1 || $this->fail($key, 'number');

                return $text;
            case 'date':
                Fields::isDate($text) || $this->fail($key, 'date');

                return $text;
            default:
                mb_strlen($text) <= ($kind === 'geopoint' ? 100 : 5000) || $this->fail($key, 'long');

                return $text;
        }
    }

    /** What the page sends to Kobo-style storage: the answers plus the identity the form itself carries. */
    private function raw(object $form, object $project, ?object $person, array $answers, string $date, ?object $existing): array
    {
        $raw = $existing === null ? [] : (json_decode((string) $existing->raw_json, true) ?: []);
        // Keep what the form does not own (Kobo's own metadata of a synced visit); replace every answer the form owns.
        foreach (KoboCatalog::forms()[$form->form_uid]['fields'] ?? [] as $field) {
            unset($raw[$field['path']]);
            if (! empty($field['repeat'])) {
                unset($raw[substr((string) $field['path'], 0, (int) strrpos((string) $field['path'], '/'))]);
            }
        }
        $raw = [...$raw, ...$answers, 'today' => $date];
        $form->project_number_field && $raw[$form->project_number_field] = (string) $project->project_number;
        $person !== null && $raw[self::BENEFICIARY_FIELD] = $person->name;

        return $raw;
    }

    // ---------------------------------------------------------------- the stage

    /** The visit's stage number: fixed by the form, answered in its stage field, or (agriculture, livestock) derived from the person's previous visit. */
    private function stage(object $form, array $spec, array $answers, string $category, int $projectId, int $personId, string $date, int $submissionId): int
    {
        if ($form->visit_type === 'final') {
            return 4;
        }
        if (KoboSchedule::production($category)) {
            return $this->productionStage($category, $answers, $projectId, $personId, $date, $submissionId, (string) $form->visit_category);
        }
        if ($form->fixed_stage !== null) {
            return (int) $form->fixed_stage;
        }
        $form->stage_field !== null || $this->fail('a', 'stage_missing');
        $value = strtoupper(trim((string) ($answers[$form->stage_field] ?? '')));
        for ($stage = 1; $stage <= 4; $stage++) {
            if ($value === 'M'.$stage || $value === 'M&E_'.$stage) {
                return $stage;
            }
        }
        $index = collect($spec['fields'])->firstWhere('path', $form->stage_field)['index'] ?? 0;
        $this->fail('a.'.$index, 'stage');
    }

    private function productionStage(string $category, array $answers, int $projectId, int $personId, string $date, int $submissionId, string $formCategory): int
    {
        $earlier = DB::table('kobo_monitoring_records as r')->join('kobo_submissions as s', 's.id', '=', 'r.kobo_submission_id')->join('kobo_forms as f', fn ($j) => $j->on('f.form_uid', '=', 's.form_uid')->on('f.city_id', '=', 's.city_id'))
            ->where('r.project_id', $projectId)->where('r.person_id', $personId)->where('s.id', '<>', $submissionId)->where('r.visit_date', '<', $date)->whereNull('r.deleted_at')->whereNull('s.deleted_at')
            ->where('s.sync_status', '<>', 'review_replaced')->where(fn ($q) => $q->where('f.visit_category', $category)->orWhere(fn ($w) => $w->where('f.visit_type', 'final')->where('f.visit_category', 'general')))
            ->orderByDesc('r.visit_date')->orderByDesc('s.id')->first(['r.visit_number', 's.raw_json', 's.sync_status']);
        if ($earlier === null) {
            KoboSchedule::started($answers, $category) !== null || $this->fail('a', 'production_started');

            return 1;
        }
        in_array($earlier->sync_status, ['local_inserted', 'local_updated'], true) || $this->fail('visit', 'previous_unconfirmed');
        ctype_digit((string) $earlier->visit_number) || $this->fail('visit', 'previous_number');
        $next = KoboSchedule::next((int) $earlier->visit_number, KoboSchedule::started(json_decode((string) $earlier->raw_json, true) ?: [], $category));
        $next === 0 && $this->fail('visit', 'production_next');
        $next === 4 && $this->fail('visit', 'production_final');
        unset($formCategory);

        return $next;
    }

    // ---------------------------------------------------------------- the visit rows

    private function openingVisit(int $city, int $projectId, ?object $existing, int $submissionId, string $kind, string $date, string $plate, string $actor, array &$details): void
    {
        $now = now();
        $localId = $existing === null ? null : (int) $existing->local_record_id;
        $others = DB::table('visits')->where('project_id', $projectId)->where('kind', $kind)->whereNull('deleted_at')->when($localId, fn ($q) => $q->where('id', '<>', $localId))->exists();
        $others && $this->fail('visit', 'opening_exists');
        $columns = ['visit_date' => $date, 'vehicle_number' => $plate === '' ? null : $plate];
        if ($localId === null || ! DB::table('visits')->where('id', $localId)->whereNull('deleted_at')->exists()) {
            $localId = (int) DB::table('visits')->insertGetId($columns + ['city_id' => $city, 'project_id' => $projectId, 'kind' => $kind, 'type' => null, 'created_by_name' => $actor, 'created_at' => $now, 'updated_at' => $now]);
            DB::table('kobo_submissions')->where('id', $submissionId)->update(['local_record_id' => $localId]);

            return;
        }
        $before = DB::table('visits')->where('id', $localId)->first(['visit_date', 'vehicle_number']);
        $changes = AccountAuditTrail::changes(['visit_date' => Fields::day($before->visit_date), 'vehicle_number' => $before->vehicle_number], $columns) ?? [];
        $details = [...$details, ...$changes];
        DB::table('visits')->where('id', $localId)->update($columns + ['edited_by_name' => $actor, 'updated_at' => $now]);
    }

    private function meVisit(int $city, int $projectId, ?object $record, int $submissionId, int $personId, string $number, string $kind, string $date, array $raw, object $form, string $actor, array &$details): int
    {
        $now = now();
        $localId = $record === null || $record->me_visit_id === null ? null : (int) $record->me_visit_id;
        DB::table('me_visits')->where('project_id', $projectId)->where('person_id', $personId)->where('kind', $kind)->where('visit_number', $number)->whereNull('deleted_at')
            ->when($localId, fn ($q) => $q->where('id', '<>', $localId))->exists() && $this->fail('visit', 'number_used', ['number' => $number]);
        $columns = ['visit_number' => $number, 'visit_date' => $date, 'profit' => $this->income($form, $raw)];
        if ($localId === null || ! DB::table('me_visits')->where('id', $localId)->whereNull('deleted_at')->exists()) {
            $localId = (int) DB::table('me_visits')->insertGetId($columns + ['city_id' => $city, 'person_id' => $personId, 'project_id' => $projectId, 'kind' => $kind, 'type' => null, 'result' => 0,
                'created_by_name' => $actor, 'created_at' => $now, 'updated_at' => $now]);
            DB::table('kobo_submissions')->where('id', $submissionId)->update(['local_record_id' => $localId]);

            return $localId;
        }
        $before = DB::table('me_visits')->where('id', $localId)->first(['visit_number', 'visit_date', 'profit']);
        $details = [...$details, ...(AccountAuditTrail::changes(['visit_number' => (string) $before->visit_number, 'visit_date' => Fields::day($before->visit_date), 'profit' => $before->profit === null ? null : (float) $before->profit],
            ['visit_number' => $number, 'visit_date' => $date, 'profit' => $columns['profit']]) ?? [])];
        DB::table('me_visits')->where('id', $localId)->update($columns + ['edited_by_name' => $actor, 'updated_at' => $now]);

        return $localId;
    }

    /** The monthly net income answer (desktop `IsNetProfit`), 0 when the form has none. */
    private function income(object $form, array $raw): float
    {
        $wanted = array_map([KoboCatalog::class, 'normalize'], self::NET_INCOME);
        foreach (KoboCatalog::forms()[$form->form_uid]['fields'] ?? [] as $field) {
            if (in_array(KoboCatalog::normalize((string) $field['label']), $wanted, true) && in_array(KoboCatalog::kind($field), ['integer', 'decimal'], true)) {
                $value = $raw[$field['path']] ?? null;
                if (is_numeric($value)) {
                    return (float) $value;
                }
            }
        }

        return 0.0;
    }

    private function saveRecord(int $city, int $projectId, ?object $record, int $submissionId, object $person, string $number, string $kind, string $date, int $localId): void
    {
        $columns = ['project_id' => $projectId, 'person_id' => $person->id, 'person_role' => $person->role !== '' ? $person->role : null, 'visit_number' => $number, 'visit_kind' => $kind, 'visit_date' => $date,
            'me_visit_id' => $localId, 'updated_at' => now()];
        if ($record === null) {
            DB::table('kobo_monitoring_records')->insert($columns + ['city_id' => $city, 'kobo_submission_id' => $submissionId, 'created_at' => now()]);
        } else {
            DB::table('kobo_monitoring_records')->where('id', $record->id)->update($columns);
        }
    }

    /** The visit's own visitor rows follow the chosen accounts: removed ones soft-deleted, new ones added. */
    private function writeVisitors(string $table, string $column, int $city, int $visitId, array $accounts): void
    {
        $now = now();
        $stored = DB::table($table)->where($column, $visitId)->whereNull('deleted_at')->get(['id', 'account_id']);
        $remove = $stored->filter(fn ($r) => ! in_array((int) $r->account_id, $accounts, true))->pluck('id')->all();
        $remove === [] || DB::table($table)->whereIn('id', $remove)->update(['deleted_at' => $now, 'updated_at' => $now]);
        foreach (array_diff($accounts, $stored->pluck('account_id')->map(fn ($id) => (int) $id)->all()) as $account) {
            DB::table($table)->insert(['city_id' => $city, $column => $visitId, 'account_id' => $account, 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    /** The submission's visitor names in order (as the sync kept them), linked to the accounts. */
    private function writeKoboVisitors(int $city, int $submissionId, array $accounts): void
    {
        $names = $this->support->names($accounts);
        $now = now();
        DB::table('kobo_visit_visitors')->where('kobo_submission_id', $submissionId)->whereNull('deleted_at')->update(['deleted_at' => $now, 'updated_at' => $now]);
        foreach (array_values($accounts) as $order => $id) {
            DB::table('kobo_visit_visitors')->insert(['city_id' => $city, 'kobo_submission_id' => $submissionId, 'visitor_order' => $order + 1, 'kobo_name' => $names[$id] ?? null, 'display_name' => $names[$id] ?? null,
                'account_id' => $id, 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    // ---------------------------------------------------------------- effects

    /** The task, the reminders and the production schedule. Returns audit details. */
    private function effects(WebAccount $account, object $project, object $form, bool $opening, string $kind, string $category, ?int $stage, string $date, array $raw, ?object $person, int $submissionId, bool $created): array
    {
        $details = [];
        $letter = str_starts_with($kind, 'V') || $kind === 'v' ? 'V' : 'O';
        $solar = $category === 'energy' ? ' (Solar)' : '';
        if ($opening) {
            $task = VisitKinds::OPENING_TASK;
            $bodies = ['Visit Opening-'.$letter.$solar];
        } else {
            $task = VisitKinds::ME_TASKS[$stage] ?? null;
            $bodies = ['Visit M'.$stage.'-'.$letter.$solar, ...($stage === 4 ? ['Visit MFinal-'.$letter.$solar] : [])];
        }
        if ($task !== null && $this->support->task($project, $task, true)) {
            $details[] = ['field' => 'task', 'before' => null, 'after' => $task];
        }
        $seen = $this->schedule->complete($project, $bodies, $date);
        if ($seen > 0) {
            $details[] = ['field' => 'notices_seen', 'before' => null, 'after' => $seen];
        }
        if (KoboSchedule::production($category)) {
            if ($opening) {
                $this->schedule->opening($project, $date, $account->id);
            } else {
                $this->schedule->after($project, (int) $person->id, (int) $stage, $date, $category, $raw, (string) $person->name, $account->id, $submissionId);
            }
        }
        unset($created);

        return $details;
    }

    // ---------------------------------------------------------------- deleting

    /** Soft-deletes the Kobo visit with the visit it stands for; unticks the task when no other visit of that stage remains; its reminder is unseen again. */
    public function delete(WebAccount $account, int $projectId, int $submissionId, string $version): void
    {
        $project = $this->support->project($account, $projectId, 'visits.view');
        $city = (int) $project->city_id;
        $submission = DB::table('kobo_submissions')->where('id', $submissionId)->where('city_id', $city)->whereNull('deleted_at')->first();
        $submission === null && abort(404);
        $opening = $submission->local_table === 'visits';
        $this->support->require($account, $city, ($opening ? 'visits' : 'me_visits').'.delete');
        if ($submission->kobo_submission_id !== null && ! config('kobo.web_edit_synced')) {
            $this->fail('visit', 'synced');
        }
        DB::transaction(function () use ($account, $projectId, $submission, $version, $opening) {
            $project = $this->support->lock($projectId);
            hash_equals($this->version($projectId, $submission), $version) || $this->fail('version', 'stale');
            $now = now();
            $form = DB::table('kobo_forms')->where('city_id', $project->city_id)->where('form_uid', $submission->form_uid)->first();
            $details = [];
            $localId = $submission->local_record_id === null ? null : (int) $submission->local_record_id;
            $record = DB::table('kobo_monitoring_records')->where('kobo_submission_id', $submission->id)->whereNull('deleted_at')->first();
            if ($opening && $localId !== null) {
                $visit = DB::table('visits')->where('id', $localId)->first();
                DB::table('visit_visitors')->where('visit_id', $localId)->whereNull('deleted_at')->update(['deleted_at' => $now, 'updated_at' => $now]);
                DB::table('visits')->where('id', $localId)->update(['deleted_at' => $now, 'updated_at' => $now]);
                $letter = $visit !== null && $visit->kind === 'V-op' ? 'V' : 'O';
                $details[] = ['field' => 'visit', 'before' => ($submission->form_name ?: $submission->form_uid).' · '.Fields::day($visit->visit_date ?? null), 'after' => null];
                $remaining = DB::table('visits')->where('project_id', $projectId)->where('kind', $visit->kind ?? 'O-op')->whereNull('deleted_at')->exists();
                if (! $remaining && $this->support->task($project, VisitKinds::OPENING_TASK, false)) {
                    $details[] = ['field' => 'task', 'before' => VisitKinds::OPENING_TASK, 'after' => null];
                }
                $unseen = $remaining ? 0 : $this->support->notices($project, ['Visit Opening-'.$letter, 'Visit Opening-'.$letter.' (Solar)'], 0);
            } else {
                $number = (string) ($record->visit_number ?? '');
                $kind = (string) ($record->visit_kind ?? 'o');
                $personId = (int) ($record->person_id ?? 0);
                if ($localId !== null) {
                    DB::table('me_visit_visitors')->where('me_visit_id', $localId)->whereNull('deleted_at')->update(['deleted_at' => $now, 'updated_at' => $now]);
                    DB::table('me_visit_answers')->where('me_visit_id', $localId)->whereNull('deleted_at')->update(['deleted_at' => $now, 'updated_at' => $now]);
                    DB::table('me_visits')->where('id', $localId)->update(['deleted_at' => $now, 'updated_at' => $now]);
                }
                $person = $this->support->people($project)->firstWhere('id', $personId);
                $details[] = ['field' => 'visit', 'before' => 'M'.$number.' · '.($person->name ?? '#'.$personId).' · '.Fields::day($record->visit_date ?? null), 'after' => null];
                $task = VisitKinds::ME_TASKS[(int) $number] ?? null;
                $remaining = DB::table('me_visits')->where('project_id', $projectId)->where('visit_number', $number)->whereNull('deleted_at')->exists();
                if ($task !== null && ! $remaining && $this->support->task($project, $task, false)) {
                    $details[] = ['field' => 'task', 'before' => $task, 'after' => null];
                }
                $solar = $form !== null && $form->visit_category === 'energy' ? ' (Solar)' : '';
                $letter = strtoupper($kind);
                $bodies = ['Visit M'.$number.'-'.$letter.$solar, ...($number === '4' ? ['Visit MFinal-'.$letter.$solar] : [])];
                $unseen = $remaining ? 0 : $this->support->notices($project, $bodies, 0, $person->name ?? null);
                DB::table('kobo_monitoring_records')->where('kobo_submission_id', $submission->id)->whereNull('deleted_at')->update(['deleted_at' => $now, 'updated_at' => $now]);
            }
            if ($unseen > 0) {
                $details[] = ['field' => 'notices_unseen', 'before' => null, 'after' => $unseen];
            }
            DB::table('kobo_visit_visitors')->where('kobo_submission_id', $submission->id)->whereNull('deleted_at')->update(['deleted_at' => $now, 'updated_at' => $now]);
            DB::table('kobo_submissions')->where('id', $submission->id)->update(['deleted_at' => $now, 'updated_at' => $now]);
            $this->audit->record($account->id, 'kobo.visit_deleted', 'succeeded', (int) $project->city_id, subjectType: 'application_entity', subjectId: $projectId, details: $details);
        });
    }

    private function fail(string $field, string $key, array $replace = []): never
    {
        throw ValidationException::withMessages([$field => __('kobo.errors.'.$key, $replace)]);
    }

    /** "CITY-0001" of a project, for the page header. */
    public function number(object $project): string
    {
        return ProjectNumberAllocator::display((string) DB::table('cities')->where('id', $project->city_id)->value('code'), (int) $project->project_number);
    }
}
