<?php
require __DIR__ . '/lib.php';
$T = TGT; $C = cities();
$F = []; $n = 0; $cells = 0;
function note($k, $c, $id) { global $F; $F[$k][$c] ??= [0, []]; $F[$k][$c][0]++; if (count($F[$k][$c][1]) < 6) $F[$k][$c][1][] = $id; }
$acct = [];
foreach (q("select u.city_id, u.legacy_user_id, l.account_id from $T.legacy_staff_users u join $T.account_legacy_links l on l.legacy_staff_user_id=u.id") as $r) $acct[$r['city_id']][$r['legacy_user_id']] = $r['account_id'];
foreach (SRC as $code => $db) {
    $cid = $C[$code];
    $pm = []; foreach (q("select id, legacy_id from $T.projects where city_id=$cid") as $r) $pm[$r['legacy_id']] = $r['id'];
    $im = []; foreach (q("select id, legacy_id from $T.loan_installments where city_id=$cid") as $r) $im[$r['legacy_id']] = $r['id'];
    $ret = []; foreach (q("select * from $db.kobo_visit_schedule") as $r) $ret[$r['Notification_ID']] = (int) $r['Retired'];
    $t = []; foreach (q("select * from $T.web_notices where city_id=$cid") as $r) { if (isset($t[$r['legacy_id']])) note('duplicate', $code, $r['id']); $t[$r['legacy_id']] = $r; }
    $seenRet = [];
    foreach (q("select * from $db.user_notification") as $s) {
        $n++;
        $r = $t[$s['ID']] ?? null;
        if (!$r) { note('missing row', $code, $s['ID']); continue; }
        unset($t[$s['ID']]);
        $id = $r['id'];
        foreach ([['account_id', 'User_ID'], ['sender_account_id', 'Sender_ID']] as [$tc, $sc]) {
            $cells++;
            $v = $s[$sc];
            $exp = $v === null ? null : ($acct[$cid][$v] ?? null);
            if ($exp != $r[$tc]) note("$tc wrong (source " . ($v === null ? 'null' : ($v <= 0 ? $v : 'positive')) . ", target " . ($r[$tc] === null ? 'null' : 'set') . ')', $code, $id);
            elseif ($v !== null && $exp === null) note("info $tc: dangling user dropped (source " . ($v <= 0 ? $v : 'positive') . ')', $code, $id);
        }
        $cells += 8;
        $v = $s['MicroProject_ID'];
        $exp = $v === null ? null : ($pm[$v] ?? null);
        if ($exp != $r['project_id']) note('project_id wrong', $code, $id);
        elseif ($v !== null && $exp === null) note('info project dangling', $code, $id);
        if (($v === null ? null : (int) $v) !== ($r['project_number'] === null ? null : (int) $r['project_number'])) note('project_number differs from MicroProject_ID', $code, $id);
        $pk = $s['Pay_ID'];
        if (($pk === null ? null : (int) $pk) !== ($r['legacy_pay_key'] === null ? null : (int) $r['legacy_pay_key'])) note('legacy_pay_key differs', $code, $id);
        $exp = ($pk !== null && $pk > 0) ? ($im[$pk] ?? null) : null;
        if ($exp != $r['loan_installment_id']) note('loan_installment_id wrong (target ' . ($r['loan_installment_id'] === null ? 'null' : 'set') . ')', $code, $id);
        elseif ($pk > 0 && $exp === null) note('info positive Pay_ID with no instalment (kept only in legacy_pay_key, no exception)', $code, $id);
        // instalment must belong to the notice's project
        $d = substr((string) $s['Date'], 0, 10);
        if ($d !== $r['notice_date']) note('notice_date differs', $code, $id);
        if ($r['original_date'] !== $d) note('original_date differs from source Date', $code, $id);
        foreach ([['title', 'P_Name'], ['body', 'Body'], ['note', 'Note']] as [$tc, $sc]) {
            $a = $r[$tc]; $v = $s[$sc];
            if ((string) $a !== (string) $v) note("$tc differs" . (trim((string) $v) === (string) $a ? ' (outer whitespace trimmed)' : ($a !== null && str_starts_with((string) $v, $a) ? ' (TRUNCATED)' : '')), $code, $id);
            elseif (($a === null) !== ($v === null)) note("$tc '' <-> NULL", $code, $id);
        }
        $seen = $s['Seen'] === null ? null : (int) $s['Seen'];
        if (!in_array($seen, [0, 1], true) || $seen !== (int) $r['seen']) note('seen differs src ' . var_export($seen, true), $code, $id);
        $exp = $ret[$s['ID']] ?? 0;
        if (isset($ret[$s['ID']])) $seenRet[$s['ID']] = 1;
        if ($exp !== (int) $r['retired_by_kobo']) note('retired_by_kobo differs', $code, $id);
        // kind per spec rule
        $b = (string) $s['Body'];
        if ($pk > 0 && isset($im[$pk])) $k = 'payment';
        elseif (str_contains($b, 'تم تبديل')) $k = 'state';
        elseif (str_contains($b, 'المشتريات')) $k = 'purchases';
        elseif (str_contains($b, 'Visit') && str_contains($b, 'Trophy')) $k = 'trophy';
        elseif (str_contains($b, 'Visit')) $k = 'visit';
        elseif (str_contains($b, 'براءة الذمة')) $k = 'clearance';
        elseif (str_contains($b, 'التنفيذي')) $k = 'executive_file';
        else $k = 'message';
        if ($k !== $r['kind']) note("kind: spec rule gives $k, target {$r['kind']} (Pay_ID " . ($pk > 0 ? (isset($im[$pk]) ? 'instalment' : 'positive-dangling') : (($pk <= -1000 && $pk >= -1039) ? 'state code' : $pk)) . ')', $code, $id);
        // notice installment project consistency
        if ($r['loan_installment_id'] !== null) { $lp[$r['loan_installment_id']][] = [$r['project_id'], $code, $id]; }
    }
    foreach ($t as $r) note('extra target row', $code, $r['id']);
    foreach ($ret as $k => $v) if (!isset($seenRet[$k])) note('kobo_visit_schedule row without notification', $code, $k);
    $q = q("select count(*) c, min(w.id) m from $T.web_notices w join $T.loan_installments i on i.id=w.loan_installment_id join $T.loans l on l.id=i.loan_id where w.city_id=$cid and (w.project_id is null or w.project_id<>l.project_id)")[0];
    echo "$code notices whose instalment's loan project differs from notice project: {$q['c']} (first {$q['m']})\n";
}
echo "rows=$n cells=$cells\n";
foreach ($F as $k => $p) { echo $k, ' |'; foreach ($p as $c => [$x, $ids]) echo " $c=$x(", implode(',', $ids), ")"; echo "\n"; }
