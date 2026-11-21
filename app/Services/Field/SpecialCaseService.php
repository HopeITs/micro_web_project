<?php

declare(strict_types=1);

namespace App\Services\Field;

use App\Models\WebAccount;
use App\Services\AccountAuditTrail;
use App\Services\Finance\Fields;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Special cases of a project (M4.13, desktop `SpecialCase_Form`, `Classes/SpecialCase.cs`) on the unified schema: `special_cases`
 * with its type (`special_case_types`) and recorder (`account_id` → `web_accounts`). A case is a beneficiary's contact with the office;
 * it has no effect on the project, its loan, tasks or notices.
 *
 * Owner decisions (21 Nov 2026): reading needs `applications.view` + `special_cases.view` in the project's city (another city: 404),
 * held by the administrator, data entry and monitoring (1); writing `special_cases.manage` and deleting `special_cases.delete`, held by
 * the administrator and data entry (1, 4); any project of the city in any state, the date required and not in the future, not compared
 * with the loan (2); the recorder defaults to the signed-in account and may be any enabled account of the city that can write cases (3);
 * deletion is permanent after a confirmation, as on the desktop, and audited with details (4).
 */
final class SpecialCaseService
{
    public const REASON_MAX = 1000;

    public const TEXT_MAX = 65535;

    public function __construct(private VisitSupport $support, private AccountAuditTrail $audit) {}

    // ---------------------------------------------------------------- reading

    /** Read model of the project's special cases page; `$opened` audits the page visit once. */
    public function page(WebAccount $account, int $projectId, bool $opened = false): array
    {
        $project = $this->support->project($account, $projectId, 'special_cases.view');
        $city = (int) $project->city_id;
        $rows = $this->rows($projectId);
        if ($opened) {
            $this->audit->record($account->id, 'special_cases.view', 'succeeded', $city, subjectType: 'application_entity', subjectId: $projectId);
        }
        $types = $this->types($rows->pluck('special_case_type_id')->filter()->all());
        $recorders = $this->recorders($city, $rows->pluck('account_id')->filter()->all());
        $names = $this->support->names($rows->pluck('account_id')->filter()->all());
        $typeNames = $types->pluck('name', 'id')->all();
        $canManage = $this->support->can($account, $city, 'special_cases.manage');

        return [
            'project' => $project, 'header' => $this->support->header($project), 'current' => $this->support->current($projectId),
            'cases' => $rows->map(fn ($r) => (object) [
                'id' => (int) $r->id, 'date' => Fields::day($r->case_date), 'type_id' => $r->special_case_type_id === null ? null : (int) $r->special_case_type_id,
                'type' => $typeNames[(int) $r->special_case_type_id] ?? '', 'recorder_id' => $r->account_id === null ? null : (int) $r->account_id,
                'recorder' => $r->account_id === null ? '' : ($names[(int) $r->account_id] ?? '#'.$r->account_id),
                'reason' => (string) ($r->reason ?? ''), 'description' => (string) ($r->description ?? ''), 'action' => (string) ($r->action ?? ''),
            ])->values(),
            'types' => $types, 'recorders' => $recorders, 'canManage' => $canManage, 'canDelete' => $this->support->can($account, $city, 'special_cases.delete'),
            'version' => $this->version($rows),
            'defaults' => ['date' => VisitSupport::today(), 'type' => (string) ($types->firstWhere('active', true)->id ?? ''),
                'recorder' => $recorders->contains('id', (int) $account->id) ? (string) $account->id : ''],
        ];
    }

    /** The project's cases (not deleted), newest first as the desktop Search lists them. */
    private function rows(int $projectId): Collection
    {
        return DB::table('special_cases')->where('project_id', $projectId)->whereNull('deleted_at')->orderByDesc('case_date')->orderByDesc('id')->get();
    }

    /** A fingerprint of what the page showed: the project's cases. */
    private function version(Collection $rows): string
    {
        $plain = $rows->sortBy('id')->map(fn ($r) => collect((array) $r)->map(fn ($v) => $v === null ? null : (string) $v)->all())->values()->all();

        return hash('sha256', json_encode($plain, JSON_THROW_ON_ERROR));
    }

