<?php

declare(strict_types=1);

namespace App\Services;

use App\Mail\EmailVerificationCode;
use App\Models\WebAccount;
use App\Support\InstitutionEmail;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * An account without an e-mail (owner decision of 21 Nov 2026) signs in once more with its user name and must then add its institution
 * e-mail before using the site. Ownership is proven with a six-digit code sent to that address; the pending code lives only in the
 * signed-in session (as a hash, with an expiry and an attempt limit), so no table is needed for it.
 */
final class AccountEmails
{
    public const CODE_MINUTES = 15;

    public const MAX_ATTEMPTS = 5;

    public const RESEND_SECONDS = 60;

    private const KEY = 'account_email_pending';

    public function __construct(private AccountAuditTrail $audit) {}

    /** Whether the account still has to add its institution e-mail (none, or an older address of another domain) before using the site. */
    public function missing(WebAccount $account): bool
    {
        return InstitutionEmail::normalize((string) DB::table('web_accounts')->where('id', $account->id)->value('email')) === null;
    }

    /** The address waiting for its code in this session, or null. */
    public function pending(Session $session): ?string
    {
        $pending = $session->get(self::KEY);

        return is_array($pending) && ($pending['expires'] ?? 0) > time() ? (string) $pending['email'] : null;
    }

    /** Checks the address and e-mails a code to it. Throws a validation error on an address that is not allowed or already used. */
    public function sendCode(WebAccount $account, string $email, Session $session, string $locale): void
    {
        $value = InstitutionEmail::normalize($email)
            ?? throw ValidationException::withMessages(['email' => __('account.email_domain', ['domain' => InstitutionEmail::domain()])]);
        if (DB::table('web_accounts')->where('email', $value)->where('id', '<>', $account->id)->exists()) {
            throw ValidationException::withMessages(['email' => __('account.email_taken')]);
        }
        $pending = $session->get(self::KEY);
        if (is_array($pending) && $pending['email'] === $value && ($pending['sent'] ?? 0) > time() - self::RESEND_SECONDS) {
            throw ValidationException::withMessages(['email' => __('account.code_wait', ['seconds' => self::RESEND_SECONDS])]);
        }
        $code = (string) random_int(100000, 999999);
        $session->put(self::KEY, [
            'email' => $value, 'code' => hash_hmac('sha256', $code, (string) config('app.key')),
            'sent' => time(), 'expires' => time() + self::CODE_MINUTES * 60, 'attempts' => 0,
        ]);
        Mail::to($value)->send(new EmailVerificationCode($code, self::CODE_MINUTES, $locale));
    }

    /** Saves the pending address when the code matches. Throws a validation error otherwise. */
    public function confirm(WebAccount $account, string $code, Session $session): string
    {
        $pending = $session->get(self::KEY);
        if (! is_array($pending) || ($pending['expires'] ?? 0) <= time() || ($pending['attempts'] ?? 0) >= self::MAX_ATTEMPTS) {
            $session->forget(self::KEY);
            throw ValidationException::withMessages(['email' => __('account.code_expired')]);
        }
        $code = preg_replace('/\D/', '', $code) ?? '';
        if (! hash_equals((string) $pending['code'], hash_hmac('sha256', $code, (string) config('app.key')))) {
            $pending['attempts'] = ($pending['attempts'] ?? 0) + 1;
            $session->put(self::KEY, $pending);
            throw ValidationException::withMessages(['code' => __('account.code_wrong')]);
        }
        $email = (string) $pending['email'];
        DB::transaction(function () use ($account, $email) {
            $row = DB::table('web_accounts')->where('id', $account->id)->lockForUpdate()->first(['email']);
            if (DB::table('web_accounts')->where('email', $email)->where('id', '<>', $account->id)->exists()) {
                throw ValidationException::withMessages(['email' => __('account.email_taken')]);
            }
            DB::table('web_accounts')->where('id', $account->id)->update(['email' => $email, 'updated_at' => now('UTC')->format('Y-m-d H:i:s.u')]);
            $this->audit->record($account->id, 'accounts.email_set', 'succeeded', subjectAccount: $account->id, subjectType: 'account', subjectId: $account->id,
                details: AccountAuditTrail::changes(['email' => $row?->email], ['email' => $email]));
        });
        $session->forget(self::KEY);

        return $email;
    }

    /** Starts over with another address. */
    public function cancel(Session $session): void
    {
        $session->forget(self::KEY);
    }
}
