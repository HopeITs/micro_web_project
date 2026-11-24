<?php

declare(strict_types=1);

namespace App\Services\Papers;

use App\Models\WebAccount;
use App\Services\AccountAccess;
use App\Services\AccountAuditTrail;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * M4.20 (schema v1.6): the details each city's papers print (address, phones, office days and hours) and the people who sign for the
 * centre there (director, projects manager, finance manager, purchasing officer, legal representative, each with a validity period).
 * Held by `papers.manage`, checked per city; every change is audited with the changed fields.
 */
final class PaperSettings
{
    public const ROLES = ['director', 'projects_manager', 'finance_manager', 'purchasing_officer', 'legal_representative'];

    public const SETTING_FIELDS = ['address_ar', 'address_en', 'phone_1', 'phone_2', 'complaints_phone', 'office_days', 'office_hours', 'notes'];

    public const SIGNATORY_FIELDS = ['full_name', 'father_name', 'mother_name', 'birth_date', 'registration_place', 'national_number', 'id_issue_text', 'valid_from', 'valid_to'];

    private const MAX = ['address_ar' => 255, 'address_en' => 255, 'phone_1' => 40, 'phone_2' => 40, 'complaints_phone' => 40, 'office_days' => 255, 'office_hours' => 255, 'notes' => 2000,
        'full_name' => 200, 'father_name' => 100, 'mother_name' => 100, 'registration_place' => 100, 'national_number' => 60, 'id_issue_text' => 100];

    public function __construct(private AccountAccess $access, private AccountAuditTrail $audit) {}

    /** Cities where the account may change the paper details. */
    public function cities(WebAccount $account): Collection
    {
        return $this->access->cities($account, 'papers.manage');
    }

    private function authorize(WebAccount $account, int $city): void
    {
        if (! $this->access->check($account, $city, 'papers.manage')) {
            $this->audit->record($account->id, 'access.denied', 'denied', $city);
            abort(403);
        }
    }

    /** @return array{settings: array<string, string>, signatories: Collection} */
    public function page(WebAccount $account, int $city): array
    {
        $this->authorize($account, $city);
        $row = DB::table('city_paper_settings')->where('city_id', $city)->whereNull('deleted_at')->first();
        $settings = [];
        foreach (self::SETTING_FIELDS as $f) {
            $settings[$f] = (string) ($row->{$f} ?? '');
        }
        // Until the city has its own row, the footer the papers print today is shown, so saving keeps it.
        if ($row === null) {
            $footer = PaperCity::footer((string) DB::table('cities')->where('id', $city)->value('code'));
            $settings['address_en'] = $footer['address'];
            $settings['phone_1'] = $footer['mobile'];
        }

        return ['settings' => $settings, 'signatories' => DB::table('city_paper_signatories')->where('city_id', $city)->whereNull('deleted_at')->orderBy('role')->orderByDesc('valid_from')->orderBy('id')->get()];
    }

    /** @param array<string, mixed> $input */
    public function saveSettings(WebAccount $account, int $city, array $input): void
    {
        $this->authorize($account, $city);
        $values = [];
        foreach (self::SETTING_FIELDS as $f) {
            $v = trim((string) ($input[$f] ?? ''));
            if (mb_strlen($v) > self::MAX[$f]) {
                throw ValidationException::withMessages([$f => __('papers.settings.too_long')]);
            }
            $values[$f] = $v === '' ? null : $v;
        }
        $now = now('UTC')->format('Y-m-d H:i:s');
        DB::transaction(function () use ($account, $city, $values, $now) {
            $row = DB::table('city_paper_settings')->where('city_id', $city)->first();
            $changes = AccountAuditTrail::changes($row ?? array_fill_keys(self::SETTING_FIELDS, null), $values);
            if ($row === null) {
                DB::table('city_paper_settings')->insert($values + ['city_id' => $city, 'created_at' => $now, 'updated_at' => $now]);
            } else {
                DB::table('city_paper_settings')->where('id', $row->id)->update($values + ['updated_at' => $now, 'deleted_at' => null]);
            }
            $this->audit->record($account->id, 'papers.settings_saved', 'succeeded', $city, details: $changes);
        });
    }

