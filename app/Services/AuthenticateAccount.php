<?php

namespace App\Services;

use App\Models\LegacyStaffUser;
use App\Models\WebAccount;
use App\Security\LegacyPasswordVerifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Global username/password login (unified schema v1.1). An account either has its own Argon2id hash, or —
 * until its first web login — the desktop PBKDF2 hash of its reviewed legacy_staff_users credential. Both
 * paths always do the same hashing work so timing does not reveal which accounts exist.
 */
final class AuthenticateAccount
{
    private const DUMMY_HASH = '$argon2id$v=19$m=65536,t=3,p=1$N3NJMHBGVWRUMFMyRUJ5Lg$sHlNUI9jVD2cZBUk/Xd47YpTn75rN+5zeqZrLRksEYU';

    private const DUMMY_LEGACY = 'AAECAwQFBgcICQoLDA0OD6rirHkgJUrCARvhwiO9AlEz7NUBCDqkiw29w+5zjOOk';

    public function __construct(private LegacyPasswordVerifier $legacy, private AccountAuditTrail $audit) {}

    public function attempt(string $username, string $password): ?WebAccount
    {
        return DB::transaction(function () use ($username, $password) {
            $legacyPerformed = false;
            $modernPerformed = false;
            $completeWork = function () use ($password, &$legacyPerformed, &$modernPerformed) {
                if (! $legacyPerformed) {
                    $this->legacy->verify(substr($password, 0, 4096), self::DUMMY_LEGACY);
                    $legacyPerformed = true;
                }
                if (! $modernPerformed) {
                    password_verify(substr($password, 0, 4096), self::DUMMY_HASH);
                    $modernPerformed = true;
                }
            };
            $deny = function () use ($completeWork) {
                $completeWork();
                $this->audit->record(null, 'login.denied', 'denied');

                return null;
            };
            if ($username === '' || strlen($username) > 800 || mb_strlen($username) > 200 || strlen($password) > 4096
                || preg_match('//u', $username) !== 1 || preg_match('//u', $password) !== 1) {
                return $deny();
            }
            $account = WebAccount::where('username', $username)->lockForUpdate()->first();
            if ($account === null || $account->disabled_at !== null) {
                return $deny();
            }
            $hash = $account->password_hash;
            $legacyHash = null;
            if ($hash === null) {
                $linked = DB::table('account_legacy_links')->where('account_id', $account->id)
                    ->where('legacy_staff_user_id', $account->credential_legacy_user_id)->whereNull('revoked_at')->sharedLock()->exists();
                $user = $linked ? LegacyStaffUser::whereKey($account->credential_legacy_user_id)->whereNull('disabled_at')->sharedLock()->first() : null;
                if ($user === null || $user->password_hash === null) {
                    return $deny();
                }
                $legacyHash = $user->password_hash;
            }
            try {
                if ($hash !== null) {
                    $modernPerformed = str_starts_with($hash, '$argon2id$');
                    $algorithm = password_get_info($hash)['algoName'];
                    $valid = $algorithm !== 'unknown' && ! ($algorithm === 'bcrypt' && strlen($password) > 72)
                        && password_verify($password, $hash);
                } else {
                    $encoded = str_replace([' ', "\t", "\r", "\n"], '', (string) $legacyHash);
                    $legacyPerformed = preg_match('/\A[A-Za-z0-9+\/]{64}\z/', $encoded) === 1;
                    $valid = $this->legacy->verify($password, (string) $legacyHash);
                }
            } catch (RuntimeException) {
                return $deny();
            }
            if (! $valid) {
                return $deny();
            }
            if ($account->password_hash === null || Hash::needsRehash($account->password_hash)) {
                $account->password_hash = Hash::make($password);
                $modernPerformed = true;
                $account->save();
                $this->audit->record($account->id, 'password.rehashed', 'succeeded', subjectAccount: $account->id, subjectType: 'account', subjectId: $account->id);
            }
            $completeWork();
            $this->audit->record($account->id, 'login.succeeded', 'succeeded', subjectAccount: $account->id, subjectType: 'account', subjectId: $account->id);

            return $account;
        }, attempts: 3);
    }
}