    /** Active case types (default first: هاتفية), plus the stored types of the cases shown even when hidden since. */
    public function types(array $kept = []): Collection
    {
        $en = app()->getLocale() === 'en';

        return DB::table('special_case_types')->where(fn ($q) => $q->where('is_active', true)->orWhereIn('id', $kept ?: [0]))->orderBy('sort_order')->orderBy('id')
            ->get(['id', 'name_ar', 'name_en', 'is_active'])
            ->map(fn ($t) => (object) ['id' => (int) $t->id, 'name' => $en && $t->name_en ? (string) $t->name_en : (string) $t->name_ar, 'name_ar' => (string) $t->name_ar, 'active' => (bool) $t->is_active]);
    }

    /** Owner decision 3: the enabled accounts of the city that can write special cases, plus the stored recorders (placeholders included). */
    public function recorders(int $city, array $kept = []): Collection
    {
        $ids = DB::table('account_city_capabilities as g')->join('web_accounts as a', 'a.id', '=', 'g.account_id')
            ->where('g.city_id', $city)->where('g.capability', 'special_cases.manage')->whereNull('g.revoked_at')->whereNull('a.disabled_at')->pluck('a.id')
            ->merge($kept)->map(fn ($id) => (int) $id)->unique()->all();

        return DB::table('web_accounts')->whereIn('id', $ids ?: [0])->get(['id', 'username'])
            ->map(fn ($a) => (object) ['id' => (int) $a->id, 'name' => (string) $a->username])->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values();
    }

    // ---------------------------------------------------------------- writing

    private function fail(string $field, string $key, array $replace = []): never
    {
        throw ValidationException::withMessages([$field => __('special_cases.errors.'.$key, $replace)]);
    }

    /**
     * Records a new case (`$caseId` null) or edits one of the project. $input: date, recorder (account id), type (type id), reason,
     * description, action. Only the changed fields are written; the project of a case never changes. Returns the case id.
     */
    public function save(WebAccount $account, int $projectId, ?int $caseId, array $input, string $version): int
    {
        $project = $this->support->project($account, $projectId, 'special_cases.view');
        $this->support->require($account, (int) $project->city_id, 'special_cases.manage');

        return DB::transaction(function () use ($account, $projectId, $caseId, $input, $version) {
            $project = $this->support->lock($projectId);
            $city = (int) $project->city_id;
            $rows = $this->rows($projectId);
            // A resubmitted form carries the version from before its first save, so it never makes a second case.
            hash_equals($this->version($rows), $version) || $this->fail('version', 'stale');
            $case = null;
            if ($caseId !== null) {
                $case = $rows->firstWhere('id', $caseId) ?? abort(404);
            }
            $columns = $this->payload($city, $input, $case);
            $now = now();
            $number = $this->support->header($project)['number'];
            if ($case === null) {
                $caseId = (int) DB::table('special_cases')->insertGetId($columns + ['city_id' => $city, 'project_id' => $projectId, 'created_at' => $now, 'updated_at' => $now]);
                $details = $this->describe([], $columns);
            } else {
                $before = ['case_date' => Fields::day($case->case_date), 'account_id' => $case->account_id === null ? null : (int) $case->account_id,
                    'special_case_type_id' => $case->special_case_type_id === null ? null : (int) $case->special_case_type_id,
                    'reason' => $case->reason, 'description' => $case->description, 'action' => $case->action];
                $changed = array_filter($columns, fn ($value, $key) => $this->differs($before[$key] ?? null, $value), ARRAY_FILTER_USE_BOTH);
                if ($changed === []) {
                    return $caseId;
                }
                DB::table('special_cases')->where('id', $caseId)->update($changed + ['updated_at' => $now]);
                $details = $this->describe($before, $changed);
            }
            $this->audit->record($account->id, 'special_cases.saved', 'succeeded', $city, subjectType: 'application_entity', subjectId: $projectId,
                details: [['field' => 'case', 'before' => null, 'after' => $number.' · #'.$caseId], ...$details]);

            return $caseId;
        });
    }

    private function differs(mixed $before, mixed $after): bool
    {
        $plain = fn ($v) => $v === null || $v === '' ? null : (string) $v;

        return $plain($before) !== $plain($after);
    }

