<?php

declare(strict_types=1);

namespace App\Services\Lookups;

use App\Support\UnifiedSchema;
use InvalidArgumentException;

/**
 * The lists staff maintain (M4.2), as the desktop's AddNew* screens did: each key names its unified table, whether it belongs to one
 * city (streets, priests, checklist members) or is shared by all five, and its extra fields beside name_ar / name_en.
 * A field of kind `parent` points at another list; `choice` takes one of fixed codes.
 */
final class LookupCatalog
{
    public const LISTS = [
        'streets' => ['table' => 'streets', 'city' => true, 'group' => 'people', 'desktop' => 'AddNewStreet'],
        'priests' => ['table' => 'priests', 'city' => true, 'group' => 'people', 'desktop' => 'AddNewPriest'],
        'education_specialties' => ['table' => 'education_specialties', 'city' => false, 'group' => 'people', 'desktop' => 'AddNewEducation',
            'fields' => ['education_type_id' => ['kind' => 'parent', 'list' => 'education_types', 'required' => true]]],
        'education_types' => ['table' => 'education_types', 'city' => false, 'group' => 'people', 'desktop' => 'AddNewEducation'],
        'occupations' => ['table' => 'occupations', 'city' => false, 'group' => 'people', 'desktop' => 'AddNewExperience'],
        'aid_sources' => ['table' => 'aid_sources', 'city' => false, 'group' => 'people', 'desktop' => 'AddNewAidSource'],
        'aid_types' => ['table' => 'aid_types', 'city' => false, 'group' => 'people', 'desktop' => 'AddNewAidSource'],
        'donors' => ['table' => 'donors', 'city' => false, 'group' => 'finance', 'desktop' => 'AddNewDonor',
            'fields' => ['abbreviation' => ['kind' => 'text', 'max' => 50, 'required' => true]]],
        'donor_groups' => ['table' => 'donor_groups', 'city' => false, 'group' => 'finance', 'desktop' => 'AddNewGroup',
            'fields' => ['donor_id' => ['kind' => 'parent', 'list' => 'donors', 'required' => true]]],
        'state_reasons' => ['table' => 'state_reasons', 'city' => false, 'group' => 'projects', 'desktop' => 'AddNewStateReasons'],
        'checklist_members' => ['table' => 'checklist_members', 'city' => true, 'group' => 'checklist', 'desktop' => 'AddNewChecklistMember',
            'fields' => ['checklist_position_id' => ['kind' => 'parent', 'list' => 'checklist_positions', 'required' => false]]],
        'checklist_positions' => ['table' => 'checklist_positions', 'city' => false, 'group' => 'checklist', 'desktop' => 'AddNewChecklistPosition',
            'fields' => ['type' => ['kind' => 'choice', 'options' => ['t', 'k', 'c'], 'required' => true]]],
    ];

    /** @return array{table: string, city: bool, group: string, desktop: string, fields: array<string, array>} */
    public static function get(string $key): array
    {
        $list = self::LISTS[$key] ?? throw new InvalidArgumentException('Unknown list.');

        return $list + ['fields' => []];
    }

    public static function exists(string $key): bool
    {
        return isset(self::LISTS[$key]);
    }

    /**
     * Columns of the unified schema that reference the list's table: a value used by any of them is hidden, never deleted.
     *
     * @return list<array{0: string, 1: string}> [table, column]
     */
    public static function references(string $key): array
    {
        $table = self::get($key)['table'];
        $refs = [];
        foreach (UnifiedSchema::tables() as $name => $definition) {
            foreach ($definition['columns'] as $column) {
                if (($column['references'] ?? null) === $table) {
                    $refs[] = [$definition['name'] ?? $name, $column['name']];
                }
            }
        }

        return $refs;
    }
}
