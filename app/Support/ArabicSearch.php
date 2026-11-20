<?php

namespace App\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Facades\DB;

/**
 * Arabic letter folding for name search (owner request, 20 Nov 2026): أ إ آ ٱ → ا, ة → ه, ى → ي, and the
 * tatweel ـ dropped. The typed term and the stored column are folded the same way, so "احمد" finds
 * "أحمد" and "فاطمه" finds "فاطمة". Data is never changed; only the comparison is. Works on MySQL/MariaDB
 * and SQLite (both have REPLACE).
 */
final class ArabicSearch
{
    private const MAP = ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا', 'ة' => 'ه', 'ى' => 'ي', 'ـ' => ''];

    public static function fold(string $text): string
    {
        return strtr($text, self::MAP);
    }

    /** SQL expression of a column (or SQL fragment) folded like fold(). */
    public static function column(string $sql): Expression
    {
        foreach (self::MAP as $from => $to) {
            $sql = "REPLACE({$sql}, '{$from}', '{$to}')";
        }

        return DB::raw($sql);
    }

    /** LIKE pattern for a folded term, with LIKE wildcards in the term escaped. */
    public static function like(string $term): string
    {
        return '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], self::fold($term)).'%';
    }

    /** Adds "folded column LIKE folded term" as an OR condition. */
    public static function orWhereLike(Builder|\Illuminate\Database\Eloquent\Builder $query, string $column, string $term): void
    {
        $query->orWhere(self::column($column), 'like', self::like($term));
    }
}
