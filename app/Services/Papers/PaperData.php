<?php

declare(strict_types=1);

namespace App\Services\Papers;

use App\Models\WebAccount;
use App\Services\AccountAccess;
use App\Services\AccountAuditTrail;
use App\Services\Finance\FinanceService;
use App\Services\Finance\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * M4.20 (schema v1.6): the details a project keeps for two papers, so they print filled in: the disbursement request (F19: request number and
 * date, hold amount, extra amount and its reason) and the partnership contract (F18: purpose, financed equipment, the trade text, duration,
 * start and end, profit period, signing date, chosen address). One row per project; reading needs finance.view, saving finance.manage in the
 * project's city. Every save is audited with the changed fields.
 */
final class PaperData
{
    public const FORMS = [
        'disbursement' => ['table' => 'disbursement_requests', 'fields' => ['request_number' => 60, 'requested_on' => 'date', 'hold_amount' => 'money', 'extra_amount' => 'money', 'extra_reason' => 255, 'notes' => 1000]],
        'contract' => ['table' => 'partnership_contracts', 'fields' => ['purpose' => 255, 'financed_equipment' => 1000, 'work_text' => 255, 'duration_months' => 'int', 'starts_on' => 'date', 'ends_on' => 'date', 'profit_period' => 255, 'signed_on' => 'date', 'notice_address' => 255]],
    ];

    public function __construct(private FinanceService $finance, private AccountAccess $access, private AccountAuditTrail $audit) {}

    /** @return array{project: object, rows: array<string, array<string, string>>, canManage: bool} */
    public function page(WebAccount $account, int $projectId): array
    {
        $project = $this->finance->project($account, $projectId);
        $rows = [];
        foreach (self::FORMS as $form => $spec) {
            $row = DB::table($spec['table'])->where('project_id', $projectId)->whereNull('deleted_at')->orderByDesc('id')->first();
            foreach (array_keys($spec['fields']) as $f) {
                $v = $row->{$f} ?? null;
                $rows[$form][$f] = $v === null ? '' : (str_ends_with($f, '_on') ? substr((string) $v, 0, 10) : (str_ends_with($f, '_amount') ? (Money::canonical($v) ?? '') : (string) $v));
            }
        }

        return ['project' => $project, 'rows' => $rows, 'canManage' => $this->access->check($account, (int) $project->city_id, 'finance.manage')];
    }

    /** @param array<string, mixed> $input */
    public function save(WebAccount $account, int $projectId, string $form, array $input): void
    {
        $spec = self::FORMS[$form] ?? abort(404);
        $project = $this->finance->project($account, $projectId);
        $city = (int) $project->city_id;
        if (! $this->access->check($account, $city, 'finance.manage')) {
            $this->audit->record($account->id, 'access.denied', 'denied', $city);
            abort(403);
        }
        $values = [];
        foreach ($spec['fields'] as $f => $kind) {
            $v = trim((string) ($input[$f] ?? ''));
            if ($v === '') {
                $values[$f] = null;

                continue;
            }
            $bad = match (true) {
                is_int($kind) => mb_strlen($v) > $kind,
                $kind === 'date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) !== 1 || ! checkdate((int) substr($v, 5, 2), (int) substr($v, 8, 2), (int) substr($v, 0, 4)),
                $kind === 'money' => ! Money::isDecimal(Money::normalize($v), 2),
                $kind === 'int' => preg_match('/^\d{1,3}$/', $v) !== 1 || (int) $v < 1,
                default => false,
            };
            if ($bad) {
                throw ValidationException::withMessages([$f => __('papers.data.bad_value')]);
            }
            $values[$f] = $kind === 'money' ? Money::normalize($v) : ($kind === 'int' ? (int) $v : $v);
        }
        if (($values['starts_on'] ?? null) !== null && ($values['ends_on'] ?? null) !== null && $values['ends_on'] < $values['starts_on']) {
            throw ValidationException::withMessages(['ends_on' => __('papers.settings.bad_period')]);
        }
        $now = now('UTC')->format('Y-m-d H:i:s');
        DB::transaction(function () use ($account, $projectId, $form, $spec, $values, $city, $now) {
            $row = DB::table($spec['table'])->where('project_id', $projectId)->whereNull('deleted_at')->orderByDesc('id')->first();
            $changes = AccountAuditTrail::changes($row ?? array_fill_keys(array_keys($values), null), $values);
            if ($row === null) {
                DB::table($spec['table'])->insert($values + ['city_id' => $city, 'project_id' => $projectId, 'created_at' => $now, 'updated_at' => $now]);
            } else {
                DB::table($spec['table'])->where('id', $row->id)->update($values + ['updated_at' => $now]);
            }
            $this->audit->record($account->id, 'papers.data_saved', 'succeeded', $city, subjectType: 'application_entity', subjectId: $projectId, decision: 'paper-data:'.$form, details: $changes);
        });
    }
}
