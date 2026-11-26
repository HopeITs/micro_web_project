<?php
require __DIR__ . '/lib.php';
$T = TGT; $C = cities();
$tot = ['src_cells' => 0, 'src_answered' => 0, 'tgt' => 0, 'cells' => 0];
foreach (SRC as $code => $db) {
    $cid = $C[$code];
    $vmap = [];
    foreach (q("select id, legacy_id, office_comments from $T.visits where city_id=$cid") as $r) $vmap[(int) $r['legacy_id']] = $r;
    $rev = [];
    foreach ($vmap as $l => $r) $rev[$r['id']] = $l;
    $tgt = [];
    foreach (q("select * from $T.visit_answers where city_id=$cid") as $r) {
        if (!isset($rev[$r['visit_id']])) { echo "$code target answer {$r['id']} points to visit outside city\n"; continue; }
        $tgt[$rev[$r['visit_id']] . ":{$r['form']}:{$r['question_number']}"][] = $r;
        $tot['tgt']++;
    }
    $states = []; $srcIds = [];
    $diff = [];
    $comments = [];
    foreach ([['visit', 'visit', 7, 'ID'], ['rehab', 'rehabvisit', 15, 'Visit_ID'], ['office_monitoring', 'office_monitoring', 6, 'Visit_ID']] as [$form, $st, $n, $vk]) {
        foreach (q("select * from $db.$st order by ID") as $s) {
            $vid = (int) $s[$vk];
            if ($form === 'office_monitoring') $comments[$vid][$s['ID']] = (string) $s['OtherComments'];
            for ($i = 1; $i <= $n; $i++) {
                $tx = $s["Ans$i"]; $v = $s["Ans{$i}_Value"];
                $tot['src_cells']++;
                $st_t = $tx === null ? 'null' : (trim($tx) === '' ? 'empty' : 'text');
                $st_v = $v === null ? 'null' : ((int) $v === -1 ? '-1' : ((int) $v === 0 ? '0' : 'other'));
                $k = "$vid:$form:$i";
                if (!isset($vmap[$vid])) { if ($st_t === 'text' || in_array($st_v, ['0', 'other'])) { $diff["answered source cell whose visit does not exist ($form)"][] = $s['ID']; } else $states["$form no-visit $st_t/$st_v"] = ($states["$form no-visit $st_t/$st_v"] ?? 0) + 1; continue; }
                // find a matching target row
                $found = null;
                foreach ($tgt[$k] ?? [] as $idx => $r) {
                    if (!isset($r['used']) && trim((string) $r['answer_text']) === trim((string) $tx) && ($r['answer_value'] === null ? $v === null || (int) $v === -1 : $v !== null && (int) $r['answer_value'] === (int) $v)) { $found = $idx; break; }
                }
                $key = "$form $st_t/$st_v " . ($found !== null ? 'PRESENT' : 'absent');
                $states[$key] = ($states[$key] ?? 0) + 1;
                if ($found !== null) {
                    $r = &$tgt[$k][$found]; $r['used'] = 1; $tot['cells'] += 2;
                    if ((string) $r['answer_text'] !== (string) $tx) $diff['text differs only by outer whitespace / empty vs null'][] = $r['id'];
                    if ($r['answer_value'] === null && $v !== null) $diff['value -1 stored as null'][] = $r['id'];
                    unset($r);
                } elseif ($st_t === 'text' || in_array($st_v, ['0', 'other'])) {
                    $diff["ANSWERED source cell missing/different in target ($form)"][] = "src{$s['ID']}q$i";
                }
            }
        }
    }
    foreach ($tgt as $k => $rs) foreach ($rs as $r) if (!isset($r['used'])) $diff['target answer with no matching source cell'][] = $r['id'];
    // office comments
    $cm = [];
    foreach ($vmap as $l => $r) {
        $src = array_values(array_filter($comments[$l] ?? [], fn($x) => trim($x) !== ''));
        $a = (string) $r['office_comments'];
        $tot['cells']++;
        if (!$src) { if (trim($a) !== '') $cm['target has comment, source none'][] = $r['id']; }
        else {
            $miss = array_filter($src, fn($x) => !str_contains($a, trim($x)));
            if ($miss) $cm[count($src) > 1 ? 'duplicate office rows: one comment LOST' : 'office comment lost/different'][] = $l;
            elseif (count($src) === 1 && $a !== $src[0]) $cm['office comment changed (whitespace/extra)'][] = $r['id'];
        }
    }
    foreach ($comments as $vid => $x) {
        if (!isset($vmap[$vid]) && array_filter($x, fn($c) => trim($c) !== '')) $cm['office comment for non-existing visit'][] = $vid;
        if (count($x) > 1) echo "$code duplicate office_monitoring visit legacy $vid rows ", implode(',', array_keys($x)), " nonempty comments=", count(array_filter($x, fn($c) => trim($c) !== '')), " distinct=", count(array_unique($x)), "\n";
    }
    echo "== $code\n"; ksort($states); foreach ($states as $k => $c) echo "  $k = $c\n";
    foreach ($diff + $cm as $k => $v) echo "  DIFF $k: ", count($v), " (", implode(',', array_slice($v, 0, 6)), ")\n";
}
echo json_encode($tot), "\n";
