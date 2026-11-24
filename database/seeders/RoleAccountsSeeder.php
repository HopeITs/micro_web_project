<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\WebAccount;
use App\Services\AccessRoles;
use App\Services\AccountProvisioner;
use App\Support\InstitutionEmail;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * One test account per staff role, all with the password "password", for viewing the site as each role while no real
 * data is in use (owner instruction of 28 October 2026). Re-running always restores them: a missing account is created,
 * an existing one gets the password reset, re-enabled and its role re-assigned in every city. Refuses to run in production.
 *
 *   php artisan db:seed --class=RoleAccountsSeeder
 */
final class RoleAccountsSeeder extends Seeder
{
    public const PASSWORD = 'password';

    public const DECISION = 'seed:test-role-accounts';

    /**
     * Sign-in e-mail of a test account (logins use the institution e-mail since 21 Nov 2026): <user name>@<institution domain>.
     * `judy` gets none, so the owner sees the transition (user name once, then "add your e-mail").
     */
    public static function email(string $username): ?string
    {
        return $username === 'judy' ? null : $username.'@'.InstitutionEmail::domain();
    }

    /** username => role; `super.admin` is an extra administrator kept from the first local test round, `judy` the owner's own (20 Nov 2026). */
    public static function accounts(): array
    {
        $accounts = ['super.admin' => 'administrator', 'judy' => 'administrator'];
        foreach (array_keys(AccessRoles::ROLES) as $role) {
            $accounts['role.'.$role] = $role;
        }

        return $accounts;
    }

    /** The test accounts' password: SEED_ACCOUNT_PASSWORD when set; the well-known default only in local and testing. */
    public static function password(): string
    {
        $set = (string) config('app.seed_account_password');
        if ($set !== '') {
            return $set;
        }
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Set SEED_ACCOUNT_PASSWORD (a strong value) before seeding test accounts outside local and testing.');
        }

        return self::PASSWORD;
    }

    public function run(AccountProvisioner $provisioner, AccessRoles $roles): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('Test role accounts are never seeded in production.');
        }
        $password = self::password();
        $cities = DB::table('cities')->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
        $now = now('UTC')->format('Y-m-d H:i:s.u');
        foreach (self::accounts() as $username => $role) {
            $account = WebAccount::where('username', $username)->first()
                ?? $provisioner->createPlain($username, self::email($username), Hash::make($password), self::DECISION);
            $email = self::email($username);
            if ($email !== null && $account->email === null && DB::table('web_accounts')->where('email', $email)->doesntExist()) {
                DB::table('web_accounts')->where('id', $account->id)->update(['email' => $email]);
            }
            // A normal password, not the one-time kind: password_changed_at set means no forced change at first login.
            DB::table('web_accounts')->where('id', $account->id)->update(['password_hash' => Hash::make($password), 'password_changed_at' => $now, 'disabled_at' => null, 'updated_at' => $now]);
            foreach ($cities as $city) {
                $roles->assign($account->fresh(), $city, $role, self::DECISION);
            }
            $this->command?->info(sprintf('%s → %s in %d cities', $username, $role, count($cities)));
        }
    }
}
