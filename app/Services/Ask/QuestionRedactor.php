<?php

namespace App\Services\Ask;

/**
 * Local redaction of a question before it is stored (schema v1.5: `ai_queries.prompt` only after redaction): runs of ten or
 * more digits (national and mobile numbers, however they are spaced), text in quotation marks, and every word the parser could
 * not understand (what is left is most often a name) are replaced by a placeholder. What the parser understood stays.
 */
final class QuestionRedactor
{
    public const MAX_LENGTH = 300;

    /** @param  list<string>  $unknownWords */
    public function redact(string $question, array $unknownWords = []): string
    {
        $text = AskText::digits(mb_substr($question, 0, 1000));
        $text = preg_replace('/(?<!\d)(?:\d[\s\-.]*){10,}(?!\d)/u', '[#]', $text) ?? $text;
        $text = preg_replace('/"[^"]*"|«[^»]*»|“[^”]*”/u', '[…]', $text) ?? $text;
        $words = array_values(array_filter($unknownWords, fn ($w) => $w !== '' && preg_match('/^\d+$/', $w) !== 1));
        usort($words, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));
        foreach ($words as $word) {
            $text = preg_replace('/(?<![\p{L}\p{N}])'.preg_quote($word, '/').'(?![\p{L}\p{N}])/u', '[…]', $text) ?? $text;
        }
        // The last net: any run of ten digits that is still there.
        $text = preg_replace('/\d{10,}/', '[#]', $text) ?? $text;

        return mb_substr(trim(preg_replace('/\s+/u', ' ', $text) ?? $text), 0, self::MAX_LENGTH);
    }
}
