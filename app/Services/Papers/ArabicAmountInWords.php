<?php

declare(strict_types=1);

namespace App\Services\Papers;

/**
 * A whole amount in Arabic words for the legal papers (promissory note, deeds, contracts), e.g. 256000000 →
 * "مئتان وستة وخمسون مليوناً". Masculine counting forms; numbers up to 999 trillion. The currency is added by the caller.
 */
final class ArabicAmountInWords
{
    private const ONES = ['', 'واحد', 'اثنان', 'ثلاثة', 'أربعة', 'خمسة', 'ستة', 'سبعة', 'ثمانية', 'تسعة', 'عشرة', 'أحد عشر', 'اثنا عشر', 'ثلاثة عشر', 'أربعة عشر', 'خمسة عشر', 'ستة عشر', 'سبعة عشر', 'ثمانية عشر', 'تسعة عشر'];

    private const TENS = [2 => 'عشرون', 3 => 'ثلاثون', 4 => 'أربعون', 5 => 'خمسون', 6 => 'ستون', 7 => 'سبعون', 8 => 'ثمانون', 9 => 'تسعون'];

    private const HUNDREDS = [1 => 'مئة', 2 => 'مئتان', 3 => 'ثلاثمئة', 4 => 'أربعمئة', 5 => 'خمسمئة', 6 => 'ستمئة', 7 => 'سبعمئة', 8 => 'ثمانمئة', 9 => 'تسعمئة'];

    /** Scale words: [singular, dual, plural (3-10), accusative singular (11-99 and beyond)]. */
    private const SCALES = [
        1 => ['ألف', 'ألفان', 'آلاف', 'ألفاً'],
        2 => ['مليون', 'مليونان', 'ملايين', 'مليوناً'],
        3 => ['مليار', 'ملياران', 'مليارات', 'ملياراً'],
        4 => ['تريليون', 'تريليونان', 'تريليونات', 'تريليوناً'],
    ];

    public static function convert(int|string $amount): string
    {
        $amount = ltrim(preg_replace('/\D/', '', (string) $amount) ?? '', '0');
        if ($amount === '') {
            return 'صفر';
        }
        if (strlen($amount) > 15) {
            return (string) $amount;
        }
        $groups = array_map('intval', array_reverse(str_split(str_pad($amount, (int) (ceil(strlen($amount) / 3) * 3), '0', STR_PAD_LEFT), 3)));
        $parts = [];
        for ($scale = count($groups) - 1; $scale >= 0; $scale--) {
            $n = $groups[$scale];
            if ($n === 0) {
                continue;
            }
            if ($scale === 0) {
                $parts[] = self::below1000($n);

                continue;
            }
            $words = self::SCALES[$scale];
            $rest = $n % 100;
            $parts[] = match (true) {
                $n === 1 => $words[0],
                $n === 2 => $words[1],
                $n <= 10 => self::below1000($n).' '.$words[2],
                // A round hundred counts the singular: "مئة ألف", "مئتا ألف", "ثلاثمئة ألف".
                $rest === 0 => ($n === 200 ? 'مئتا' : self::HUNDREDS[intdiv($n, 100)]).' '.$words[0],
                // Hundreds plus 1 or 2 keep the singular, plus 3–10 the plural, plus 11–99 the accusative singular.
                $rest <= 2 => self::below1000($n).' '.$words[0],
                $rest <= 10 => self::below1000($n).' '.$words[2],
                default => self::below1000($n).' '.$words[3],
            };
        }

        return implode(' و', $parts);
    }

    /** 1–999 as words, hundreds then the rest joined by "و". */
    private static function below1000(int $n): string
    {
        $parts = [];
        if ($n >= 100) {
            $parts[] = self::HUNDREDS[intdiv($n, 100)];
            $n %= 100;
        }
        if ($n > 0) {
            if ($n < 20) {
                $parts[] = self::ONES[$n];
            } else {
                $ones = $n % 10;
                $parts[] = ($ones > 0 ? self::ONES[$ones].' و' : '').self::TENS[intdiv($n, 10)];
            }
        }

        return implode(' و', $parts);
    }
}
