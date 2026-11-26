<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Services\Staff\AuditLogService;
use Carbon\CarbonImmutable;

/**
 * What the filter bar of the search and statistics pages offers: one chip per field (a date or amount field stands for its two ends),
 * grouped for the "add filter" menu, the chips always shown, and the ready periods. The matching itself is ProjectReportFilters.
 */
final class ReportFilterFields
{
    public const PRESETS = ['today', 'this_month', 'last_month', 'this_year', 'last_year'];

    /** key => [type: list|choice|date|amount, group] in the order of the bar */
    private const FIELDS = [
        'status' => ['list', 'project'], 'project_type' => ['list', 'project'], 'subtype' => ['list', 'project'], 'fund_type' => ['list', 'project'],
        'category' => ['list', 'project'], 'subcategory' => ['list', 'project'], 'career' => ['list', 'project'], 'donor' => ['list', 'project'], 'donor_group' => ['list', 'project'],
        'project_street' => ['list', 'project'], 'partnership' => ['choice', 'project'],
        'parish' => ['list', 'person'], 'sex' => ['list', 'person'], 'marital' => ['list', 'person'], 'age' => ['list', 'person'], 'home_street' => ['list', 'person'],
        'request' => ['date', 'period'], 'request_year' => ['list', 'period'], 'fund' => ['date', 'period'], 'fund_year' => ['list', 'period'], 'meeting' => ['date', 'period'],
        'amount' => ['amount', 'money'], 'usd' => ['amount', 'money'],
    ];

    private const MEETING = ['category', 'subcategory', 'career', 'meeting'];

    private const PINNED = ['project' => ['status', 'project_type', 'request'], 'meeting' => ['career', 'meeting']];

    /** @return array<string, array{0: string, 1: string}> the fields of a filter kind: project, meeting or none */
    public static function for(string $kind): array
    {
        return match ($kind) {
            'project' => array_diff_key(self::FIELDS, ['meeting' => true]),
            'meeting' => array_intersect_key(self::FIELDS, array_flip(self::MEETING)),
            default => [],
        };
    }

    /** @return list<string> */
    public static function pinned(string $kind): array
    {
        return self::PINNED[$kind] ?? [];
    }

    /** The filter keys a field stands for. @return list<string> */
    public static function keys(string $field): array
    {
        return in_array(self::FIELDS[$field][0] ?? '', ['date', 'amount'], true) ? [$field.'_from', $field.'_to'] : [$field];
    }

    /** The cleaned filters that a type of this kind honours (the others are dropped when the type changes). */
    public static function applicable(array $filters, string $kind): array
    {
        $keys = [];
        foreach (array_keys(self::for($kind)) as $field) {
            array_push($keys, ...self::keys($field));
        }
        $out = array_intersect_key($filters, array_flip($keys));
        $not = array_values(array_intersect((array) ($filters['not'] ?? []), array_keys($out)));
        if ($not !== []) {
            $out['not'] = $not;
        }

        return $out;
    }

    /** @return array{0: string, 1: string}|null first and last day of a ready period, counted in Damascus */
    public static function preset(string $preset, ?CarbonImmutable $today = null): ?array
    {
        $today ??= CarbonImmutable::today(AuditLogService::TIMEZONE);
        $range = match ($preset) {
            'today' => [$today, $today],
            'this_month' => [$today->startOfMonth(), $today->endOfMonth()],
            'last_month' => [$today->startOfMonth()->subMonth(), $today->startOfMonth()->subDay()],
            'this_year' => [$today->startOfYear(), $today->endOfYear()],
            'last_year' => [$today->startOfYear()->subYear(), $today->startOfYear()->subDay()],
            default => null,
        };

        return $range === null ? null : [$range[0]->toDateString(), $range[1]->toDateString()];
    }

    /** The ready period two dates stand for, if any. */
    public static function presetOf(string $from, string $to): ?string
    {
        foreach (self::PRESETS as $preset) {
            if (self::preset($preset) === [$from, $to]) {
                return $preset;
            }
        }

        return null;
    }
}
