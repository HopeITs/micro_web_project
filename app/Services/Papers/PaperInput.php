<?php

declare(strict_types=1);

namespace App\Services\Papers;

use App\Services\AccountAuditTrail;
use App\Services\Finance\FinanceService;
use App\Services\Finance\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** The typed details of a paper (M4.20): one value cleaned by its kind, and one record of a project saved with its changed fields. */
final class PaperInput
{
    /**
     * A blank value is null. Kinds: a text length, `date`, `month` (YYYY-MM), `time` (HH:MM), `money`, `rate` (0–100), `months` (0–120),
     * `year` (4 digits), `currency`.
     */
    public static function clean(mixed $input, int|string $kind, string $field): string|int|null
    {
        $v = is_scalar($input) ? trim((string) $input) : '';
        if ($v === '') {
            return null;
        }
        $number = Money::normalize($v);
        $ok = match (true) {
            is_int($kind) => mb_strlen($v) <= $kind,
            $kind === 'date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1 && checkdate((int) substr($v, 5, 2), (int) substr($v, 8, 2), (int) substr($v, 0, 4)),
            $kind === 'month' => preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $v) === 1,
            $kind === 'time' => preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v) === 1,
            $kind === 'money' => Money::isDecimal($number, 2) && strlen(explode('.', $number)[0]) <= 13,
            $kind === 'rate' => Money::isDecimal($number, 2) && Money::compare($number, '100') <= 0,
            $kind === 'months' => preg_match('/^\d{1,3}$/', $number) === 1 && (int) $number <= 120,
            $kind === 'year' => preg_match('/^\d{4}$/', $number) === 1,
            $kind === 'currency' => in_array($v, FinanceService::CURRENCIES, true),
            default => false,
        };
        if (! $ok) {
            throw ValidationException::withMessages([$field => __('paper_entry.bad_value')]);
        }

        return match ($kind) {
            'money', 'rate', 'year' => $number,
            'months' => (int) $number,
            default => $v,
        };
    }

    /**
     * Inserts or updates the project's one record of a table (optionally one per `$match`, such as a deed kind) and returns the changed fields
     * for the audit trail. Call inside a transaction that holds the project row lock.
     *
     * @param  array<string, mixed>  $values
     * @param  array<string, string>  $match
     * @return list<array<string, mixed>>
     */
    public static function upsert(string $table, int $city, int $project, array $values, array $match = [], string $prefix = ''): array
    {
        $now = now('UTC')->format('Y-m-d H:i:s');
        $row = DB::table($table)->where('project_id', $project)->where($match)->whereNull('deleted_at')->orderByDesc('id')->first();
        $before = [];
        $after = [];
        foreach ($values as $field => $value) {
            $before[$prefix.$field] = $row->{$field} ?? null;
            $after[$prefix.$field] = $value;
        }
        if ($row !== null) {
            DB::table($table)->where('id', $row->id)->update($values + ['updated_at' => $now]);
        } elseif (array_filter($values, fn ($v) => $v !== null) !== []) {
            DB::table($table)->insert($values + $match + ['city_id' => $city, 'project_id' => $project, 'created_at' => $now, 'updated_at' => $now]);
        }

        return AccountAuditTrail::changes($before, $after) ?? [];
    }
}