    /** @param array<string, mixed> $input */
    public function saveSignatory(WebAccount $account, int $city, ?int $id, string $role, array $input): int
    {
        $this->authorize($account, $city);
        if (! in_array($role, self::ROLES, true)) {
            throw ValidationException::withMessages(['role' => __('papers.settings.bad_role')]);
        }
        $values = [];
        foreach (self::SIGNATORY_FIELDS as $f) {
            $v = trim((string) ($input[$f] ?? ''));
            $values[$f] = $v === '' ? null : $v;
        }
        if ($values['full_name'] === null) {
            throw ValidationException::withMessages(['full_name' => __('papers.settings.name_required')]);
        }
        foreach (['birth_date', 'valid_from', 'valid_to'] as $d) {
            if ($values[$d] !== null && (preg_match('/^\d{4}-\d{2}-\d{2}$/', $values[$d]) !== 1 || ! checkdate((int) substr($values[$d], 5, 2), (int) substr($values[$d], 8, 2), (int) substr($values[$d], 0, 4)))) {
                throw ValidationException::withMessages([$d => __('papers.settings.bad_date')]);
            }
        }
        if ($values['valid_from'] !== null && $values['valid_to'] !== null && $values['valid_to'] < $values['valid_from']) {
            throw ValidationException::withMessages(['valid_to' => __('papers.settings.bad_period')]);
        }
        foreach (['full_name', 'father_name', 'mother_name', 'registration_place', 'national_number', 'id_issue_text'] as $f) {
            if (mb_strlen((string) $values[$f]) > self::MAX[$f]) {
                throw ValidationException::withMessages([$f => __('papers.settings.too_long')]);
            }
        }
        $now = now('UTC')->format('Y-m-d H:i:s');

        return DB::transaction(function () use ($account, $city, $id, $role, $values, $now) {
            if ($id === null) {
                $id = (int) DB::table('city_paper_signatories')->insertGetId($values + ['city_id' => $city, 'role' => $role, 'created_at' => $now, 'updated_at' => $now]);
                $changes = AccountAuditTrail::changes(array_fill_keys(array_keys($values), null), $values + ['role' => $role]);
            } else {
                $row = DB::table('city_paper_signatories')->where('id', $id)->where('city_id', $city)->whereNull('deleted_at')->first();
                abort_if($row === null, 404);
                $changes = AccountAuditTrail::changes($row, $values + ['role' => $role]);
                DB::table('city_paper_signatories')->where('id', $id)->update($values + ['role' => $role, 'updated_at' => $now]);
            }
            $this->audit->record($account->id, 'papers.signatory_saved', 'succeeded', $city, decision: 'signatory:'.$id.':'.$role, details: $changes);

            return $id;
        });
    }

    public function removeSignatory(WebAccount $account, int $city, int $id): void
    {
        $this->authorize($account, $city);
        $row = DB::table('city_paper_signatories')->where('id', $id)->where('city_id', $city)->whereNull('deleted_at')->first();
        abort_if($row === null, 404);
        $now = now('UTC')->format('Y-m-d H:i:s');
        DB::table('city_paper_signatories')->where('id', $id)->update(['deleted_at' => $now, 'updated_at' => $now]);
        $this->audit->record($account->id, 'papers.signatory_removed', 'succeeded', $city, decision: 'signatory:'.$id.':'.$row->role);
    }

    /** The signatory holding a role in a city on a date (default today): the newest valid one. */
    public static function signatory(int $city, string $role, ?string $on = null): ?object
    {
        $on ??= now()->toDateString();

        return DB::table('city_paper_signatories')->where('city_id', $city)->where('role', $role)->whereNull('deleted_at')
            ->where(fn ($q) => $q->whereNull('valid_from')->orWhere('valid_from', '<=', $on))->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', $on))
            ->orderByDesc('valid_from')->orderByDesc('id')->first();
    }
}
