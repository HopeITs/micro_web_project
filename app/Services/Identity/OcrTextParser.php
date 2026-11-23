<?php

declare(strict_types=1);

namespace App\Services\Identity;

/**
 * Turns the text Tesseract found on an identity document (its TSV output, with a confidence per word) into the fields of
 * {@see IdentityPrompt::schema()}. It looks for the card's printed labels (الاسم، الكنية، اسم الأب، اسم الأم، القيد، المنح…) and takes what follows
 * each; the national number and the dates are found by their shape. Names found this way are never "high": OCR of Arabic print needs review,
 * and the staff review and the local checks of {@see IdCardReader::normalize} are what make the result safe.
 */
final class OcrTextParser
{
    /** Labels (folded: see fold()) in the order they are tried, the longer ones first so "اسم الأب" is not taken for "الاسم". */
    private const LABELS = [
        'father_name' => [['اسم', 'الاب']],
        'mother_name' => [['اسم', 'الام']],
        'last_name' => [['الكنيه'], ['الشهره'], ['الكنية']],
        'first_name' => [['الاسم']],
        'registration_place' => [['القيد']],
        'id_issue_text' => [['المنح']],
        'sex' => [['الجنس']],
    ];

    /**
     * @return list<array{words: list<array{text: string, conf: float}>}> the lines in the order Tesseract found them
     */
    public static function lines(string $tsv): array
    {
        $lines = [];
        foreach (preg_split('/\R/u', $tsv) ?: [] as $row) {
            $cols = explode("\t", $row);
            if (count($cols) < 12 || ! ctype_digit($cols[0]) || (int) $cols[0] !== 5) {
                continue;
            }
            $text = trim($cols[11]);
            if ($text === '') {
                continue;
            }
            $key = $cols[2].'-'.$cols[3].'-'.$cols[4];
            $lines[$key]['words'][] = ['text' => $text, 'conf' => max(0.0, (float) $cols[10])];
        }

        return array_values($lines);
    }

    /**
     * @param  list<array{words: list<array{text: string, conf: float}>}>  $lines
     * @return array<string, mixed> the answer in the engine shape
     */
    public static function toInput(array $lines): array
    {
        $folded = array_map(fn (array $line) => array_map(fn (array $w) => self::fold($w['text']), $line['words']), $lines);
        $all = implode(' ', array_map(fn (array $words) => implode(' ', $words), $folded));
        $input = ['document_type' => self::documentType($all), 'notes' => 'Tesseract OCR: names and places need review.'];
        foreach (IdCardReader::FIELDS as $field) {
            $input[$field] = ['value' => null, 'confidence' => 'low'];
        }
        if (mb_strlen(preg_replace('/\s+/u', '', $all) ?? '') < 10) {
            return ['document_type' => 'not_an_identity_document'] + $input;
        }

        // Fields after a printed label.
        foreach ($lines as $i => $line) {
            foreach (self::LABELS as $field => $variants) {
                if ($input[$field]['value'] !== null) {
                    continue;
                }
                foreach ($variants as $label) {
                    $at = self::find($folded[$i], $label);
                    if ($at === null) {
                        continue;
                    }
                    $taken = self::after($lines[$i]['words'], $at + count($label));
                    // The value can be on the next line when the label stands alone.
                    if ($taken === null && isset($lines[$i + 1]) && ! self::hasLabel($folded[$i + 1])) {
                        $taken = $lines[$i + 1]['words'];
                    }
                    if ($taken !== null) {
                        $input[$field] = self::field($field, $taken);
                        break 2;
                    }
                }
            }
        }

        // The national number and the date of birth are found by their shape, preferring the line with their label.
        $number = self::shaped($lines, $folded, '/(?<!\d)(\d[\d ]{9,}\d)(?!\d)/u', fn (string $m) => preg_replace('/\D/', '', $m) ?? '', 11, ['الرقم', 'الوطني']);
        if ($number !== null) {
            $input['national_number'] = ['value' => $number['value'], 'confidence' => $number['conf'] >= 92 ? 'high' : 'medium'];
        }
        $date = self::shaped($lines, $folded, '#(\d{1,2}[/.\-]\d{1,2}[/.\-]\d{4}|\d{4}[/.\-]\d{1,2}[/.\-]\d{1,2})#u', fn (string $m) => $m, null, ['الولاده']);
        if ($date !== null) {
            $input['birth_date'] = ['value' => $date['value'], 'confidence' => $date['conf'] >= 92 ? 'high' : 'medium'];
        }

        return $input;
    }

