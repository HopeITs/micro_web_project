<?php
require __DIR__ . '/lib.php';
$T = TGT; $S = spec()['tables'];
$checks = 0; $refs = 0;
foreach ($S as $tn => $t) {
    $hasCity = (bool) array_filter($t['columns'], fn($c) => $c['name'] === 'city_id');
    foreach ($t['columns'] as $c) {
        $ref = $c['references'] ?? null;
        if (!$ref || $c['name'] === 'city_id') continue;
        $col = $c['name'];
        $total = q("select count(*) c from $T.`$tn` where `$col` is not null")[0]['c'];
        if (!$total) continue;
        $checks++; $refs += $total;
        $orph = q("select count(*) c from $T.`$tn` x left join $T.`$ref` r on r.id=x.`$col` where x.`$col` is not null and r.id is null")[0]['c'];
        if ($orph) echo "ORPHAN $tn.$col -> $ref: $orph\n";
        $refCity = (bool) array_filter($S[$ref]['columns'], fn($c) => $c['name'] === 'city_id');
        if ($hasCity && $refCity) {
            $x = q("select count(*) c, min(x.id) m from $T.`$tn` x join $T.`$ref` r on r.id=x.`$col` where not (x.city_id <=> r.city_id)")[0];
            if ($x['c']) echo "CROSS-CITY $tn.$col -> $ref: {$x['c']} (first {$x['m']})\n";
        }
        if ($hasCity && $ref === 'web_accounts') {
            $x = q("select count(*) c, min(x.id) m from $T.`$tn` x where x.`$col` is not null and not exists(select 1 from $T.account_legacy_links l where l.account_id=x.`$col` and l.city_id=x.city_id) and not exists(select 1 from $T.account_city_roles r where r.account_id=x.`$col` and cast(r.decision_locator as char)='seed:test-role-accounts')")[0];
            if ($x['c']) echo "ACCOUNT of another city $tn.$col: {$x['c']} (first {$x['m']})\n";
        }
    }
    if ($hasCity && ($t['scope'] ?? '') === 'city') {
        $x = q("select count(*) c from $T.`$tn` where city_id not in (select id from $T.cities)")[0]['c'];
        if ($x) echo "$tn bad city_id: $x\n";
        if (array_filter($t['columns'], fn($c) => $c['name'] === 'deleted_at')) { $x = q("select count(*) c from $T.`$tn` where deleted_at is not null")[0]['c']; if ($x) echo "$tn soft-deleted rows: $x\n"; }
    }
}
echo "FK columns with data checked: $checks, references: $refs\n";
// deeper links: project-person consistency
foreach (['person_work_experiences', 'person_aid_sources'] as $t) {
    foreach (q("select x.city_id, count(*) c from $T.$t x where x.project_id is not null and not exists(select 1 from $T.project_applicants a where a.project_id=x.project_id and a.person_id=x.person_id) group by x.city_id") as $r) echo "info $t project not among person's applications, city {$r['city_id']}: {$r['c']}\n";
}
foreach (cities() as $code => $cid) {
    $db = SRC[$code];
    $a = q("select count(*) n, count(distinct p.id) proj, sum(1) s from $T.projects p where p.city_id=$cid and not exists(select 1 from $T.project_applicants a where a.project_id=p.id)")[0];
    $b = q("select count(*) n from $db.microproject m where not exists(select 1 from $db.person_microproject a where a.MicroProject_ID=m.MP_ID)")[0];
    $c = q("select count(*) n from $T.people p where p.city_id=$cid and not exists(select 1 from $T.family_members f where f.person_id=p.id)")[0];
    $d = q("select count(*) n from $db.person p where not exists(select 1 from $db.person_family f where f.Person_ID=p.P_ID and f.Family_ID is not null)")[0];
    $e = q("select count(*) n from $T.families f where f.city_id=$cid and not exists(select 1 from $T.family_members m where m.family_id=f.id)")[0];
    $g = q("select count(*) n from $db.family f where not exists(select 1 from $db.person_family m where m.Family_ID=f.F_ID and m.Person_ID is not null)")[0];
    echo "$code projects without applicant tgt={$a['n']} src={$b['n']}; people without family tgt={$c['n']} src={$d['n']}; families without members tgt={$e['n']} src={$g['n']}\n";
    $h = q("select sum(s.Person_ID is null) pn, sum(s.MicroProject_ID is null) mn from $db.person_microproject s")[0];
    $i = q("select count(*) n, sum(PersonType is null) from $db.person_microproject")[0];
    // project numbers
    $x = q("select count(*) n, sum(p.project_number<>p.legacy_id) bad, count(distinct p.project_number) d, max(p.project_number) mx from $T.projects p where p.city_id=$cid")[0];
    $m = q("select max(MP_ID) mx, count(*) n from $db.microproject")[0];
    $seq = q("select next_number from $T.project_number_sequences where city_id=$cid")[0]['next_number'];
    $ai = q("select auto_increment a from information_schema.tables where table_schema='$db' and table_name='microproject'")[0]['a'];
    echo "$code project_number: tgt n={$x['n']} distinct={$x['d']} mismatch={$x['bad']} max={$x['mx']} src max={$m['mx']} next_number=$seq expected=" . ($m['mx'] + 1) . " (source AUTO_INCREMENT=$ai)\n";
}
