<?php
require __DIR__ . '/lib.php';
$T = TGT;
$S = spec()['tables'];
$C = cities();
function norm(?string $s): string
{
    $s = trim((string) $s);
    return strtr($s, ['ى' => 'ي', 'ة' => 'ه', 'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا']);
}
$map = [];
foreach (q("select * from $T.lookup_legacy_map") as $r) $map[$r['city_id']][$r['lookup_table']][(int) $r['legacy_id']] = $r;
$exc = [];
foreach (q("select e.*, r.city_id from $T.migration_exceptions e join $T.migration_runs r on r.id=e.migration_run_id") as $e) $exc[$e['city_id']][$e['source_table']][(string) $e['source_key']][] = $e;

$F = [];
function note($k, $city, $id) { global $F; $F[$k][$city] ??= [0, []]; $F[$k][$city][0]++; if (count($F[$k][$city][1]) < 10) $F[$k][$city][1][] = $id; }
$cells = 0; $rows = 0; $usedTarget = []; $usedExc = [];
$nameCols = ['Name', 'Name_ar', 'L_Name', 'T_Name', 'Priest_Name', 'W_Name', 'Text_Ar', 'Evaluation'];
$unmapped = [];
foreach ($S as $tn => $t) {
    if (!in_array($t['scope'], ['lookup', 'city_lookup'])) continue;
    $src = $t['source'];
    $trows = [];
    foreach (q("select * from $T.`$tn`") as $r) $trows[(int) $r['id']] = $r;
    foreach (SRC as $code => $db) {
        $cid = $C[$code];
        $st = tname($db, $src);
        $pk = null;
        foreach (cols($db, $st) as $c) if ($c['k'] === 'PRI') $pk = $c['n'];
        $srows = q("select * from `$db`.`$st`");
        $nameCol = null;
        foreach ($nameCols as $n) if ($nameCol === null) $nameCol = cname($db, $st, $n);
        $used = [$pk, $nameCol];
        $srcIds = [];
        foreach ($srows as $s) {
            $rows++;
            $lid = (int) $s[$pk];
            $srcIds[$lid] = 1;
            $m = $map[$cid][$tn][$lid] ?? null;
            if (!$m) { note("$tn: source row has NO map entry", $code, $lid); continue; }
            $tid = (int) $m['lookup_id'];
            $r = $trows[$tid] ?? null;
            if (!$r) { note("$tn: map points to missing target row", $code, $lid); continue; }
            $usedTarget[$tn][$tid][$code][] = $lid;
            if ($t['scope'] === 'city_lookup') { $cells++; if ((int) $r['city_id'] !== $cid) note("$tn: city lookup mapped to ANOTHER city's row", $code, $lid); }
            // name
            if ($tn === 'me_profit_marks') {
                note("info $tn: label sample shape " . preg_replace('/\d+(\.\d+)?/', 'N', $r['name_ar']), $code, $lid);
            } else {
                $cells++;
                $sn = (string) $s[$nameCol];
                $excName = array_filter($exc[$cid][$src][(string) $lid] ?? [], fn($e) => strcasecmp((string) $e['column_name'], $nameCol) === 0);
                if (norm($sn) !== norm($r['name_ar'])) {
                    if (trim($sn) === '' && $excName) { note("$tn: name differs, explained " . reset($excName)['kind'], $code, $lid); foreach ($excName as $e) $usedExc[$e['id']] = 1; }
                    else {
                        $a = preg_replace('/\s+/u', ' ', norm($sn)); $b = preg_replace('/\s+/u', ' ', norm($r['name_ar']));
                        note("$tn: NAME differs beyond approved normalisation" . ($a === $b ? ' (inner whitespace only)' : (mb_strtolower($a) === mb_strtolower($b) ? ' (case only)' : (trim($sn) === '' ? ' (source empty)' : ''))), $code, "$lid>$tid");
                    }
                } elseif (trim($sn) !== $r['name_ar']) {
                    note("info $tn: raw Arabic letter-form variant merged (allowed)", $code, "$lid>$tid");
                }
                if ((string) $m['legacy_name'] !== $sn && trim((string) $m['legacy_name']) !== trim($sn)) note("$tn: lookup_legacy_map.legacy_name differs from source name", $code, $lid);
            }
            // name_en
            $enCol = cname($db, $st, 'Name_en') ?? cname($db, $st, 'Evaluation_en');
            $cells++;
            $sen = $enCol ? trim((string) $s[$enCol]) : '';
            $ten = trim((string) $r['name_en']);
            if ($enCol) $used[] = $enCol;
            if ($sen !== $ten) {
                $ee = array_filter($exc[$cid][$src][(string) $lid] ?? [], fn($e) => $enCol && strcasecmp((string) $e['column_name'], $enCol) === 0);
                $cls = $sen === '' ? 'source empty, target has value (filled from another city)' : ($ten === '' ? 'source value LOST' : (mb_strtolower($sen) === mb_strtolower($ten) ? 'case only' : 'different'));
                if ($ee) { $e = reset($ee); $usedExc[$e['id']] = 1; note("$tn.name_en: $cls, recorded {$e['kind']}", $code, "$lid>$tid"); if (trim((string) $e['original_value']) !== $sen) note("$tn.name_en: exception original_value differs from source", $code, $lid); }
                else note("$tn.name_en: $cls, NO value_conflict record", $code, "$lid>$tid");
            }
            // attributes
            foreach ($t['columns'] as $c) {
                $sc = $c['source'] ?? null;
                if (!$sc) continue;
                $scol = cname($db, $st, $sc);
                if (!$scol) { note("$tn.{$c['name']}: source column $sc absent", $code, $lid); continue; }
                $used[] = $scol;
                $v = $s[$scol]; $a = $r[$c['name']]; $cells++;
                $tr = $c['transform'] ?? null;
                $bad = null;
                if ($tr === 'lookup') {
                    $ref = $c['references'];
                    $exp = $v === null ? null : ($map[$cid][$ref][(int) $v]['lookup_id'] ?? null);
                    if (($exp === null) !== ($a === null) || ($exp !== null && (int) $exp !== (int) $a)) $bad = 'parent differs';
                    elseif ($exp === null && $v !== null && (int) $v > 0) note("$tn.{$c['name']}: dangling parent in source, null in target", $code, $lid);
                } elseif ($tr === 'bool01') {
                    $x = in_array((string) $v, ['1', 'YES', 'Yes'], true) ? 1 : 0;
                    if ($a === null || (int) $a !== $x) $bad = 'bool differs';
                } elseif (str_starts_with($c['type'], 'dec') || str_starts_with($c['type'], 'int')) {
                    if ($v === null ? $a !== null : ($a === null || abs((float) $v - (float) $a) > 0.006)) $bad = 'number differs';
                } else {
                    if (trim((string) $v) !== trim((string) $a)) $bad = 'text differs';
                }
                if ($bad) {
                    $ee = array_filter($exc[$cid][$src][(string) $lid] ?? [], fn($e) => strcasecmp((string) $e['column_name'], $scol) === 0);
                    if ($ee) { $e = reset($ee); $usedExc[$e['id']] = 1; note("$tn.{$c['name']}: $bad, recorded {$e['kind']}", $code, "$lid>$tid"); }
                    else note("$tn.{$c['name']}: $bad, UNEXPLAINED", $code, "$lid>$tid");
                }
            }
            // visibility and order
            $actCol = cname($db, $st, 'isActive') ?? cname($db, $st, 'Is_Active');
            if ($actCol) $used[] = $actCol;
            $x = $actCol ? ($s[$actCol] === null ? 1 : (int) ((int) $s[$actCol] === 1)) : 1;
            $cells++;
            if ((int) $r['is_active'] !== $x) {
                $ee = array_filter($exc[$cid][$src][(string) $lid] ?? [], fn($e) => $e['kind'] === 'value_conflict');
                note("$tn.is_active: source " . ($actCol ? var_export($s[$actCol], true) : '(no column)') . " target {$r['is_active']}" . ($ee ? ' recorded' : ' NOT recorded'), $code, "$lid>$tid");
                foreach ($ee as $e) $usedExc[$e['id']] = 1;
            }
            $ordCol = cname($db, $st, 'Sort_Order') ?? cname($db, $st, 'Index');
            if ($ordCol) { $used[] = $ordCol; $cells++; if ((int) $r['sort_order'] !== (int) $s[$ordCol]) note("$tn.sort_order differs from source $ordCol", $code, "$lid>$tid"); }
        }
        foreach ($map[$cid][$tn] ?? [] as $lid => $m) if (!isset($srcIds[$lid])) note("$tn: map entry for a source id that does not exist", $code, $lid);
        if ($srows) foreach (cols($db, $st) as $c) if (!in_array($c['n'], $used)) {
            $n = 0; foreach ($srows as $s) if ($s[$c['n']] !== null && trim((string) $s[$c['n']]) !== '') $n++;
            if ($n) $unmapped["$src.{$c['n']}"][$code] = $n;
        }
    }
    foreach ($trows as $tid => $r) {
        if (!isset($usedTarget[$tn][$tid])) note("$tn: target row not mapped from any source row", 'ALL', $tid);
        // inactive across cities / duplicates
    }
    // possible missed merges: same normalised name + parents + attributes
    $seen = [];
    foreach ($trows as $tid => $r) {
        $key = [norm($r['name_ar']), $r['city_id'] ?? ''];
        foreach ($t['columns'] as $c) if (isset($c['source'])) $key[] = $r[$c['name']];
        $k = json_encode($key);
        if (isset($seen[$k])) note("info $tn: two target rows with identical name/parents/attributes", 'ALL', "{$seen[$k]}={$tid}");
        else $seen[$k] = $tid;
    }
}
// map rows for unknown tables / cities
foreach ($map as $cid => $m) foreach (array_keys($m) as $tn) if (!isset($S[$tn]) || !in_array($S[$tn]['scope'], ['lookup', 'city_lookup'])) note("map has unknown lookup_table $tn", (string) $cid, 0);
// value_conflict exceptions not matched to a difference
foreach ($exc as $cid => $x) foreach ($x as $tbl => $y) foreach ($y as $k => $es) foreach ($es as $e) if (in_array($e['kind'], ['value_conflict', 'name_empty']) && !isset($usedExc[$e['id']])) note("exception {$e['kind']} on $tbl.{$e['column_name']} matches no observed difference", (string) $cid, $k);

echo "lookup source rows=$rows cells=$cells\n";
foreach ($F as $k => $p) { echo $k, ' |'; foreach ($p as $c => [$n, $ids]) echo " $c=$n(", implode(',', $ids), ")"; echo "\n"; }
echo "== source columns without a target (non-empty counts)\n";
foreach ($unmapped as $k => $v) echo "$k ", json_encode($v), "\n";
