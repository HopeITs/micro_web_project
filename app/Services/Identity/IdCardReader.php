<?php

declare(strict_types=1);

namespace App\Services\Identity;

use App\Services\Identity\Engines\EngineFactory;
use App\Services\Identity\Engines\IdentityEngine;

/**
 * Reads the printed fields of an identity document (a Syrian ID card, a family book page, a passport, a residence permit) from its scan
 * with a cloud vision model, then checks every value locally. It never writes anything: the review page shows each value beside the
 * image and staff approve before a person is changed.
 *
 * Accuracy: the model must answer through a fixed tool schema (no free text), digits and dates are normalised and validated here
 * (an 11-digit national number, a real date), and the document is read `passes` times independently; a field that differs between the
 * readings is marked doubtful with the other candidates kept for the reviewer.
 */
final class IdCardReader
{
    /** The person fields that can be filled, in the order the review shows them. */
    public const FIELDS = ['first_name', 'last_name', 'father_name', 'mother_name', 'birth_date', 'registration_place', 'national_number', 'id_issue_text', 'sex', 'id_document_number'];

    public const DOCUMENT_TYPES = ['national_id', 'passport', 'residence_permit', 'family_book', 'other', 'not_an_identity_document'];

    private const RANK = ['low' => 1, 'medium' => 2, 'high' => 3];

    /** The engine that reads (config identity_reader.driver): claude (cloud), ollama or tesseract (both on this side). */
    public function engine(): IdentityEngine
    {
        return app(EngineFactory::class)->make();
    }

    public function enabled(): bool
    {
        return $this->engine()->enabled();
    }

    /** True when the scan is read on this side and never leaves it. */
    public function isLocal(): bool
    {
        return $this->engine()->local();
    }

    /**
     * @return array{type: string, fields: array<string, array{value: ?string, confidence: string, alternatives: list<string>, warning: ?string}>, notes: string, input_tokens: int, output_tokens: int, passes: int}
     */
    public function read(string $bytes, string $mime): array
    {
        $engine = $this->engine();
        $engine->enabled() || throw new IdentityReadException('disabled');
        $reads = [];
        $tokens = ['in' => 0, 'out' => 0];
        for ($pass = 1; $pass <= $engine->passes(); $pass++) {
            $response = $engine->extract($bytes, $mime, $pass);
            $reads[] = self::normalize($response['input']);
            $tokens['in'] += $response['input_tokens'];
            $tokens['out'] += $response['output_tokens'];
        }

        return self::merge($reads) + ['input_tokens' => $tokens['in'], 'output_tokens' => $tokens['out'], 'passes' => count($reads)];
    }

    // ---------------------------------------------------------------- local checks

    /**
     * @param  array<string, mixed>  $input
     * @return array{type: string, fields: array<string, array{value: ?string, confidence: string, alternatives: list<string>, warning: ?string}>, notes: string}
     */
    public static function normalize(array $input): array
    {
        $type = in_array($input['document_type'] ?? '', self::DOCUMENT_TYPES, true) ? (string) $input['document_type'] : 'other';
        $fields = [];
        foreach (self::FIELDS as $name) {
            $raw = $input[$name] ?? null;
            $value = is_array($raw) && is_scalar($raw['value'] ?? null) ? (string) $raw['value'] : null;
            $confidence = is_array($raw) && isset(self::RANK[$raw['confidence'] ?? '']) ? (string) $raw['confidence'] : 'low';
            [$value, $confidence, $warning] = self::clean($name, $value, $confidence, $type);
            $fields[$name] = ['value' => $value, 'confidence' => $type === 'not_an_identity_document' ? 'low' : $confidence, 'alternatives' => [], 'warning' => $warning];
        }

        return ['type' => $type, 'fields' => $fields, 'notes' => mb_substr(trim((string) ($input['notes'] ?? '')), 0, 500)];
    }