    /** The checked columns, in the desktop's order of checks: date, recorder, type, reason (required), then description and action. */
    private function payload(int $city, array $input, ?object $case): array
    {
        $date = trim(Fields::text($input['date'] ?? null));
        $date !== '' || $this->fail('date', 'date_required');
        Fields::isDate($date) || $this->fail('date', 'date');
        $date <= VisitSupport::today() || $this->fail('date', 'date_future');

        $recorder = $this->id($input['recorder'] ?? null);
        $recorder !== null || $this->fail('recorder', 'recorder');
        $keptRecorder = $case?->account_id === null ? [] : [(int) $case->account_id];
        $this->recorders($city, $keptRecorder)->contains('id', $recorder) || $this->fail('recorder', 'recorder');

        $type = $this->id($input['type'] ?? null);
        $type !== null || $this->fail('type', 'type');
        $keptType = $case?->special_case_type_id === null ? [] : [(int) $case->special_case_type_id];
        $this->types($keptType)->contains('id', $type) || $this->fail('type', 'type');

        // The reason is a single line, as the desktop's text box.
        $reason = trim(preg_replace('/\s*[\r\n]+\s*/u', ' ', Fields::text($input['reason'] ?? null)) ?? '');
        $reason !== '' || $this->fail('reason', 'reason');
        mb_strlen($reason) <= self::REASON_MAX || $this->fail('reason', 'reason_long', ['max' => self::REASON_MAX]);

        $text = function (string $field) use ($input): ?string {
            $value = trim(str_replace("\r\n", "\n", Fields::text($input[$field] ?? null)));
            mb_strlen($value) <= self::TEXT_MAX || $this->fail($field, 'long');

            return $value === '' ? null : $value;
        };

        return ['case_date' => $date, 'account_id' => $recorder, 'special_case_type_id' => $type, 'reason' => $reason,
            'description' => $text('description'), 'action' => $text('action')];
    }

    private function id(mixed $value): ?int
    {
        if (Fields::blank($value) || ! (is_int($value) || is_string($value))) {
            return null;
        }
        $id = filter_var(trim((string) $value), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $id === false ? null : $id;
    }

    /** Audit details in readable form: the type by name and the recorder by username (v1.3). */
    private function describe(array $before, array $after): array
    {
        $typeName = fn ($id) => $id === null ? null : DB::table('special_case_types')->where('id', $id)->value('name_ar');
        $accounts = $this->support->names(array_filter([$before['account_id'] ?? null, $after['account_id'] ?? null]));
        $readable = function (array $row) use ($typeName, $accounts): array {
            $out = [];
            foreach ($row as $key => $value) {
                $out[match ($key) {
                    'case_date' => 'case_date', 'account_id' => 'recorder', 'special_case_type_id' => 'case_type', default => $key
                }] = match ($key) {
                    'account_id' => $value === null ? null : ($accounts[(int) $value] ?? '#'.$value),
                    'special_case_type_id' => $typeName($value),
                    default => $value,
                };
            }

            return $out;
        };

        return AccountAuditTrail::changes($readable(array_intersect_key($before, $after)), $readable($after)) ?? [];
    }

    /** Owner decision 4: permanently deletes one case of the project (desktop hard delete), audited with what it held. */
    public function delete(WebAccount $account, int $projectId, int $caseId, string $version): void
    {
        $project = $this->support->project($account, $projectId, 'special_cases.view');
        $this->support->require($account, (int) $project->city_id, 'special_cases.delete');
        DB::transaction(function () use ($account, $projectId, $caseId, $version) {
            $project = $this->support->lock($projectId);
            $rows = $this->rows($projectId);
            hash_equals($this->version($rows), $version) || $this->fail('version', 'stale');
            $case = $rows->firstWhere('id', $caseId) ?? $this->fail('case', 'none');
            DB::table('special_cases')->where('id', $caseId)->where('project_id', $projectId)->delete();
            $held = ['case_date' => Fields::day($case->case_date), 'account_id' => $case->account_id, 'special_case_type_id' => $case->special_case_type_id,
                'reason' => $case->reason, 'description' => $case->description, 'action' => $case->action];
            $details = array_map(fn ($d) => ['field' => $d['field'], 'before' => $d['after'], 'after' => null], $this->describe([], $held));
            $this->audit->record($account->id, 'special_cases.deleted', 'succeeded', (int) $project->city_id, subjectType: 'application_entity', subjectId: $projectId,
                details: [['field' => 'case', 'before' => $this->support->header($project)['number'].' · #'.$caseId, 'after' => null], ...$details]);
        });
    }
}
