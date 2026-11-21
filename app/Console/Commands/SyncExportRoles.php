<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\WebAccount;
use App\Services\AccessRoles;
use App\Services\AccountAccess;
use App\Services\AccountAuditTrail;
use App\Services\AccountProvisioner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Brings existing accounts in line with the export rules of 27 October 2026: the cashier duty is now the financial role, and the roles that
 * hold the new export and attachment-category grants (`finance.export`, `reports.export`, `attachments.personal|legal|financial`) give them
 * to the accounts that already hold the role. Only the new grants are added, never anything an administrator revoked by hand. Since M4.10 it also grants purchasing to the data-entry and legal holders and takes executive-file writing and purchasing from financial holders (unless another of their roles includes them). Safe to repeat.
 */
final class SyncExportRoles extends Command
{
    protected $signature = 'staff:sync-export-roles {--dry-run : Report what would change and change nothing} {--decision= : Evidence reference recorded with each grant}';

    protected $description = 'Merge the cashier role into financial and grant the new export and attachment-category grants to the roles that carry them';

    public function handle(AccessRoles $roles, AccountProvisioner $provisioner): int
    {
        $decision = (string) ($this->option('decision') ?: 'role-sync:export-rules:'.now('UTC')->format('Ymd'));
        if (! AccountAuditTrail::validDecision($decision)) {
            $this->error('The decision reference may use letters, digits and _ : / . - only.');

            return self::FAILURE;
        }
        $counts = ['cashier roles merged' => 0, 'grants added' => 0, 'grants removed' => 0];
        DB::beginTransaction();
        try {
            foreach (DB::table('account_city_roles')->where('role', 'cashier')->whereNull('revoked_at')->get() as $row) {
                $account = WebAccount::find($row->account_id);
                if ($account === null) {
                    continue;
                }
                // The cashier assignment ends and the account holds the financial role instead (its grants are given in full).
                DB::table('account_city_roles')->where('id', $row->id)->update(['revoked_at' => now('UTC')->format('Y-m-d H:i:s.u')]);
                $roles->assign($account, (int) $row->city_id, 'financial', $decision);
                $counts['cashier roles merged']++;
            }
            foreach (DB::table('account_city_roles')->whereNull('revoked_at')->get() as $row) {
                $account = WebAccount::find($row->account_id);
                if ($account === null || ! isset(AccessRoles::ROLES[$row->role])) {
                    continue;
                }
                foreach (array_intersect(AccountAccess::ADDED_CAPABILITIES, AccessRoles::capabilities($row->role)) as $capability) {
                    $held = DB::table('account_city_capabilities')->where('account_id', $row->account_id)->where('city_id', $row->city_id)->where('capability', $capability)->exists();
                    if (! $held) {
                        $provisioner->grant($account, (int) $row->city_id, $capability, $decision);
                        $counts['grants added']++;
                    }
                }
            }
            // Capabilities taken away from a role (M4.10): revoked unless another active role of the account in that city still includes them.
            foreach (AccountAccess::REMOVED_CAPABILITIES as $role => $removed) {
                foreach (DB::table('account_city_roles')->where('role', $role)->whereNull('revoked_at')->get() as $row) {
                    $account = WebAccount::find($row->account_id);
                    if ($account === null) {
                        continue;
                    }
                    $kept = [];
                    foreach ($roles->roles($account, (int) $row->city_id) as $other) {
                        $kept = array_merge($kept, AccessRoles::capabilities($other));
                    }
                    foreach (array_diff($removed, $kept) as $capability) {
                        $held = DB::table('account_city_capabilities')->where('account_id', $row->account_id)->where('city_id', $row->city_id)->where('capability', $capability)->whereNull('revoked_at')->exists();
                        if ($held) {
                            $provisioner->revoke($account, (int) $row->city_id, $capability, $decision);
                            $counts['grants removed']++;
                        }
                    }
                }
            }
            $this->option('dry-run') ? DB::rollBack() : DB::commit();
        } catch (\Throwable $exception) {
            DB::rollBack();
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
        foreach ($counts as $what => $count) {
            $this->line(sprintf('%-22s %d', $what, $count));
        }
        $this->info($this->option('dry-run') ? 'Dry run: nothing was changed.' : 'Done.');

        return self::SUCCESS;
    }
}
