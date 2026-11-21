<?php

namespace App\Support\Datasets;

/**
 * Splits a search box into terms: a comma (also the Arabic "،"), a semicolon or a new line separates terms
 * that are OR'ed, so a column pasted from Excel finds every row at once; the spaces inside one term stay
 * AND (every word must match), as before.
 */
final class SearchTerms
{
    public const MAX_LENGTH = 4000;

    public const MAX_TERMS = 200;

    /** @return list<string> */
    public static function split(string $text): array
    {
        $text = mb_substr($text, 0, self::MAX_LENGTH);
        $terms = [];
        foreach (preg_split('/[,،;\r\n\t]+/u', $text) ?: [] as $term) {
            $term = trim(preg_replace('/\s+/u', ' ', $term) ?? '');
            if ($term !== '' && ! in_array($term, $terms, true)) {
                $terms[] = mb_substr($term, 0, 100);
            }
        }

        return array_slice($terms, 0, self::MAX_TERMS);
    }

    /** @return list<string> */
    public static function words(string $term): array
    {
        return array_values(array_filter(preg_split('/\s+/u', trim($term)) ?: [], fn ($w) => $w !== ''));
    }
}
