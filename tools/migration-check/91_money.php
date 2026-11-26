<?php
// Money and instalment reconciliation per city, currency, project and loan, using integer cents.
require __DIR__ . '/lib.php';
$T = TGT; $C = cities();
$cents = fn($v) => $v === null ? 0 : (int) round(((float) $v) * 100);
$tot = ['loans' => 0, 'installments' => 0, 'projects' => 0, 'comparisons' => 0];
foreach (SRC as $code => $db) {
    $cid = $C[$code];
    $load = function (array $rows) use ($cents) {
        $loans = [];
        foreach ($rows as $r) {
            $k = (int) $r['lid'];
            $loans[$k] ??= ['mp' => (int) $r['mp'], 'cur' => $r['cur'] === 'SP' ? 'SYP' : $r['cur'], 'amount' => $cents($r['amount']), 'ret' => $cents($r['ret']), 'inst' => $cents($r['inst']), 'n' => 0, 'paid' => 0, 'sum' => 0, 'paidsum' => 0, 'ids' => []];
            if ($r['pid'] !== null) {
                $p = in_array((string) $r['paidflag'], ['Paid', '1'], true);
                $loans[$k]['n']++; $loans[$k]['paid'] += $p; $loans[$k]['sum'] += $cents($r['pamount']); if ($p) $loans[$k]['paidsum'] += $cents($r['pamount']);
                $loans[$k]['ids'][(int) $r['pid']] = [$cents($r['pamount']), $p, $r['due'], $r['rec']];
            }
        }
        ksort($loans); foreach ($loans as &$l) ksort($l['ids']);
        return $loans;
    };
    $s = $load(q("select l.Loan_ID lid, l.MicroProject_ID mp, l.Currency cur, l.Loan_Amount amount, (l.Loan_ReturnedAmount+0e0) ret, (l.Loan_PaymentsAmount+0e0) inst, p.Pay_ID pid, (p.Pay_Amount+0e0) pamount, p.Pay_IsPaid paidflag, cast(p.Pay_DueDate as char) due, cast(p.Pay_RecievedOnDate as char) rec from $db.loan l left join $db.payment p on p.Loan_ID=l.Loan_ID"));
    $t = $load(q("select l.legacy_id lid, pr.legacy_id mp, l.currency cur, l.amount, l.returned_amount ret, l.installment_amount inst, i.legacy_id pid, i.amount pamount, i.is_paid paidflag, cast(i.due_on as char) due, cast(i.received_on as char) rec from $T.loans l join $T.projects pr on pr.id=l.project_id and pr.city_id=l.city_id left join $T.loan_installments i on i.loan_id=l.id and i.city_id=l.city_id where l.city_id=$cid"));
    $bad = [];
    foreach ($s as $k => $a) {
        $b = $t[$k] ?? null;
        if (!$b) { $bad['loan missing'][] = $k; continue; }
        foreach (['mp', 'cur', 'amount', 'ret', 'inst', 'n', 'paid', 'sum', 'paidsum'] as $f) { $tot['comparisons']++; if ($a[$f] !== $b[$f]) $bad["loan $f differs"][] = $k; }
        if (array_keys($a['ids']) !== array_keys($b['ids'])) $bad['instalment id set differs'][] = $k;
        foreach ($a['ids'] as $pid => $v) { $w = $b['ids'][$pid] ?? null; if (!$w) continue; $norm = fn($d) => ($d === null || str_starts_with($d, '0000') || $d < '1900') ? null : $d; $tot['comparisons'] += 4; if ($v[0] !== $w[0] || $v[1] !== $w[1] || $norm($v[2]) !== $w[2] || $norm($v[3]) !== $w[3]) $bad['instalment detail differs'][] = $pid; }
    }
    foreach ($t as $k => $b) if (!isset($s[$k])) $bad['extra loan'][] = $k;
    $tot['loans'] += count($s); $tot['installments'] += array_sum(array_column($s, 'n'));
    $nsp = q("select count(*) c from $db.payment p left join $db.loan l on l.Loan_ID=p.Loan_ID where l.Loan_ID is null")[0]['c'];
    $ntp = q("select count(*) c from $T.loan_installments i left join $T.loans l on l.id=i.loan_id and l.city_id=i.city_id where i.city_id=$cid and l.id is null")[0]['c'];
    echo "== $code loans=", count($s), "/", count($t), " instalments src=", q("select count(*) c from $db.payment")[0]['c'], " tgt=", q("select count(*) c from $T.loan_installments where city_id=$cid")[0]['c'], " orphan src=$nsp tgt=$ntp\n";
    foreach (['SYP', 'USD'] as $cur) {
        $sum = fn($x, $f) => array_sum(array_map(fn($l) => $l['cur'] === $cur ? $l[$f] : 0, $x));
        $out = [];
        foreach (['amount', 'ret', 'inst', 'n', 'paid', 'sum', 'paidsum'] as $f) { $a = $sum($s, $f); $b = $sum($t, $f); $out[] = "$f=" . (in_array($f, ['n', 'paid']) ? $a : $a / 100) . ($a === $b ? ' ok' : " DIFF(tgt " . (in_array($f, ['n', 'paid']) ? $b : $b / 100) . ')'); }
        if ($sum($s, 'amount') || $sum($t, 'amount')) echo "  $cur: ", implode('; ', $out), "\n";
    }
    // per project
    $pp = function ($x) { $o = []; foreach ($x as $l) foreach (['amount', 'sum', 'paidsum', 'n', 'paid', 'ret'] as $f) { $o[$l['mp'] . '|' . $l['cur']][$f] = ($o[$l['mp'] . '|' . $l['cur']][$f] ?? 0) + $l[$f]; } ksort($o); return $o; };
    $a = $pp($s); $b = $pp($t); $tot['projects'] += count($a);
    echo "  per project/currency groups=", count($a), " differing=", count(array_diff_key($a, $b)) + count(array_filter(array_keys($a), fn($k) => isset($b[$k]) && $a[$k] !== $b[$k])) + count(array_diff_key($b, $a)), "\n";
    foreach ($bad as $k => $v) echo "  DIFF $k: ", count($v), " (", implode(',', array_slice($v, 0, 8)), ")\n";
    // raw column sums other money
    foreach ([['microproject', 'MP_RequestedAmount', 'projects', 'requested_amount'], ['material', 'Price', 'project_materials', 'price'], ['material', 'LocalContribution', 'project_materials', 'local_contribution'], ['material', 'Amount', 'project_materials', 'quantity'], ['mevisit', 'Profit', 'me_visits', 'profit'], ['person_workexp', 'W_Salary', 'person_work_experiences', 'salary'], ['microproject_powersource', 'Cost', 'project_power_sources', 'cost'], ['person_powersource', 'Cost', 'person_power_sources', 'cost'], ['microproject_rehab_answer', 'Price', 'project_rehabilitation_items', 'price']] as [$st, $sc, $tt, $tc]) {
        $pk = 'ID'; if ($st === 'microproject') $pk = 'MP_ID';
        $r = q("select coalesce(sum(round(s.`$sc`+0e0,2)),0) a, coalesce(sum(t.`$tc`),0) b, count(*) n from $db.`$st` s join $T.$tt t on t.city_id=$cid and t.legacy_id=s.$pk")[0];
        $lost = q("select count(*) n, coalesce(sum(s.`$sc`),0) a from $db.`$st` s left join $T.$tt t on t.city_id=$cid and t.legacy_id=s.$pk where t.id is null")[0];
        if (abs($r['a'] - $r['b']) > 0.02 || ($lost['a'] ?? 0) != 0) echo "  other money $st.$sc: migrated rows sum src=", $r['a'], " tgt=", $r['b'], "; dropped rows=", $lost['n'], " dropped sum=", $lost['a'], "\n";
    }
}
echo json_encode($tot), "\n";
