<?php

namespace App\Services;

use App\Models\LegacyStaffUser;
use App\Models\WebAccount;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Controlled administrative service. It writes accounts, reviewed links to desktop users (legacy_staff_users, unified
 * schema v1.1) and city capabilities, so only the staff administration screens (`accounts.manage`) and provisioning
 * commands reach it.
 */
final class AccountProvisioner
{
    public function __construct(private AccountAuditTrail $audit) {}

    /**
     * Creates an account from reviewed desktop users of one or more cities. The credential user's desktop PBKDF2 hash is
     * used until the first web login; every reviewed user is linked so old records resolve to this account.
     *
     * @param  list<int>  $reviewedUserIds
     * @param  array<int, list<string>>  $cityCapabilities
     */
    public function provision(string $username, int $credentialUserId, array $reviewedUserIds, array $cityCapabilities, string $decision, ?int $actor = null): WebAccount
    {
        if ($username === '' || strlen($username) > 800 || preg_match('//u', $username) !== 1 || mb_strlen($username) > 200
            || ! AccountAuditTrail::validDecision($decision) || $credentialUserId < 1 || $reviewedUserIds === []
            || ! in_array($credentialUserId, $reviewedUserIds, true)
            || count(array_unique($reviewedUserIds)) !== count($reviewedUserIds)) {
            throw new InvalidArgumentException('Explicit reviewed account identity and decision are required.');
        }
        foreach ($reviewedUserIds as $id) {
            if (! is_int($id) || $id < 1) {
                throw new InvalidArgumentException('Reviewed legacy user IDs must be positive integers.');
            }
        }

        return DB::transaction(function () use ($username, $credentialUserId, $reviewedUserIds, $cityCapabilities, $decision, $actor) {
            sort($reviewedUserIds);
            $users = LegacyStaffUser::whereIn('id', $reviewedUserIds)->orderBy('id')->lockForUpdate()->get();
            if ($users->count() !== count($reviewedUserIds)
                || DB::table('account_legacy_links')->whereIn('legacy_staff_user_id', $reviewedUserIds)->exists()) {
                throw new InvalidArgumentException('A reviewed legacy user is missing or already linked.');
            }
            foreach ($users as $user) {
                if ($user->disabled_at !== null) {
                    throw new InvalidArgumentException('Inactive legacy identity requires a separate staff decision.');
                }
                if ($user->id === $credentialUserId && ! self::compatibleLegacyHash($user->password_hash)) {
                    throw new InvalidArgumentException('Selected credential needs compatibility review.');
                }
            }
            $time = now('UTC')->format('Y-m-d H:i:s.u');
            $id = DB::table('web_accounts')->insertGetId(['username' => $username, 'email' => null, 'password_hash' => null, 'password_changed_at' => null, 'credential_legacy_user_id' => $credentialUserId, 'disabled_at' => null, 'created_at' => $time, 'updated_at' => $time]);
            $account = WebAccount::findOrFail($id);
            $this->audit->record($actor, 'account.provisioned', 'succeeded', subjectAccount: $account->id, subjectType: 'account', subjectId: $account->id, decision: $decision);
            foreach ($users as $user) {
                $this->insertLink($account, $user, $decision, $actor);
            }
            foreach ($cityCapabilities as $city => $capabilities) {
                if (! is_int($city) || ! is_array($capabilities)) {
                    throw new InvalidArgumentException('City capabilities must map integer city IDs to capability lists.');
                }
                foreach ($capabilities as $capability) {
                    $this->grant($account, $city, $capability, $decision, $actor);
                }
            }

            return $account;
        }, attempts: 3);
    }

    /** The desktop stores Base64(16-byte salt + 32-byte PBKDF2-SHA256 hash): 64 characters. */
    public static function compatibleLegacyHash(?string $hash): bool
    {
        $encoded = str_replace([' ', "\t", "\r", "\n"], '', (string) $hash);

        return preg_match('/\A[A-Za-z0-9+\/]{64}\z/', $encoded) === 1 && strlen((string) base64_decode($encoded, true)) === 48;
    }

    public function grant(WebAccount $account, int $city, string $capability, string $decision, ?int $actor = null): void
    {
        $this->changeGrant($account, $city, $capability, $decision, $actor, false);
    }

    public function revoke(WebAccount $account, int $city, string $capability, string $decision, ?int $actor = null): void
    {
        $this->changeGrant($account, $city, $capability, $decision, $actor, true);
    }

    private function changeGrant(WebAccount $account, int $city, string $capability, string $decision, ?int $actor, bool $revoke): void
    {
        if (! in_array($capability, AccountAccess::CAPABILITIES, true) || ! AccountAuditTrail::validDecision($decision)
            || ! DB::table('cities')->where('id', $city)->exists()) {
            throw new InvalidArgumentException('Invalid explicit city capability assignment.');
        }
        DB::transaction(function () use ($account, $city, $capability, $decision, $actor, $revoke) {
            WebAccount::whereKey($account->id)->lockForUpdate()->firstOrFail();
            $query = DB::table('account_city_capabilities')->where('account_id', $account->id)->where('city_id', $city)->where('capability', $capability);
            $grant = (clone $query)->lockForUpdate()->first();
            $time = now('UTC')->format('Y-m-d H:i:s.u');
            if ($revoke) {
                if ($grant === null) {
                    throw new InvalidArgumentException('Cannot revoke a missing city capability.');
                }
                $query->update(['revoked_at' => $time]);
                $id = $grant->id;
            } else {
                $values = ['decision_locator' => $decision, 'granted_at' => $time, 'revoked_at' => null];
                if ($grant === null) {
                    $id = DB::table('account_city_capabilities')->insertGetId($values + ['account_id' => $account->id, 'city_id' => $city, 'capability' => $capability]);
                } else {
                    $query->update($values);
                    $id = $grant->id;
                }
            }
            $this->audit->record($actor, $revoke ? 'capability.revoked' : 'capability.granted', 'succeeded', $city, $account->id, 'capability_grant', $id, $decision);
        }, attempts: 3);
    }

