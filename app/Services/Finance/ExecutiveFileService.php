<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Models\WebAccount;
use App\Services\AccessRoles;
use App\Services\AccountAccess;
use App\Services\AccountAuditTrail;
use App\Services\Lifecycle\LifecycleService;
use App\Services\Staff\ProjectNotices;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The executive (legal enforcement) file of one project (M4.10, desktop ExecutiveFiles_Form) on the unified schema: `executive_files`,
 * `web_notices`, `projects`. A project has at most one file (no loan needed, as in the desktop).
 *
 * Reading needs `applications.view` and `executive_files.view` in the project's city (another city: 404), writing and deleting
 * `executive_files.manage` (administrator and legal, owner decision of 21 Nov 2026). Saving a new file sends one notice per legal account
 * of the city, dated the expiry date; an edit moves those notices to the new expiry; deleting the file (soft, audited with details) removes
 * them. Every write runs under the project row lock and carries the version of what the page showed.
 */
final class ExecutiveFileService
{
    public const MAX_MONTHS = 100;

    public function __construct(private AccountAccess $access, private AccountAuditTrail $audit, private LifecycleService $lifecycle, private ProjectNotices $projectNotices) {}

    // ---------------------------------------------------------------- access

    /** The project, readable with applications.view + executive_files.view in its city (another city: 404). */
    public function project(WebAccount $account, int $projectId): object
    {
        $project = $this->lifecycle->project($account, $projectId);
        if (! $this->access->check($account, (int) $project->city_id, 'executive_files.view')) {
            $this->audit->record($account->id, 'access.denied', 'denied', (int) $project->city_id);
            abort(403);
        }

        return $project;
    }

    public function canManage(WebAccount $account, int $city): bool
    {
        return $this->access->check($account, $city, 'executive_files.manage');
    }

    /** Refuses (and records the refusal) before any transaction, so the audit row is not rolled back. */
    private function writable(WebAccount $account, int $projectId): object
    {
        $project = $this->project($account, $projectId);
        if (! $this->canManage($account, (int) $project->city_id)) {
            $this->audit->record($account->id, 'access.denied', 'denied', (int) $project->city_id);
            abort(403);
        }

        return $project;
    }

    // ---------------------------------------------------------------- reading

    /** The page itself was opened (audited once per visit, not on every Livewire round trip). */
    public function opened(WebAccount $account, int $projectId): object
    {
        $project = $this->project($account, $projectId);
        $this->audit->record($account->id, 'executive_files.view', 'succeeded', (int) $project->city_id, subjectType: 'application_entity', subjectId: $projectId);

        return $project;
    }

    public function file(int $projectId): ?object
    {
        $file = DB::table('executive_files')->where('project_id', $projectId)->whereNull('deleted_at')->orderBy('id')->first();
        if ($file === null) {
            return null;
        }
        foreach (['begin_on', 'current_on', 'impound_on'] as $column) {
            $file->{$column} = Fields::day($file->{$column});
        }

        return $file;
    }

    /** A fingerprint of what the page showed: the file (or its absence). */
    public function version(?object $file): string
    {
        return hash('sha256', json_encode($file === null ? null : [$file->id, $file->file_number, $file->begin_on, $file->current_on, $file->impound_on, $file->impound_type, $file->months], JSON_THROW_ON_ERROR));
    }

    /** Current date plus months with .NET `AddMonths` semantics: the day is clamped to the end of the target month. */
    public static function expiry(?string $current, mixed $months): ?string
    {
        $day = Fields::day($current);
        if (! Fields::isDate($day) || ! is_numeric($months)) {
            return null;
        }

        return CarbonImmutable::createFromFormat('!Y-m-d', $day)->addMonthsNoOverflow((int) $months)->toDateString();
    }

    /** Whether the city has an enabled legal account to send the expiry notice to. */
    public function hasLegalAccount(int $city): bool
    {
        return app(AccessRoles::class)->holders($city, AccessRoles::RECIPIENTS['executive_file'])->isNotEmpty();
    }

    /** Read model of the page. */
    public function panel(WebAccount $account, int $projectId): array
    {
        $project = $this->project($account, $projectId);
        $city = (int) $project->city_id;
        $file = $this->file($projectId);
        $current = $this->lifecycle->currentState($projectId);

        return [
            'project' => $project, 'file' => $file, 'version' => $this->version($file), 'canManage' => $this->canManage($account, $city),
            'beneficiary' => $this->projectNotices->beneficiary($project), 'state' => $current,
            'noLegal' => ! $this->hasLegalAccount($city), 'expires' => $file === null ? null : self::expiry($file->current_on, $file->months),
        ];
    }

    // ---------------------------------------------------------------- writes

    private function fail(string $field, string $key): never
    {
        throw ValidationException::withMessages([$field => __('executive.errors.'.$key)]);
    }