    /** Arabic text without marks, with the letter variants of the print made equal, digits as ASCII (so labels and numbers can be matched). */
    public static function fold(string $text): string
    {
        $text = preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $text) ?? $text;
        $text = strtr($text, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ى' => 'ي', 'ة' => 'ه',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9']);

        return trim(rtrim($text, ':：'));
    }

    private static function documentType(string $folded): string
    {
        return match (true) {
            str_contains($folded, 'البطاقه الشخصيه') => 'national_id',
            str_contains($folded, 'جواز') || stripos($folded, 'passport') !== false => 'passport',
            str_contains($folded, 'العائله') || str_contains($folded, 'اخراج قيد') => 'family_book',
            default => 'other',
        };
    }

    /** @param list<string> $words @param list<string> $label @return ?int the index where the label starts */
    private static function find(array $words, array $label): ?int
    {
        for ($i = 0; $i + count($label) <= count($words); $i++) {
            if (array_slice($words, $i, count($label)) === $label) {
                return $i;
            }
        }

        return null;
    }

    /** @param list<string> $words */
    private static function hasLabel(array $words): bool
    {
        foreach (self::LABELS as $variants) {
            foreach ($variants as $label) {
                if (self::find($words, $label) !== null) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @param list<array{text: string, conf: float}> $words @return ?list<array{text: string, conf: float}> the words after an index, without separators, or null when none */
    private static function after(array $words, int $from): ?array
    {
        $rest = array_values(array_filter(array_slice($words, $from), fn (array $w) => preg_replace('/[\s:：\-_.|]+/u', '', $w['text']) !== ''));

        return $rest === [] ? null : $rest;
    }

    /** @param list<array{text: string, conf: float}> $words @return array{value: ?string, confidence: string} */
    private static function field(string $field, array $words): array
    {
        $value = trim(implode(' ', array_column($words, 'text')));
        $conf = array_sum(array_column($words, 'conf')) / max(1, count($words));
        if ($field === 'sex') {
            $folded = self::fold($value);
            $value = str_contains($folded, 'ذكر') ? 'ذكر' : (str_contains($folded, 'انثي') ? 'أنثى' : null);
        }

        // Names and places read from Arabic print are never "high": staff compare them with the image.
        return ['value' => $value === '' ? null : $value, 'confidence' => $conf >= 70 ? 'medium' : 'low'];
    }

    /**
     * The first value of a shape, preferring the line that holds the label words.
     *
     * @param  list<array{words: list<array{text: string, conf: float}>}>  $lines
     * @param  list<list<string>>  $folded
     * @param  list<string>  $label
     * @return ?array{value: string, conf: float}
     */
    private static function shaped(array $lines, array $folded, string $pattern, \Closure $clean, ?int $length, array $label): ?array
    {
        $candidates = [];
        foreach ($folded as $i => $words) {
            $text = implode(' ', $words);
            if (preg_match_all($pattern, $text, $found) < 1) {
                continue;
            }
            foreach ($found[1] as $match) {
                $value = $clean($match);
                if ($length !== null && strlen($value) !== $length) {
                    continue;
                }
                $digitWords = array_filter($lines[$i]['words'], fn (array $w) => preg_match('/\d/u', self::fold($w['text'])) === 1);
                $conf = $digitWords === [] ? 0.0 : array_sum(array_column($digitWords, 'conf')) / count($digitWords);
                $candidates[] = ['value' => $value, 'conf' => $conf, 'labelled' => self::find($words, $label) !== null || ($i > 0 && self::find($folded[$i - 1], $label) !== null)];
            }
        }
        usort($candidates, fn (array $a, array $b) => [(int) $b['labelled'], $b['conf']] <=> [(int) $a['labelled'], $a['conf']]);

        return $candidates[0] ?? null;
    }
}
