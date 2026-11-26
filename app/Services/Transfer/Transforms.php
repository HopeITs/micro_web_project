<?php

namespace App\Services\Transfer;

/**
 * The value rules of the approved transfer specification (unified schema v1.1, meta.transforms).
 * Each method returns [value, problem]; a non-null problem is recorded in migration_exceptions
 * together with the original value, so nothing is changed silently.
 */
final class Transforms
{
    /** Trim; empty becomes null. */
    public static function trim(mixed $v): array
    {
        if ($v === null) {
            return [null, null];
        }
        $s = trim(preg_replace('/[\x{00A0}\x{200B}-\x{200F}\x{FEFF}]/u', ' ', (string) $v) ?? (string) $v);

        return [$s === '' ? null : $s, null];
    }

    public static function bool01(mixed $v): array
    {
        if ($v === null || $v === '') {
            return [null, null];
        }
        $s = mb_strtolower(trim((string) $v));

        return match ($s) {
            '-1' => [null, null], // the desktop uses -1 for "not answered"
            '1', 'yes', 'true', 'paid', 'نعم' => [true, null],
            '0', 'no', 'false', 'not paid', 'لا' => [false, null],
            default => [null, 'bool_unrecognised'],
        };
    }

    /** Valid dates are kept (date or datetime); null, zero or before 1900 become null. */
    public static function date(mixed $v, bool $withTime = false): array
    {
        if ($v === null || $v === '') {
            return [null, null];
        }
        $s = trim((string) $v);
        if (str_starts_with($s, '0000-00-00')) {
            return [null, 'date_zero'];
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2}))?)?/', $s, $m) !== 1 || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return [null, 'date_invalid'];
        }
        if ((int) $m[1] < 1900 || (int) $m[1] > 2100) {
            return [null, 'date_out_of_range'];
        }
        $date = "{$m[1]}-{$m[2]}-{$m[3]}";

        return [$withTime ? $date.' '.($m[4] ?? '00').':'.($m[5] ?? '00').':'.($m[6] ?? '00') : $date, null];
    }

    public static function sex(mixed $v): array
    {
        [$s] = self::trim($v);

        return match ($s) {
            null => [null, null],
            'ذكر', 'Male', 'male', 'M', 'm' => ['male', null],
            'أنثى', 'انثى', 'Female', 'female', 'F', 'f' => ['female', null],
            default => [null, 'sex_unrecognised'],
        };
    }

    public static function yesNo(mixed $v): array
    {
        [$s] = self::trim($v);

        return match ($s === null ? null : mb_strtolower($s)) {
            null => [null, null],
            'yes', 'no' => [mb_strtolower($s), null],
            default => [mb_substr($s, 0, 10), 'enum_unrecognised'],
        };
    }

    public static function stage(mixed $v): array
    {
        return match (trim((string) $v)) {
            'zero', 'pre_project' => ['preliminary', null],
            'project' => ['full', null],
            default => ['full', 'stage_unrecognised'],
        };
    }

    public static function currency(mixed $v): array
    {
        [$s] = self::trim($v);

        return match ($s === null ? null : strtoupper($s)) {
            null => [null, null],
            'SP', 'SYP', 'ل.س' => ['SYP', null],
            'USD', '$' => ['USD', null],
            'EUR' => ['EUR', null],
            default => [mb_substr($s, 0, 3), 'currency_unrecognised'],
        };
    }

    /** float/double -> DECIMAL(15,2), half-up; a change above 0.005 is reported. */
    public static function money(mixed $v): array
    {
        if ($v === null || $v === '') {
            return [null, null];
        }
        if (! is_numeric($v)) {
            return [null, 'money_not_numeric'];
        }
        $rounded = round((float) $v, 2, PHP_ROUND_HALF_UP);

        return [number_format($rounded, 2, '.', ''), abs($rounded - (float) $v) > 0.005 ? 'money_rounded' : null];
    }

    public static function number(mixed $v, bool $integer = true): array
    {
        if ($v === null || $v === '') {
            return [null, null];
        }
        if (! is_numeric($v)) {
            return [null, 'number_not_numeric'];
        }

        return [$integer ? (int) round((float) $v) : $v, null];
    }

    /** Arabic-aware key for merging list values by name (unify ي/ى, ة/ه, alef forms; drop tatweel/diacritics). */
    public static function nameKey(?string $name): string
    {
        $s = mb_strtolower(trim((string) $name));
        $s = preg_replace('/[\x{064B}-\x{0652}\x{0640}]/u', '', $s) ?? $s;
        $s = strtr($s, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ى' => 'ي', 'ة' => 'ه', 'ؤ' => 'و', 'ئ' => 'ي']);

        return preg_replace('/\s+/u', ' ', $s) ?? $s;
    }

    /** The columns of a list that, with the name, make two source rows the same value: parents and transferred attributes. */
    public static function listKeyColumns(array $columns): array
    {
        return array_values(array_map(fn ($c) => $c['name'], array_filter($columns,
            fn ($c) => (isset($c['source']) || isset($c['references'])) && ! in_array($c['name'], ['city_id', 'name_ar', 'name_en'], true))));
    }

    /**
     * Merge key of a list value. Rows merge only when the name, the parents and every transferred attribute are equal
     * (user decision, 25 November 2026): a difference in a mark, type or limit keeps them as separate values.
     */
    public static function listKey(array $row, array $columns): string
    {
        $parts = array_map(function (string $column) use ($row) {
            $v = $row[$column] ?? null;

            return match (true) {
                $v === null => null,
                is_bool($v) => (string) (int) $v,
                is_numeric($v) => (string) (float) $v, // 5, "5" and "5.00" are the same attribute
                default => trim((string) $v) === '' ? null : trim((string) $v),
            };
        }, $columns);

        return json_encode([self::nameKey($row['name_ar'] ?? null), ...$parts], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /** Notice kind from the desktop body text (desktop notices carry no type). */
    public static function noticeKind(?string $body, mixed $payId): string
    {
        $b = trim((string) $body);

        return match (true) {
            (int) $payId > 0 && preg_match('/^[0-9.,\s]+$/', $b) === 1 => 'payment',
            str_starts_with($b, 'تم تبديل') => 'state',
            str_contains($b, 'المشتريات') => 'purchases',
            str_starts_with($b, 'Visit (Trophy)') => 'trophy',
            str_starts_with($b, 'Visit') => 'visit',
            str_contains($b, 'براءة الذمة') => 'clearance',
            str_contains($b, 'التنفيذي') => 'executive_file',
            default => 'message',
        };
    }
}
