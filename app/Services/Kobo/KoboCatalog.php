<?php

declare(strict_types=1);

namespace App\Services\Kobo;

/**
 * The Kobo form definitions (M4.14): resources/kobo/question-catalog.json is the desktop's KoboQuestionCatalog.json (4.0.44), extracted
 * from the 46 XLSForm definitions of the five governorates: each form's ordered fields with path, Arabic label, type, section and
 * choice codes/labels. It turns a stored submission (`kobo_submissions.raw_json`, keyed by field path) into readable questions and
 * answers. No personal data is in it.
 */
final class KoboCatalog
{
    /** Groups the desktop excludes from the result reports: basic information, companions/other teams, vehicle identification. */
    public const EXCLUDED_GROUPS = ['group_mo5jr41', 'group_vv6uu79', 'group_vz9zl11'];

    private static ?array $forms = null;

    /** @return array<string, array{region: string, fields: list<array{path: string, label: string, type: string, repeat: bool, section: ?string, context: ?string, choices: list<array{code: string, label: string}>}>}> */
    public static function forms(): array
    {
        return self::$forms ??= json_decode((string) file_get_contents(resource_path('kobo/question-catalog.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @return array<string, array> the form's fields by path, or [] for an unknown form */
    public static function fields(string $formUid): array
    {
        $out = [];
        foreach (self::forms()[$formUid]['fields'] ?? [] as $field) {
            $out[$field['path']] = $field;
        }

        return $out;
    }

    /** "select_one", "select_multiple", "text", "integer", "date", "geopoint". */
    public static function kind(array $field): string
    {
        return explode(' ', (string) $field['type'])[0];
    }

    /** A stored answer as people read it: choice codes become their labels (unknown codes stay as given). */
    public static function display(array $field, mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        if (is_array($value)) {
            return implode(' | ', array_map(fn ($v) => self::display($field, $v), $value));
        }
        $kind = self::kind($field);
        if ($kind === 'select_one' || $kind === 'select_multiple') {
            $labels = array_column($field['choices'] ?? [], 'label', 'code');
            $codes = $kind === 'select_multiple' ? preg_split('/\s+/', trim((string) $value)) : [(string) $value];

            return implode('، ', array_map(fn ($c) => $labels[$c] ?? $c, $codes));
        }

        return (string) $value;
    }

    /**
     * A submission's answers by section, in the form's order. Fields the catalog does not know are listed last under "other" with their
     * path, so nothing stored is hidden. Kobo's own metadata (`_id`, `formhub/uuid`, `meta/…`, `__version__`…) is left out.
     *
     * @return list<array{section: string, items: list<array{label: string, value: string, path: string}>}>
     */
    public static function readable(string $formUid, array $raw): array
    {
        $fields = self::fields($formUid);
        $sections = [];
        foreach ($fields as $path => $field) {
            if (! array_key_exists($path, $raw)) {
                continue;
            }
            $value = self::display($field, $raw[$path]);
            if ($value === '') {
                continue;
            }
            $sections[$field['section'] ?: '—'][] = ['label' => (string) $field['label'], 'value' => $value, 'path' => $path];
        }
        foreach ($raw as $path => $value) {
            if (isset($fields[$path]) || self::isMeta((string) $path) || $value === null || $value === '' || is_array($value) && $value === []) {
                continue;
            }
            $sections["\u{2026}"][] = ['label' => (string) $path, 'value' => is_scalar($value) ? (string) $value : json_encode($value, JSON_UNESCAPED_UNICODE), 'path' => (string) $path];
        }

        return array_map(fn ($name, $items) => ['section' => $name, 'items' => $items], array_keys($sections), array_values($sections));
    }

    public static function isMeta(string $path): bool
    {
        return str_starts_with($path, '_') || str_starts_with($path, 'meta/') || str_starts_with($path, 'formhub/') || in_array($path, ['start', 'end', 'today', 'deviceid', '__version__'], true);
    }

    /** Whether a field is part of the result reports (not identification, companions, vehicle, visit number or date). */
    public static function reported(array $field): bool
    {
        $group = explode('/', (string) $field['path'])[0];
        if (in_array($group, self::EXCLUDED_GROUPS, true)) {
            return false;
        }
        $label = self::normalize((string) $field['label']);

        return ! preg_match('/^(رقم الزيار|تاريخ الزيار|الزيار(ه|ة) رقم)/u', $label) && ! in_array(self::kind($field), ['geopoint', 'date'], true);
    }

    /** Question wording as compared across forms: no vowel marks, tatweel, direction marks, extra spaces or trailing punctuation. */
    public static function normalize(string $text): string
    {
        $text = preg_replace('/[\x{064B}-\x{0652}\x{0670}\x{0640}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', trim($text)) ?? $text;

        return rtrim($text, ' :؟?.،,');
    }
}
