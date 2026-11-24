<?php

/*
 * Builds unified-schema-v1.json from unified-schema-v1.php (expands standard columns per scope,
 * validates foreign keys and source columns, and stamps a SHA-256 over the canonical JSON).
 * Usage: php build-schema.php [source-schema-inventory.json]
 */

$version = $argv[1] ?? 'v1';
$def = require __DIR__."/unified-schema-{$version}.php";
$inventoryFile = $argv[2] ?? __DIR__.'/../m0/schema-inventory-2026-11-19.json';
$inventory = json_decode(file_get_contents($inventoryFile), true);

// Union of source columns per table across cities (exported from the restored sources).
$sourceCols = [];
foreach (file(__DIR__.'/source-columns.tsv', FILE_IGNORE_NEW_LINES) as $line) {
    [$tbl, $c] = explode("\t", $line);
    $sourceCols[$tbl][$c] = true;
}

function parseColumn(string $name, string $spec): array
{
    $parts = explode('|', $spec);
    $col = ['name' => $name, 'type' => array_shift($parts), 'nullable' => false];
    foreach ($parts as $p) {
        if ($p === 'null') {
            $col['nullable'] = true;
        } elseif ($p === 'unique' || $p === 'index') {
            $col[$p] = true;
        } elseif (str_starts_with($p, 'src:')) {
            $col['source'] = substr($p, 4);
        } elseif (str_starts_with($p, 'tx:')) {
            $col['transform'] = substr($p, 3);
        } else {
            throw new RuntimeException("Unknown flag $p on $name");
        }
    }
    if (str_starts_with($col['type'], 'fk:')) {
        $col['references'] = substr($col['type'], 3);
        $col['type'] = 'fk';
    }

    return $col;
}

$standard = [
    'explicit' => [],
    'global' => ['before' => ['id' => 'id'], 'after' => ['created_at' => 'datetime|null', 'updated_at' => 'datetime|null']],
    'city' => ['before' => ['id' => 'id', 'city_id' => 'fk:cities', 'legacy_id' => 'int|null'], 'after' => ['created_at' => 'datetime|null', 'updated_at' => 'datetime|null', 'deleted_at' => 'datetime|null']],
    'lookup' => ['before' => ['id' => 'id', 'name_ar' => 'str:255', 'name_en' => 'str:255|null'], 'after' => ['sort_order' => 'int', 'is_active' => 'bool', 'created_at' => 'datetime|null', 'updated_at' => 'datetime|null']],
    'city_lookup' => ['before' => ['id' => 'id', 'city_id' => 'fk:cities', 'name_ar' => 'str:255', 'name_en' => 'str:255|null'], 'after' => ['sort_order' => 'int', 'is_active' => 'bool', 'created_at' => 'datetime|null', 'updated_at' => 'datetime|null']],
];

$out = ['meta' => $def['meta'], 'groups' => $def['groups'], 'tables' => [], 'not_imported' => $def['not_imported']];
$errors = [];
$names = array_keys($def['tables']);

foreach ($def['tables'] as $table => $t) {
    $scope = $t['scope'];
    $columns = [];
    if ($scope !== 'existing') {
        $all = ($standard[$scope]['before'] ?? []) + ($t['columns'] ?? []) + ($standard[$scope]['after'] ?? []);
        foreach ($all as $name => $spec) {
            $col = parseColumn($name, $spec);
            if (isset($col['references']) && ! in_array($col['references'], $names, true)) {
                $errors[] = "$table.$name references unknown table {$col['references']}";
            }
            if (isset($col['source'])) {
                [$srcTable, $srcCol] = str_contains($col['source'], '.') ? explode('.', $col['source'], 2) : [$t['source'] ?? '', $col['source']];
                $firstTable = preg_split('/[ +]/', $srcTable)[0];
                $GLOBALS['checked'] = ($GLOBALS['checked'] ?? 0) + (isset($sourceCols[$firstTable]) ? 1 : 0);
                if (isset($sourceCols[$firstTable]) && ! isset($sourceCols[$firstTable][$srcCol])) {
                    $errors[] = "$table.$name: source column $firstTable.$srcCol does not exist";
                }
            }
            $columns[] = $col;
        }
    }
    $uniques = $t['unique'] ?? [];
    if ($scope === 'city') {
        $uniques[] = ['city_id', 'legacy_id'];
    }
    if ($table === 'projects') {
        $uniques[] = ['city_id', 'project_number'];
    }
    if ($table === 'kobo_forms') {
        $uniques[] = ['city_id', 'form_uid'];
    }
    if ($table === 'lookup_legacy_map') {
        $uniques[] = ['city_id', 'lookup_table', 'legacy_id'];
    }
    $out['tables'][$table] = array_filter([
        'addendum' => $t['addendum'] ?? null,
        'group' => $t['group'], 'scope' => $scope, 'source' => $t['source'] ?? null, 'legacy_key' => $t['legacy_key'] ?? null,
        'note' => $t['note'] ?? null, 'unique' => $uniques ?: null, 'indexes' => $t['indexes'] ?? null, 'columns' => $columns ?: null,
    ], fn ($v) => $v !== null);
}

// Coverage: every source table is either imported somewhere or listed as not imported.
$covered = [];
foreach ($def['tables'] as $t) {
    if (! isset($t['source'])) {
        continue;
    }
    preg_match_all('/[a-z_]+(?=\.|\b)/', $t['source'], $m);
    foreach ($m[0] as $s) {
        $covered[$s] = true;
    }
}
foreach (array_keys($def['not_imported']) as $s) {
    $covered[$s] = true;
}
$missing = array_values(array_filter(array_keys($inventory['table_matrix']), fn ($s) => ! isset($covered[$s])));
if ($missing) {
    $errors[] = 'source tables not covered: '.implode(', ', $missing);
}

$out['counts'] = [
    'tables' => count($out['tables']),
    'by_scope' => array_count_values(array_column($out['tables'], 'scope')),
    'columns' => array_sum(array_map(fn ($t) => count($t['columns'] ?? []), $out['tables'])),
    'source_tables' => count($inventory['table_matrix']),
    'source_tables_not_imported' => count($def['not_imported']),
];
$out['validation_errors'] = $errors;

$canonical = json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$out = ['sha256' => hash('sha256', $canonical)] + $out;
file_put_contents(__DIR__."/unified-schema-{$version}.json", json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");

echo 'tables='.$out['counts']['tables'].' columns='.$out['counts']['columns'].' sha256='.$out['sha256']."\n";
echo json_encode($out['counts']['by_scope'])."\n";
echo 'source columns checked: '.($GLOBALS['checked'] ?? 0).'
';
echo $errors ? "ERRORS:\n - ".implode("\n - ", $errors)."\n" : "no validation errors\n";
