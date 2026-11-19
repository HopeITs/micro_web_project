<?php

namespace App\Services;

use App\Models\City;
use App\Models\Project;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Project numbers (approved unified schema v1.1, meta.project_number):
 * the original number stays; numbers are unique per city only; the form suggests the next number,
 * the user may type another one; checking and saving happen under a lock on the city's sequence row.
 */
final class ProjectNumberAllocator
{
    private const MAX = 2147483647; // the desktop MP_ID is a signed INT

    /** The number the form shows. Not reserved: another user may take it before this one saves. */
    public function suggest(int $cityId): int
    {
        $next = (int) DB::table('project_number_sequences')->where('city_id', $cityId)->value('next_number');
        $max = (int) Project::withoutGlobalScopes()->withTrashed()->where('city_id', $cityId)->max('project_number');

        return max($next, $max + 1, 1);
    }

    /**
     * Saves a project with the requested number (or the next free one when null) inside one transaction
     * that holds the city's sequence lock. $create receives the number and must insert the project.
     *
     * @template T
     *
     * @param  Closure(int): T  $create
     * @return T
     */
    public function claim(int $cityId, ?int $requested, Closure $create): mixed
    {
        return DB::transaction(function () use ($cityId, $requested, $create) {
            $sequence = DB::table('project_number_sequences')->where('city_id', $cityId)->lockForUpdate()->first();
            if ($sequence === null) {
                if (! City::whereKey($cityId)->exists()) {
                    throw new RuntimeException('Unknown city.');
                }
                DB::table('project_number_sequences')->insert(['city_id' => $cityId, 'next_number' => 1, 'created_at' => now(), 'updated_at' => now()]);
                $sequence = DB::table('project_number_sequences')->where('city_id', $cityId)->lockForUpdate()->first();
            }
            $max = (int) Project::withoutGlobalScopes()->withTrashed()->where('city_id', $cityId)->max('project_number');
            $next = max((int) $sequence->next_number, $max + 1, 1);
            $number = $requested ?? $next;

            if ($number < 1 || $number > self::MAX) {
                throw ValidationException::withMessages(['project_number' => __('projects.number_invalid')]);
            }
            $taken = Project::withoutGlobalScopes()->withTrashed()->where('city_id', $cityId)->where('project_number', $number)->exists();
            if ($taken) {
                throw ValidationException::withMessages(['project_number' => __('projects.number_taken', ['next' => $next])]);
            }

            $result = $create($number);
            DB::table('project_number_sequences')->where('city_id', $cityId)
                ->update(['next_number' => max($next, $number + 1), 'updated_at' => now()]);

            return $result;
        }, attempts: 5);
    }

    /** CITY-#### for display, e.g. DAM-2354. */
    public static function display(string $cityCode, int $number): string
    {
        return strtoupper($cityCode).'-'.$number;
    }

    /** Parses "DAM-2354", "dam2354" or "2354" into [cityCode|null, number] for search; null when not a number. */
    public static function parse(string $input): ?array
    {
        if (preg_match('/^\s*([A-Za-z]{3})?\s*-?\s*(\d{1,10})\s*$/', $input, $m) !== 1) {
            return null;
        }

        return [$m[1] !== '' ? strtoupper($m[1]) : null, (int) $m[2]];
    }
}
