<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Loads the non-personal reference lists (database/seeders/data/reference-lists.json, exported by
 * tools/seed/export-reference-lists.php). References between lists are given as legacy ids in the file
 * and resolved here. Only for local/synthetic databases: the real lists come from the M3 transfer tool.
 */
final class ReferenceListsSeeder extends Seeder
{
    public function run(): void
    {
        $schema = json_decode(file_get_contents(base_path('docs/planning/m1/unified-schema-v1.1.json')), true)['tables'];
        $lists = json_decode(file_get_contents(database_path('seeders/data/reference-lists.json')), true)['lists'];

        // Insert lists that others reference first.
        $refs = [];
        foreach ($lists as $table => $_) {
            $refs[$table] = [];
            foreach ($schema[$table]['columns'] as $c) {
                if (isset($c['references']) && isset($lists[$c['references']]) && $c['references'] !== $table) {
                    $refs[$table][$c['name']] = $c['references'];
                }
            }
        }
        $done = [];
        $ids = []; // table => legacy_id => new id
        while (count($done) < count($lists)) {
            foreach ($lists as $table => $rows) {
                if (isset($done[$table]) || array_diff(array_values($refs[$table]), array_keys($done))) {
                    continue;
                }
                if (DB::table($table)->exists()) {
                    $done[$table] = true;
                    continue;
                }
                foreach ($rows as $i => $row) {
                    $legacy = $row['legacy_id'];
                    unset($row['legacy_id']);
                    foreach ($refs[$table] as $col => $ref) {
                        $row[$col] = isset($row[$col]) ? ($ids[$ref][$row[$col]] ?? null) : null;
                    }
                    $row['sort_order'] = $row['sort_order'] ?: $i + 1;
                    $row['is_active'] = true;
                    $row['created_at'] = $row['updated_at'] = now();
                    $ids[$table][$legacy] = DB::table($table)->insertGetId($row);
                }
                $done[$table] = true;
            }
        }
        $this->command?->info(sprintf('reference lists: %d lists, %d values', count($lists), array_sum(array_map('count', $lists))));
    }
}
