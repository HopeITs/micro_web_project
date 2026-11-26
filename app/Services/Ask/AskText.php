<?php

namespace App\Services\Ask;

use App\Support\ArabicSearch;

/**
 * Text helpers of the ask box (X1): digits, Arabic letter folding and a light stem, so the same word written
 * "الممولة", "ممولين" or "ممول" meets the same vocabulary entry. Everything is deterministic and local.
 */
final class AskText
{
    /** Arabic-Indic and Eastern (Persian) digits become 0-9; Arabic decimal and thousands marks become "." and nothing. */
    public static function digits(string $text): string
    {
        $text = strtr($text, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٫' => '.', '٬' => '', '＞' => '>', '＜' => '<', '≥' => '>=', '≤' => '<=', '؟' => ' ',
        ]);

        // 5,000 and 1,250,000 are one number; 1001,1002 (a list) is not.
        return preg_replace_callback('/(?<![\d,])\d{1,3}(?:,\d{3})+(?![\d,])/', fn (array $m) => str_replace(',', '', $m[0]), $text) ?? $text;
    }

    /** Lower case, no vowel marks or tatweel, Arabic letters folded like ArabicSearch (أ إ آ → ا, ة → ه, ى → ي). */
    public static function norm(string $word): string
    {
        $word = mb_strtolower(trim($word));
        $word = preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $word) ?? $word;
        $word = strtr($word, ['ی' => 'ي', 'ک' => 'ك', 'ؤ' => 'و', 'ئ' => 'ي']);

        return ArabicSearch::fold($word);
    }

    /** The comparison form of a word: folded, without the Arabic article, and without a plural or feminine ending. */
    public static function stem(string $word): string
    {
        $word = self::norm($word);
        if (preg_match('/\p{Arabic}/u', $word) !== 1) {
            return strlen($word) > 3 && str_ends_with($word, 's') && ! str_ends_with($word, 'ss') ? substr($word, 0, -1) : $word;
        }
        if (mb_strlen($word) > 4 && str_starts_with($word, 'ال')) {
            $word = mb_substr($word, 2);
        }
        if (mb_strlen($word) > 5) {
            foreach (['ات', 'ون', 'ين'] as $ending) {
                if (str_ends_with($word, $ending)) {
                    $word = mb_substr($word, 0, -2);
                    break;
                }
            }
        }
        if (mb_strlen($word) > 3 && str_ends_with($word, 'ه')) {
            $word = mb_substr($word, 0, -1);
        }

        return $word;
    }

    /**
     * What a word may also mean once a one-letter prefix is taken off: و (and), ب (in), ل (to), ف and ك, or للـ (to the).
     *
     * @return list<string> stems, the word's own first
     */
    public static function alternatives(string $word): array
    {
        $alts = [self::stem($word)];
        $plain = self::norm($word);
        if (preg_match('/^\p{Arabic}/u', $plain) === 1 && mb_strlen($plain) > 3) {
            if (str_starts_with($plain, 'لل') && mb_strlen($plain) > 4) {
                $alts[] = self::stem(mb_substr($plain, 2));
            }
            if (in_array(mb_substr($plain, 0, 1), ['و', 'ب', 'ل', 'ف', 'ك'], true)) {
                $alts[] = self::stem(mb_substr($plain, 1));
            }
        }

        return array_values(array_unique(array_filter($alts, fn ($a) => $a !== '')));
    }

    /**
     * Splits a question into words. Letters, digits and the few marks that carry meaning ("-" in DAM-12 and 2025-03-01,
     * "/" in 1/3/2025, "." in 1.5, "$") stay in a word; > < >= <= = stand alone; everything else separates words.
     *
     * @return list<string>
     */
    public static function tokenize(string $text): array
    {
        $text = self::digits($text);
        $text = preg_replace('/(>=|<=|=>|=<|[><=])/u', ' $1 ', $text) ?? $text;
        $text = str_replace(['=>', '=<'], ['>=', '<='], $text);
        $text = preg_replace('/(\$)/u', ' $1 ', $text) ?? $text;
        // A number glued to letters ("30سنة", "5000دولار", "ال30") is two words.
        $text = preg_replace('/(?<=\d)(?=\p{Arabic})|(?<=\p{Arabic})(?=\d)/u', ' ', $text) ?? $text;
        $text = preg_replace('/[^\p{L}\p{N}\-\/.$><=]+/u', ' ', $text) ?? $text;
        $words = [];
        foreach (preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            $word = preg_replace('/^[\-\/.\x{0640}]+|[\-\/.\x{0640}]+$/u', '', $word) ?? $word;
            if ($word !== '') {
                $words[] = $word;
            }
        }

        return $words;
    }
}
