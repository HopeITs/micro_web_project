<?php
require __DIR__ . '/lib.php';
$T = TGT; $C = cities();
$F = []; $n = []; $cells = 0;
function note($k, $c, $id) { global $F; $F[$k][$c] ??= [0, []]; $F[$k][$c][0]++; if (count($F[$k][$c][1]) < 8) $F[$k][$c][1][] = $id; }
$exc = [];
foreach (q("select e.*, r.city_id from $T.migration_exceptions e join $T.migration_runs r on r.id=e.migration_run_id") as $e) $exc[$e['city_id']][$e['source_table']][$e['source_key']][] = $e;

// ---- kobo_forms
foreach (SRC as $code => $db) {
    $cid = $C[$code];
    $t = []; foreach (q("select * from $T.kobo_forms where city_id=$cid") as $r) $t[$r['legacy_id']] = $r;
    $uids = [];
    foreach (q("select * from $db.kobo_form_mapping") as $s) {
        @$n['kobo_form_mapping']++;
        $r = $t[$s['ID']] ?? null;
        if (!$r) { note('kobo_forms missing row', $code, $s['ID']); continue; }
        unset($t[$s['ID']]);
        $u = $s['Kobo_Form_UID']; $uids[$u] = 1;
        $pairs = ['form_uid' => $u, 'form_name' => $s['Kobo_Form_Name'], 'governorate' => $s['Governorate'], 'visit_type' => $s['Visit_Type'], 'visit_category' => $s['Visit_Category'], 'target_table' => $s['Target_Table'], 'is_active' => $s['Is_Active']];
        $mon = q("select * from $db.kobo_monitoring_field_mapping where binary Kobo_Form_UID=binary ?", [$u]);
        $op = q("select * from $db.kobo_opening_field_mapping where binary Kobo_Form_UID=binary ?", [$u]);
        $st = q("select * from $db.kobo_monitoring_stage_mapping where binary Kobo_Form_UID=binary ?", [$u]);
        if ($mon && $op) {
            foreach (['Project_Number_Field', 'Visitor_Choice_Field', 'Visitor_Text_Field', 'Vehicle_Number_Field'] as $f) if ((string) $mon[0][$f] !== (string) $op[0][$f]) note("kobo_forms: monitoring and opening mapping both exist and differ ($f)", $code, $r['id']);
        }
        $m = $mon[0] ?? $op[0] ?? null;
        foreach (['project_number_field' => 'Project_Number_Field', 'visitor_choice_field' => 'Visitor_Choice_Field', 'visitor_text_field' => 'Visitor_Text_Field', 'vehicle_number_field' => 'Vehicle_Number_Field'] as $tc => $sc) $pairs[$tc] = $m[$sc] ?? null;
        $pairs['stage_field'] = $st[0]['Stage_Field'] ?? null;
        $pairs['fixed_stage'] = $st[0]['Fixed_Stage'] ?? null;
        foreach ($pairs as $tc => $v) { $cells++; $a = $r[$tc]; if ((string) $v !== (string) $a) note("kobo_forms.$tc differs" . ($a === null && $v === '' ? " ('' -> null)" : (trim((string) $v) === (string) $a ? ' (trim)' : '')), $code, $r['id']); }
    }
    foreach ($t as $r) note('kobo_forms extra row', $code, $r['id']);
    foreach (['kobo_monitoring_field_mapping', 'kobo_opening_field_mapping', 'kobo_monitoring_stage_mapping'] as $m) foreach (q("select Kobo_Form_UID u from $db.$m") as $x) { @$n[$m]++; if (!isset($uids[$x['u']])) note("$m row has no form mapping (lost)", $code, 0); }
}

