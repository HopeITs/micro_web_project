<?php

declare(strict_types=1);

namespace App\Services\Kobo;

/**
 * The XLSForm rules of the Kobo forms (M4.14b): required, relevant (show-if), constraint, hint and appearance per field path, read from
 * `resources/kobo/form-rules.json` (made by `tools/kobo/export-form-rules.php` from the 47 XLSForm exports, which hold what the question
 * catalogue lacks). The expressions use only: `${f} = 'v'`, `${f} != 'v'`, `${f} != ${g}`, `selected(${f}, 'v')`, joined by `and` / `or`
 * (no parentheses; `and` binds tighter), with `${f}` already resolved to the field path. A hidden question counts as unanswered and its
 * answer is dropped; a required one must be answered when shown; a constraint is checked once the question is answered.
 */
final class KoboRules
{
    private static ?array $rules = null;

    /** @return array<string, array<string, array{required?: bool, relevant?: list<string>, constraint?: string, message?: string, hint?: string, appearance?: string}>> */
    public static function all(): array
    {
        return self::$rules ??= json_decode((string) file_get_contents(resource_path('kobo/form-rules.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    public static function for(string $uid, string $path): array
    {
        return self::all()[$uid][$path] ?? [];
    }

    /** Paths that other questions' show-if or constraint read: their inputs update the page as they change. */
    public static function controlling(string $uid): array
    {
        static $cache = [];
        if (! isset($cache[$uid])) {
            $paths = [];
            foreach (self::all()[$uid] ?? [] as $rule) {
                foreach ([...($rule['relevant'] ?? []), $rule['constraint'] ?? ''] as $expr) {
                    preg_match_all('/\$\{([^}]+)\}/', (string) $expr, $m);
                    foreach ($m[1] as $path) {
                        $paths[$path] = true;
                    }
                }
            }
            $cache[$uid] = $paths;
        }

        return $cache[$uid];
    }

    /**
     * Whether the question is shown, given the answers.
     *
     * @param  callable(string): string  $value  the current answer of a path ('' when unanswered or hidden)
     */
    public static function shown(array $rule, callable $value): bool
    {
        foreach ($rule['relevant'] ?? [] as $expr) {
            if (! self::test($expr, $value)) {
                return false;
            }
        }

        return true;
    }

    /** @param callable(string): string $value */
    public static function test(string $expr, callable $value): bool
    {
        foreach (preg_split('/\s+or\s+/', trim($expr)) ?: [] as $any) {
            $all = true;
            foreach (preg_split('/\s+and\s+/', $any) ?: [] as $term) {
                if (! self::term(trim($term), $value)) {
                    $all = false;
                    break;
                }
            }
            if ($all) {
                return true;
            }
        }

        return false;
    }

    /** @param callable(string): string $value */
    private static function term(string $term, callable $value): bool
    {
        if (preg_match('/^selected\(\s*\$\{([^}]+)\}\s*,\s*\'([^\']*)\'\s*\)$/u', $term, $m) === 1) {
            return in_array($m[2], preg_split('/\s+/', trim($value($m[1])), -1, PREG_SPLIT_NO_EMPTY) ?: [], true);
        }
        if (preg_match('/^\$\{([^}]+)\}\s*(!=|=)\s*(?:\'([^\']*)\'|\$\{([^}]+)\})$/u', $term, $m) === 1) {
            $left = $value($m[1]);
            $right = isset($m[4]) && $m[4] !== '' ? $value($m[4]) : $m[3];

            return $m[2] === '=' ? $left === $right : $left !== $right;
        }

        // An expression outside the supported grammar never hides a question or blocks an answer.
        return true;
    }
}
