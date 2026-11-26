<?php
require __DIR__ . '/lib.php';
$T = TGT; $C = cities();
$F = []; $n = 0;
function note($k, $c, $id) { global $F; $F[$k][$c] ??= [0, []]; $F[$k][$c][0]++; if (count($F[$k][$c][1]) < 10) $F[$k][$c][1][] = $id; }
$exc = [];
foreach (q("select e.*, r.city_id from $T.migration_exceptions e join $T.migration_runs r on r.id=e.migration_run_id where e.kind='project_chosen'") as $e) $exc[$e['city_id']][$e['source_table']][] = $e;
foreach (SRC as $code => $db) {
    $cid = $C[$code];
    $roles = []; foreach (q("select PersonType t, count(*) c from $db.person_microproject group by t") as $r) $roles[] = $r['c'];
    $pp = []; foreach (q("select pm.Person_ID p, pm.MicroProject_ID m, mp.MP_DateOfRequest d from $db.person_microproject pm join $db.microproject mp on mp.MP_ID=pm.MicroProject_ID") as $r) $pp[$r['p']][$r['m']] = $r['d'];
    $pm = []; foreach (q("select id, legacy_id from $T.projects where city_id=$cid") as $r) $pm[$r['id']] = (int) $r['legacy_id'];
    $kmr = []; foreach (q("select Legacy_Visit_ID v, MicroProject_ID m, Person_ID p from $db.kobo_monitoring_record where Legacy_Visit_ID is not null") as $r) $kmr[$r['v']] = $r;
    foreach (['checklist' => 'checklists', 'mevisit' => 'me_visits'] as $st => $tt) {
        $used = [];
        $src = []; foreach (q("select * from $db.$st") as $r) $src[$r['ID']] = $r;
        foreach (q("select id, legacy_id, project_id, person_id from $T.$tt where city_id=$cid") as $r) {
            $s = $src[$r['legacy_id']] ?? null;
            if (!$s) continue;
            $n++;
            $P = $pp[$s['Person_ID']] ?? [];
            $a = $r['project_id'] === null ? null : ($pm[$r['project_id']] ?? 'OTHERCITY');
            if ($a === 'OTHERCITY') { note("$tt project from another city", $code, $r['id']); continue; }
            $ex = null;
            foreach ($exc[$cid][$st] ?? [] as $i => $e) if ((string) $e['source_key'] === (string) $r['legacy_id']) { $ex = $e; $used[$e['id']] = 1; }
            if ($st === 'mevisit' && isset($kmr[$s['ID']])) {
                $k = $kmr[$s['ID']];
                if ($k['p'] !== null && (int) $k['p'] !== (int) $s['Person_ID']) note("$tt kobo record person differs from visit person", $code, $r['id']);
                if ($k['m'] !== null) {
                    if ($a !== (int) $k['m']) note("$tt project differs from kobo_monitoring_record", $code, $r['id']);
                    else { note("info $tt from kobo record" . (isset($P[$k['m']]) ? '' : ' (person is NOT on that project)'), $code, $r['id']); }
                    continue;
                }
            }
            if (count($P) === 1) {
                if ($a !== (int) array_key_first($P)) note("$tt only-project rule violated" . ($a === null ? ' (null)' : ''), $code, $r['id']);
                else note("info $tt only project", $code, $r['id']);
                if ($ex) note("$tt exception project_chosen though person has one project", $code, $r['id']);
            } elseif (count($P) === 0) {
                if ($a !== null) note("$tt person has no project but target set", $code, $r['id']);
                else note("$tt person has NO project; project_id null; " . ($ex ? 'recorded' : 'no exception'), $code, $r['id']);
            } else {
                if ($a === null) { note("$tt several projects, project_id null" . ($ex ? ' recorded' : ' no exception'), $code, $r['id']); continue; }
                if (!isset($P[$a])) note("$tt chosen project is not one of the person's projects", $code, $r['id']);
                if (!$ex) note("$tt several projects, chosen without review record", $code, $r['id']);
                else { if (!str_contains($ex['resolution'], "project ") ) note('odd resolution', $code, $r['id']);
                    preg_match('/project (\d+) chosen/', $ex['resolution'], $m); if (($m[1] ?? null) != $a && ($m[1] ?? null) != $r['project_id']) note("$tt exception names a different project than stored", $code, $r['id']); }
                // check closest-before rule
                if ($st === 'checklist') { $dates = []; foreach (['HVisit_Date', 'PVisit_Date', 'ProTest_Date', 'ProTrain_BDate', 'ProTrain_EDate', 'MngTrain_Date', 'TeamDate', 'CommityDate', 'RejectMsgDate'] as $c) if ($s[$c] && $s[$c] > '1900' && $s[$c] < '2100') $dates[] = $s[$c]; }
                else $dates = ($s['Date'] && $s['Date'] > '1900') ? [$s['Date']] : [];
                $pickFor = function ($ref) use ($P) { $best = null; foreach ($P as $mid => $d) if ($d && $d <= $ref && ($best === null || $d > $P[$best] || ($d === $P[$best] && $mid > $best))) $best = $mid; return $best; };
                if (!$dates) note("info $tt multi-project with no source dates (rule cannot apply)", $code, $r['id']);
                else { $e1 = $pickFor(min($dates)); $e2 = $pickFor(max($dates));
                    if ($a === $e1 && $a === $e2) note("info $tt multi: matches closest-before (earliest and latest date)", $code, $r['id']);
                    elseif ($a === $e1) note("info $tt multi: matches closest-before earliest date only", $code, $r['id']);
                    elseif ($a === $e2) note("info $tt multi: matches closest-before latest date only", $code, $r['id']);
                    else note("$tt multi: does NOT match closest-request-date-before rule" . ($e1 === null && $e2 === null ? ' (no project requested before the dates)' : ''), $code, $r['id']); }
            }
        }
        foreach ($exc[$cid][$st] ?? [] as $e) if (!isset($used[$e['id']])) note("$tt unmatched project_chosen exception", $code, $e['source_key']);
    }
}
echo "rows=$n\n";
foreach ($F as $k => $p) { echo $k, ' |'; foreach ($p as $c => [$x, $ids]) echo " $c=$x(", implode(',', $ids), ")"; echo "\n"; }