// ---- staff users, accounts, roles, capabilities, tasks
$accounts = []; foreach (q("select * from $T.web_accounts") as $a) $accounts[$a['id']] = $a;
$links = []; foreach (q("select * from $T.account_legacy_links") as $l) $links[$l['legacy_staff_user_id']][] = $l;
$roles = []; foreach (q("select *, cast(decision_locator as char) loc from $T.account_city_roles") as $r) if ($r['loc'] !== 'seed:test-role-accounts') $roles[$r['account_id']][] = $r;
$caps = []; foreach (q("select *, cast(decision_locator as char) loc from $T.account_city_capabilities") as $r) if ($r['loc'] !== 'seed:test-role-accounts') $caps[$r['account_id']][] = $r;
$testAcc = []; foreach (q("select distinct account_id a from $T.account_city_roles where cast(decision_locator as char)='seed:test-role-accounts' union select distinct account_id from $T.account_city_capabilities where cast(decision_locator as char)='seed:test-role-accounts'") as $r) $testAcc[$r['a']] = 1;
$combo = []; $linkedAccounts = []; $acct = [];
$tdmap = []; foreach (q("select * from $T.lookup_legacy_map where lookup_table='task_definitions'") as $r) $tdmap[$r['city_id']][$r['legacy_id']] = $r['lookup_id'];
foreach (SRC as $code => $db) {
    $cid = $C[$code];
    $t = []; foreach (q("select * from $T.legacy_staff_users where city_id=$cid") as $r) $t[$r['legacy_user_id']] = $r;
    $roleNames = []; foreach (q("select * from $db.role") as $r) $roleNames[$r['Role_ID']] = $r['Role_Name'];
    foreach (q("select UserID, UserName, Email, Password, UserRoleID, IsVisitor, IsME, IsCommunication, IsCashier from $db.user") as $s) {
        @$n['user']++;
        $r = $t[$s['UserID']] ?? null;
        if (!$r) { note('legacy_staff_users missing', $code, $s['UserID']); continue; }
        unset($t[$s['UserID']]);
        foreach (['username' => 'UserName', 'email' => 'Email', 'password_hash' => 'Password', 'legacy_role_id' => 'UserRoleID'] as $tc => $sc) { $cells++; $v = $s[$sc]; $a = $r[$tc]; if ((string) $v !== (string) $a) note("legacy_staff_users.$tc differs" . (($a === null && trim((string) $v) === '') ? " (blank -> null)" : (trim((string) $v) === (string) $a ? ' (trim)' : '')), $code, $r['id']); }
        foreach (['is_visitor' => 'IsVisitor', 'is_me' => 'IsME', 'is_communication' => 'IsCommunication', 'is_cashier' => 'IsCashier'] as $tc => $sc) { $cells++; $v = $s[$sc]; if ((int) ($v == 1) !== (int) $r[$tc]) note("$tc differs", $code, $r['id']); if ($v === null || !in_array((int) $v, [0, 1], true)) note("info user flag $sc source " . var_export($v, true), $code, $r['id']); }
        $cells++;
        if ((int) $s['UserRoleID'] === 6 && $r['disabled_at'] === null) note('role 6 user NOT disabled (legacy_staff_users.disabled_at)', $code, $r['id']);
        if ((int) $s['UserRoleID'] !== 6 && $r['disabled_at'] !== null) note('non-role-6 user has disabled_at', $code, $r['id']);
        $l = $links[$r['id']] ?? [];
        if (count($l) !== 1) { note('staff user without exactly one account link', $code, $r['id']); continue; }
        $l = $l[0];
        if ((int) $l['city_id'] !== $cid) note('account link city differs from staff user city', $code, $r['id']);
        if ($l['revoked_at'] !== null) note('account link revoked', $code, $r['id']);
        $aid = $l['account_id']; $linkedAccounts[$aid][] = $r['id'];
        $a = $accounts[$aid] ?? null;
        if (!$a) { note('link to missing account', $code, $r['id']); continue; }
        if (isset($testAcc[$aid])) note('legacy user linked to a test account', $code, $r['id']);
        $acct[$cid][$s['UserID']] = $aid;
        if ((int) $a['credential_legacy_user_id'] !== (int) $r['id']) note('account credential_legacy_user_id points to a different staff user', $code, $r['id']);
        if ($a['disabled_at'] === null) note('imported account is ENABLED (spec: disabled)', $code, $r['id']);
        if ($a['password_hash'] !== null) note('imported account has its own password_hash', $code, $r['id']);
        if ($a['email'] !== null) note('info imported account has email', $code, $r['id']);
        $unameOk = str_contains(mb_strtolower($a['username']), mb_strtolower(trim($s['UserName'])));
        if (!$unameOk) note('info account username not derived from legacy name', $code, $r['id']);
        $rs = []; foreach ($roles[$aid] ?? [] as $x) { if ((int) $x['city_id'] !== $cid) note('role grant in another city', $code, $x['id']); $rs[] = $x['role'] . ($x['revoked_at'] ? '(revoked)' : ''); }
        $cs = []; foreach ($caps[$aid] ?? [] as $x) { if ((int) $x['city_id'] !== $cid) note('capability grant in another city', $code, $x['id']); $cs[] = $x['capability'] . ($x['revoked_at'] ? '(revoked)' : ''); }
        sort($rs); sort($cs);
        $key = "role{$s['UserRoleID']}:" . ($roleNames[$s['UserRoleID']] ?? '?') . " V{$s['IsVisitor']}M{$s['IsME']}C{$s['IsCommunication']}\$" . "{$s['IsCashier']}";
        $combo[$key][implode(',', $rs) . ' | caps=' . count($cs) . ' ' . substr(md5(implode(',', $cs)), 0, 6)][] = "$code:{$r['id']}";
        $combo2[(int) $s['UserRoleID']][implode(',', $rs)][implode(',', $cs)] = 1;
    }
    foreach ($t as $r) { note('legacy_staff_users row not in source user table (placeholder?)', $code, $r['id']); }
    // tasks
    $tt = []; foreach (q("select * from $T.account_city_tasks where city_id=$cid") as $r) if (!isset($testAcc[$r['account_id']])) $tt[$r['account_id'] . ':' . $r['task_definition_id']][] = $r;
    $seen = [];
    foreach (q("select * from $db.task_user") as $s) {
        @$n['task_user']++; $cells += 2;
        $aid = $s['User_ID'] === null ? null : ($acct[$cid][$s['User_ID']] ?? null);
        $td = $s['Task_ID'] === null ? null : ($tdmap[$cid][$s['Task_ID']] ?? null);
        $e = $exc[$cid]['task_user'][$s['TU_ID']] ?? null;
        if (!$aid || !$td) { note('task_user row dropped: ' . (!$aid ? 'no user (src ' . var_export($s['User_ID'] === null ? null : ($s['User_ID'] > 0 ? 'positive-dangling' : $s['User_ID']), true) . ')' : 'no task') . ($e ? ' recorded' : ' NO exception'), $code, $s['TU_ID']); continue; }
        $k = "$aid:$td";
        if (!isset($tt[$k])) note('task_user assignment MISSING' . ($e ? ' recorded' : ''), $code, $s['TU_ID']);
        elseif (isset($seen[$k])) note('info duplicate task_user assignment collapsed' . ($e ? ' recorded' : ' (no exception)'), $code, $s['TU_ID']);
        $seen[$k] = 1;
        if (isset($tt[$k]) && $tt[$k][0]['revoked_at'] !== null) note('task revoked', $code, $s['TU_ID']);
    }
    foreach ($tt as $k => $r) if (!isset($seen[$k])) note('extra task assignment', $code, $r[0]['id']);
}
foreach ($accounts as $id => $a) if (!isset($linkedAccounts[$id]) && !isset($testAcc[$id])) note('account with no legacy link and no test grant', 'ALL', $id);
foreach ($linkedAccounts as $id => $x) if (count($x) > 1) note('account merged from several legacy users', 'ALL', $id);
foreach ($roles as $aid => $x) if (!isset($linkedAccounts[$aid])) note('import role grant on unlinked account', 'ALL', $aid);
foreach ($caps as $aid => $x) if (!isset($linkedAccounts[$aid])) note('non-seed capability on unlinked account', 'ALL', $aid);
echo "test accounts ignored: ", count($testAcc), "\n";
echo json_encode($n), " cells=$cells\n";
foreach ($F as $k => $p) { echo $k, ' |'; foreach ($p as $c => [$x, $ids]) echo " $c=$x(", implode(',', $ids), ")"; echo "\n"; }
echo "== role mapping by legacy role + flags (V visitor, M me, C communication, \$ cashier)\n";
ksort($combo);
foreach ($combo as $k => $v) { echo "$k\n"; foreach ($v as $g => $ids) echo "    -> $g : ", count($ids), " e.g. ", implode(' ', array_slice($ids, 0, 4)), "\n"; }
$loc = []; foreach (q("select cast(decision_locator as char) l, count(*) c from $T.account_city_capabilities group by 1") as $r) echo "cap locator {$r['l']}={$r['c']}\n";
echo "== capability sets per mapped role set\n";
foreach ($combo2 as $rid => $x) foreach ($x as $rs => $y) foreach (array_keys($y) as $cs) echo "legacy role $rid roles[$rs] caps: $cs\n";
