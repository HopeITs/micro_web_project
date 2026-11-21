<?php

declare(strict_types=1);

namespace App\Support;

/** The institution e-mail rule: one valid address of the configured domain (config auth.institution_domain), stored lower-case. */
final class InstitutionEmail
{
    public static function domain(): string
    {
        return (string) config('auth.institution_domain', 'hcsyria.org');
    }

    /** The lower-case address when it is a valid e-mail of the institution domain, otherwise null. */
    public static function normalize(string $value): ?string
    {
        $value = mb_strtolower(trim($value));
        if ($value === '' || mb_strlen($value) > 190 || filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        return str_ends_with($value, '@'.self::domain()) ? $value : null;
    }

    /** Whether the typed sign-in name looks like an e-mail (contains @), as opposed to a desktop user name. */
    public static function looksLikeEmail(string $value): bool
    {
        return str_contains($value, '@');
    }
}