    /** @return array{0: ?string, 1: string, 2: ?string} the cleaned value, its confidence (lowered when a check fails) and the warning */
    private static function clean(string $field, ?string $value, string $confidence, string $type): array
    {
        $value = $value === null ? null : trim(preg_replace('/\s+/u', ' ', $value) ?? '');
        if ($value === null || $value === '') {
            return [null, $confidence, null];
        }
        $digits = ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9', '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9'];
        switch ($field) {
            case 'national_number':
                $number = preg_replace('/\D/', '', strtr($value, $digits)) ?? '';
                if ($number === '') {
                    return [null, $confidence, null];
                }
                // A Syrian national number has 11 digits; anything else is a misread (or a number of another kind).
                $expected = in_array($type, ['national_id', 'family_book'], true);

                return [$number, $expected && strlen($number) !== 11 ? 'low' : $confidence, $expected && strlen($number) !== 11 ? 'national_number_length' : null];
            case 'birth_date':
                $date = strtr($value, $digits);
                if (preg_match('#^(\d{4})-(\d{1,2})-(\d{1,2})$#', $date, $m) !== 1 && preg_match('#^(\d{1,2})[/.\-](\d{1,2})[/.\-](\d{4})$#', $date, $d) === 1) {
                    $m = [$date, $d[3], $d[2], $d[1]];
                } elseif (preg_match('#^(\d{4})-(\d{1,2})-(\d{1,2})$#', $date, $m) !== 1) {
                    return [null, 'low', 'birth_date_format'];
                }
                if (! checkdate((int) $m[2], (int) $m[3], (int) $m[1]) || (int) $m[1] < 1900 || (int) $m[1] > (int) date('Y')) {
                    return [null, 'low', 'birth_date_invalid'];
                }

                return [sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]), $confidence, null];
            case 'sex':
                $sex = mb_strtolower($value);

                return [in_array($sex, ['male', 'ذكر', 'm'], true) ? 'male' : (in_array($sex, ['female', 'أنثى', 'انثى', 'f'], true) ? 'female' : null), $confidence, null];
            case 'id_document_number':
                $number = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', strtr($value, $digits)) ?? '');

                return [$number === '' ? null : mb_substr($number, 0, 60), $confidence, null];
            case 'registration_place':
            case 'id_issue_text':
                return [mb_substr($value, 0, 100), self::scriptMismatch($value, $type) ? 'low' : $confidence, self::scriptMismatch($value, $type) ? 'script_mismatch' : null];
            default:
                // Names: letters, marks, spaces, hyphens and apostrophes only (no digits or stray symbols from the print).
                $name = trim(preg_replace('/\s+/u', ' ', preg_replace('/[^\p{L}\p{M}\s\'\-]+/u', '', $value) ?? '') ?? '');

                $warning = $name !== $value ? 'name_symbols' : (self::scriptMismatch($name, $type) ? 'script_mismatch' : null);

                return [$name === '' ? null : mb_substr($name, 0, 100), $warning === null ? $confidence : 'low', $warning];
        }
    }

    /**
     * Latin letters where the print is Arabic: mixed with Arabic letters in one value, or in any value of a Syrian ID card or family book. A model can
     * mix scripts ("زيidan") with full confidence and repeat it on a second reading, so this is checked here.
     */
    private static function scriptMismatch(string $value, string $type): bool
    {
        $latin = preg_match('/\p{Latin}/u', $value) === 1;

        return $latin && (preg_match('/\p{Arabic}/u', $value) === 1 || in_array($type, ['national_id', 'family_book'], true));
    }

    /**
     * Combines independent readings: equal values keep the lowest confidence of the readings; different values become doubtful ("low") with the
     * majority value (else the first) as the proposal and the others as alternatives.
     *
     * @param  list<array{type: string, fields: array<string, array{value: ?string, confidence: string, alternatives: list<string>, warning: ?string}>, notes: string}>  $reads
     * @return array{type: string, fields: array<string, array{value: ?string, confidence: string, alternatives: list<string>, warning: ?string}>, notes: string}
     */
    public static function merge(array $reads): array
    {
        $types = array_count_values(array_column($reads, 'type'));
        arsort($types);
        $fields = [];
        foreach (self::FIELDS as $name) {
            $items = array_column(array_column($reads, 'fields'), $name);
            $values = array_column($items, 'value');
            $lowest = array_reduce(array_column($items, 'confidence'), fn (?string $carry, string $c) => $carry === null || self::RANK[$c] < self::RANK[$carry] ? $c : $carry);
            $warning = collect($items)->pluck('warning')->filter()->first();
            if (count(array_unique(array_map(fn ($v) => $v ?? "\0", $values))) === 1) {
                $fields[$name] = ['value' => $values[0], 'confidence' => (string) $lowest, 'alternatives' => [], 'warning' => $warning];

                continue;
            }
            $counts = array_count_values(array_filter($values, fn ($v) => $v !== null));
            arsort($counts);
            $chosen = $counts === [] ? null : (string) array_key_first($counts);
            $fields[$name] = ['value' => $chosen, 'confidence' => 'low', 'alternatives' => array_values(array_diff(array_map('strval', array_keys($counts)), [(string) $chosen])), 'warning' => $warning ?? 'readings_differ'];
        }

        return ['type' => (string) array_key_first($types), 'fields' => $fields, 'notes' => (string) (collect($reads)->pluck('notes')->filter()->first() ?? '')];
    }
}