    public function setLinkRevoked(WebAccount $account, int $legacyUserId, bool $revoked, string $decision, ?int $actor = null): void
    {
        if (! AccountAuditTrail::validDecision($decision)) {
            throw new InvalidArgumentException('A link review decision is required.');
        }
        DB::transaction(function () use ($account, $legacyUserId, $revoked, $decision, $actor) {
            WebAccount::whereKey($account->id)->lockForUpdate()->firstOrFail();
            $query = DB::table('account_legacy_links')->where('account_id', $account->id)->where('legacy_staff_user_id', $legacyUserId);
            $link = (clone $query)->lockForUpdate()->first();
            if ($link === null) {
                throw new InvalidArgumentException('No reviewed link exists.');
            }
            if (! $revoked && LegacyStaffUser::whereKey($legacyUserId)->whereNull('disabled_at')->lockForUpdate()->doesntExist()) {
                throw new InvalidArgumentException('Inactive legacy identity requires a separate staff decision.');
            }
            $query->update(['revoked_at' => $revoked ? now('UTC')->format('Y-m-d H:i:s.u') : null]);
            $this->audit->record($actor, $revoked ? 'legacy.revoked' : 'legacy.restored', 'succeeded', subjectAccount: $account->id, subjectType: 'legacy_link', subjectId: $link->id, decision: $decision);
        }, attempts: 3);
    }

    /** Adds one reviewed desktop user to an existing account. A user already linked to any account is never moved. */
    public function linkLegacyUser(WebAccount $account, int $legacyUserId, string $decision, ?int $actor = null): int
    {
        if (! AccountAuditTrail::validDecision($decision)) {
            throw new InvalidArgumentException('A link review decision is required.');
        }

        return DB::transaction(function () use ($account, $legacyUserId, $decision, $actor) {
            WebAccount::whereKey($account->id)->lockForUpdate()->firstOrFail();
            $user = LegacyStaffUser::whereKey($legacyUserId)->lockForUpdate()->first();
            if ($user === null || DB::table('account_legacy_links')->where('legacy_staff_user_id', $legacyUserId)->exists()) {
                throw new InvalidArgumentException('A reviewed legacy user is missing or already linked.');
            }
            if ($user->disabled_at !== null) {
                throw new InvalidArgumentException('Inactive legacy identity requires a separate staff decision.');
            }

            return $this->insertLink($account, $user, $decision, $actor);
        }, attempts: 3);
    }

    private function insertLink(WebAccount $account, LegacyStaffUser $user, string $decision, ?int $actor): int
    {
        $linkId = DB::table('account_legacy_links')->insertGetId([
            'account_id' => $account->id, 'city_id' => $user->city_id, 'legacy_staff_user_id' => $user->id,
            'decision_locator' => $decision, 'reviewed_at' => now('UTC')->format('Y-m-d H:i:s.u'), 'revoked_at' => null,
        ]);
        $this->audit->record($actor, 'legacy.linked', 'succeeded', $user->city_id, $account->id, 'legacy_link', $linkId, $decision);

        return (int) $linkId;
    }

    /** Disables or re-enables the whole web account (every city); protected requests recheck it. */
    public function setDisabled(WebAccount $account, bool $disabled, ?int $actor = null, ?string $decision = null): void
    {
        if ($decision !== null && ! AccountAuditTrail::validDecision($decision)) {
            throw new InvalidArgumentException('Invalid decision reference.');
        }
        DB::transaction(function () use ($account, $disabled, $actor, $decision) {
            WebAccount::whereKey($account->id)->lockForUpdate()->firstOrFail();
            $time = now('UTC')->format('Y-m-d H:i:s.u');
            DB::table('web_accounts')->where('id', $account->id)->update(['disabled_at' => $disabled ? $time : null, 'updated_at' => $time]);
            $this->audit->record($actor, $disabled ? 'accounts.disabled' : 'accounts.enabled', 'succeeded', subjectAccount: $account->id, subjectType: 'account', subjectId: $account->id, decision: $decision);
        }, attempts: 3);
    }

    /**
     * Creates an account that has no desktop identity: it carries its own password hash, which its user must replace at first
     * login (`credential_legacy_user_id` NULL and `password_changed_at` NULL mark it).
     */
    public function createPlain(string $username, ?string $email, string $passwordHash, string $decision, ?int $actor = null): WebAccount
    {
        if ($username === '' || strlen($username) > 800 || mb_strlen($username) > 200 || preg_match('//u', $username) !== 1 || ! AccountAuditTrail::validDecision($decision) || $passwordHash === '') {
            throw new InvalidArgumentException('A valid user name, password hash and decision are required.');
        }

        return DB::transaction(function () use ($username, $email, $passwordHash, $decision, $actor) {
            $time = now('UTC')->format('Y-m-d H:i:s.u');
            $id = DB::table('web_accounts')->insertGetId(['username' => $username, 'email' => $email, 'password_hash' => $passwordHash, 'password_changed_at' => null, 'credential_legacy_user_id' => null, 'disabled_at' => null, 'created_at' => $time, 'updated_at' => $time]);
            $this->audit->record($actor, 'account.provisioned', 'succeeded', subjectAccount: $id, subjectType: 'account', subjectId: $id, decision: $decision);

            return WebAccount::findOrFail($id);
        }, attempts: 3);
    }
}
