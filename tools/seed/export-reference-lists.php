<?php

/*
 * Exports the non-personal reference lists (lookups) from one restored city database into
 * database/seeders/data/reference-lists.json for the synthetic local data. Personal or
 * organisation-specific lists (donors, donor groups, streets, priests, checklist members) are not
 * exported: the synthetic seeder invents them.
 *
 * Usage: php tools/seed/export-reference-lists.php [database=m1src_dam]
 */

$root = dirname(__DIR__, 2);
$db = $argv[1] ?? 'm1src_dam';
$pdo = new PDO("mysql:host=127.0.0.1;dbname={$db};charset=utf8mb4", 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$approved = require $root.'/database/schema/approved.php';
$schema = json_decode(file_get_contents($root.'/'.$approved['file']), true);

$skip = ['donors', 'donor_groups', 'streets', 'priests', 'checklist_members'];
$nameColumn = ['parish' => ['Name_ar', 'Name_en'], 'level' => ['Level_Symbol', null], 'work' => ['W_Name', null], 'task' => ['T_Name', null],
    'state' => ['Name_ar', 'Name_en'], 'meresultranges' => ['Evaluation', 'Evaluation_en'], 'profession_section' => ['Name_Ar', null],
    'profession_question' => ['Text_Ar', null], 'profession_option' => ['Text_Ar', null], 'meprofitmarks' => [null, null], 'language' => ['L_Name', null]];
$keyColumn = ['work' => 'W_ID', 'level' => 'Level_ID', 'task' => 'T_ID', 'language' => 'L_ID'];
$sortColumn = ['checklist_opinion' => 'Index', 'profession_section' => 'Sort_Order', 'profession_question' => 'Sort_Order', 'profession_option' => 'Sort_Order'];

$out = [];
foreach ($schema['tables'] as $table => $t) {
    if (! in_array($t['scope'], ['lookup'], true) || in_array($table, $skip, true)) {
        continue;
    }
    $src = $t['source'];
    $cols = $pdo->query("SHOW COLUMNS FROM `{$src}`")->fetchAll(PDO::FETCH_COLUMN);
    [$nameAr, $nameEn] = $nameColumn[$src] ?? ['Name', in_array('Name_en', $cols, true) ? 'Name_en' : null];
    $key = $keyColumn[$src] ?? 'ID';
    $rows = [];
    foreach ($pdo->query("SELECT * FROM `{$src}` ORDER BY `{$key}`") as $r) {
        $row = ['legacy_id' => (int) $r[$key]];
        $row['name_ar'] = $nameAr ? trim((string) $r[$nameAr]) : trim($r['Min'].' – '.$r['Max']);
        $row['name_en'] = $nameEn && isset($r[$nameEn]) && trim((string) $r[$nameEn]) !== '' ? trim((string) $r[$nameEn]) : null;
        $row['sort_order'] = isset($sortColumn[$src]) ? (int) $r[$sortColumn[$src]] : 0;
        foreach ($t['columns'] as $c) {
            if (! isset($c['source']) || in_array($c['name'], ['name_ar', 'name_en'], true)) {
                continue;
            }
            $v = $r[$c['source']] ?? null;
            $row[$c['name']] = $v === null ? null : (is_numeric($v) ? $v + 0 : $v);
        }
        $rows[] = $row;
    }
    $out[$table] = $rows;
}
@mkdir($root.'/database/seeders/data', 0777, true);
file_put_contents($root.'/database/seeders/data/reference-lists.json', json_encode(['source' => "{$db} (restored 19 Nov 2026 backup)", 'lists' => $out], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n");
printf("exported %d lists, %d values\n", count($out), array_sum(array_map('count', $out)));