    /**
     * Creates the project's file ($fileId null) or edits it. $input: number, begin_date, current_date, impound_date, impound_type, months.
     * Returns the file id and how many notices the save created or moved.
     *
     * @return array{id: int, created: bool, notices: int}
     */
    public function save(WebAccount $account, int $projectId, ?int $fileId, array $input, string $version): array
    {
        $this->writable($account, $projectId);

        return DB::transaction(function () use ($account, $projectId, $fileId, $input, $version) {
            $project = DB::table('projects')->where('id', $projectId)->lockForUpdate()->first();
            $file = $this->file($projectId);
            if ($fileId === null && $file !== null) {
                $this->fail('file', 'exists');
            }
            if ($fileId !== null && ($file === null || (int) $file->id !== $fileId)) {
                $this->fail('file', 'stale');
            }
            hash_equals($this->version($file), $version) || $this->fail('file', 'stale');
            $payload = $this->payload($input, $file === null);
            $now = now();
            $details = AccountAuditTrail::changes($file ?? [], $payload) ?? [];
            if ($file !== null && $details === []) {
                return ['id' => (int) $file->id, 'created' => false, 'notices' => 0];
            }
            $expiry = self::expiry($payload['current_on'], $payload['months']);
            if ($file === null) {
                $id = (int) DB::table('executive_files')->insertGetId($payload + ['city_id' => $project->city_id, 'project_id' => $projectId, 'created_at' => $now, 'updated_at' => $now]);
            } else {
                $id = (int) $file->id;
                DB::table('executive_files')->where('id', $id)->update($payload + ['updated_at' => $now]);
            }
            $notices = $this->projectNotices->executiveFile($project, $expiry, $file === null, $account->id);
            $details[] = ['field' => 'expiry_date', 'before' => $file === null ? null : self::expiry($file->current_on, $file->months), 'after' => $expiry];
            $file === null && $details[] = ['field' => 'notices_sent', 'before' => null, 'after' => $notices];
            $this->audit->record($account->id, 'executive_files.saved', 'succeeded', (int) $project->city_id, subjectType: 'application_entity', subjectId: $projectId, details: $details);

            return ['id' => $id, 'created' => $file === null, 'notices' => $notices];
        });
    }

    /** Soft-deletes the file after the confirmation and removes its notices. Returns how many notices were removed. */
    public function delete(WebAccount $account, int $projectId, int $fileId, string $version): int
    {
        $this->writable($account, $projectId);

        return DB::transaction(function () use ($account, $projectId, $fileId, $version) {
            $project = DB::table('projects')->where('id', $projectId)->lockForUpdate()->first();
            $file = $this->file($projectId);
            ($file === null || (int) $file->id !== $fileId || ! hash_equals($this->version($file), $version)) && $this->fail('file', 'stale');
            $now = now();
            DB::table('executive_files')->where('id', $file->id)->update(['deleted_at' => $now, 'updated_at' => $now]);
            $removed = $this->projectNotices->removeExecutiveFile($project);
            $details = [
                ['field' => 'file_number', 'before' => $file->file_number, 'after' => null],
                ['field' => 'expiry_date', 'before' => self::expiry($file->current_on, $file->months), 'after' => null],
                ['field' => 'notices_removed', 'before' => null, 'after' => $removed],
            ];
            $this->audit->record($account->id, 'executive_files.deleted', 'succeeded', (int) $project->city_id, subjectType: 'application_entity', subjectId: $projectId, details: $details);

            return $removed;
        });
    }

    /** Validated columns. A new file starts with its current date equal to the begin date (ExeF_BeginDate_dateTimePicker_ValueChanged). */
    private function payload(array $input, bool $create): array
    {
        $get = fn (string $key) => is_string($input[$key] ?? null) ? trim($input[$key]) : (is_numeric($input[$key] ?? null) ? (string) $input[$key] : '');
        $number = $get('number');
        ($number !== '' && mb_strlen($number) <= 50) || $this->fail('number', 'number');
        $begin = $get('begin_date');
        Fields::isDate($begin) || $this->fail('begin_date', 'date');
        $current = $get('current_date');
        if ($current === '' && $create) {
            $current = $begin;
        }
        Fields::isDate($current) || $this->fail('current_date', 'date');
        $impound = $get('impound_date');
        ($impound === '' || Fields::isDate($impound)) || $this->fail('impound_date', 'date');
        $type = $get('impound_type');
        mb_strlen($type) <= 100 || $this->fail('impound_type', 'type');
        $months = $get('months') === '' ? '0' : $get('months');
        (preg_match('/\A\d{1,3}\z/', $months) === 1 && (int) $months <= self::MAX_MONTHS) || $this->fail('months', 'months');

        return ['file_number' => $number, 'begin_on' => $begin, 'current_on' => $current, 'impound_on' => $impound === '' ? null : $impound, 'impound_type' => $type === '' ? null : $type, 'months' => (int) $months];
    }
}
