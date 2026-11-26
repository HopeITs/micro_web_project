<?php

declare(strict_types=1);

namespace App\Support\TableTools;

use App\Support\ArabicSearch;
use Closure;
use Illuminate\Database\Query\Builder;

/**
 * The search box of the server-paginated lists: Arabic-aware (ArabicSearch folding, vowel marks dropped from the typed term, Arabic-Indic
 * digits read as 0-9), every typed word must be found (AND), each in any of the list's columns (OR). `%` and `_` are literal
 * (ESCAPE '!' behaves the same in MariaDB and SQLite). Column names come from code only, never from the request.
 */
final class TableSearch
{
    public const MAX_LENGTH = 100;

    public const MAX_WORDS = 6;

    private const DIGITS = ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9'];

    /** The typed text as it is compared. */
    public static function normalize(string $term): string
    {
        $term = mb_substr(trim($term), 0, self::MAX_LENGTH);
        $term = preg_replace('/[\x{064B}-\x{065F}\x{0670}]/u', '', $term) ?? '';

        return ArabicSearch::fold(strtr($term, self::DIGITS));
    }

    /** @return list<string> */
    public static function words(string $term): array
    {
        return array_slice(preg_split('/\s+/u', self::normalize($term), -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, self::MAX_WORDS);
    }

    /** The same rule for rows already in PHP: every word appears in the text. */
    public static function matches(string $text, string $term): bool
    {
        $hay = mb_strtolower(ArabicSearch::fold(strtr(preg_replace('/[\x{064B}-\x{065F}\x{0670}]/u', '', $text) ?? '', self::DIGITS)));
        foreach (self::words($term) as $word) {
            if (! str_contains($hay, mb_strtolower($word))) {
                return false;
            }
        }

        return true;
    }

    /**
     * Narrows the query to the rows holding every word of the term.
     *
     * @param  list<string>  $columns  searched columns (from code)
     * @param  Closure(Builder, string): void|null  $also  adds further OR conditions for one word (a related table, a number)
     */
    public static function apply(Builder $query, array $columns, string $term, ?Closure $also = null): void
    {
        foreach (self::words($term) as $word) {
            $query->where(function (Builder $any) use ($columns, $word, $also) {
                foreach ($columns as $column) {
                    self::orLike($any, $column, $word);
                }
                $also !== null && $also($any, $word);
            });
        }
    }

    /** OR "the folded column contains the (already normalized) word". */
    public static function orLike(Builder $query, string $column, string $word): void
    {
        $grammar = $query->getGrammar();
        $folded = (string) ArabicSearch::column($grammar->wrap($column))->getValue($grammar);
        $query->orWhereRaw($folded." like ? escape '!'", ['%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $word).'%']);
    }
}
