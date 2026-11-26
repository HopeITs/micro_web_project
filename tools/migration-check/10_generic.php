<?php
// Generic row-by-row, cell-by-cell reconciliation of city tables that have one source table.
require __DIR__ . '/lib.php';
$T = TGT;
$S = spec()['tables'];
$cities = cities();
$cityCode = array_flip($cities);

// exceptions index
$exc = [];
foreach (q("select e.*, r.city_id from $T.migration_exceptions e join $T.migration_runs r on r.id=e.migration_run_id") as $e) {
    $exc[$e['city_id']][strtolower($e['source_table'])][(string) $e['source_key']][] = $e;
}
function excFor($cid, $table, $key, $col = null): ?array
{
    global $exc;
    foreach ($exc[$cid][strtolower($table)][(string) $key] ?? [] as $e) {
        if ($col === null || strcasecmp((string) $e['column_name'], $col) === 0) return $e;
    }
    return null;
}
$consumed = [];

// lookup map
$lmap = [];
foreach (q("select city_id, lookup_table, legacy_id, lookup_id from $T.lookup_legacy_map") as $r) {
    $lmap[$r['city_id']][$r['lookup_table']][(int) $r['legacy_id']] = (int) $r['lookup_id'];
}
// accounts: (city, legacy user id) -> account
$acct = [];
foreach (q("select u.city_id, u.legacy_user_id, l.account_id, l.city_id lc, l.revoked_at from $T.legacy_staff_users u join $T.account_legacy_links l on l.legacy_staff_user_id=u.id") as $r) {
    $acct[$r['city_id']][(int) $r['legacy_user_id']] = (int) $r['account_id'];
}

$KEYS = [ // target => [source key columns, target key columns]
    'kobo_questions' => [['Governorate', 'Question_Key'], ['governorate', 'question_key']],
    'kobo_question_versions' => [['Kobo_Form_UID', 'Form_Version', 'Field_Path'], ['form_uid', 'form_version', 'field_path']],
    'profession_meeting_careers' => [['Meeting_ID', 'Career_ID'], ['@m', '@c']],
    'kobo_monitoring_records' => [['Submission_ID'], ['legacy_id']],
    'kobo_person_reviews' => [['Submission_ID'], ['legacy_id']],
];
$SPECIAL = ['visit_answers', 'attachments', 'web_notices', 'kobo_forms', 'legacy_staff_users', 'account_city_tasks', 'account_city_roles', 'project_number_sequences'];

$idmapCache = [];
function idmap(string $table, int $cid): array
{
    global $idmapCache;
    if (!isset($idmapCache["$table.$cid"])) {
        $m = [];
        foreach (q('select id, legacy_id from ' . TGT . ".`$table` where city_id=$cid and legacy_id is not null") as $r) $m[(int) $r['legacy_id']] = (int) $r['id'];
        $idmapCache["$table.$cid"] = $m;
    }
    return $idmapCache["$table.$cid"];
}

function srcSelect(string $db, string $table): string
{
    $parts = [];
    foreach (cols($db, $table) as $c) {
        if (in_array($c['t'], ['longblob', 'mediumblob', 'blob'])) continue;
        $n = $c['n'];
        $parts[] = in_array($c['t'], ['float', 'double']) ? "(`$n`+0e0) as `$n`" : "`$n`";
    }
    return 'select ' . implode(',', $parts) . " from `$db`.`$table`";
}

$findings = [];   // [table][column][class][city] => [count, ids]
function note($table, $col, $class, $city, $id)
{
    global $findings;
    $f = &$findings[$table][$col][$class][$city];
    $f ??= [0, []];
    $f[0]++;
    if (count($f[1]) < 8) $f[1][] = $id;
}
$stats = ['rows_src' => 0, 'rows_tgt' => 0, 'rows_matched' => 0, 'cells' => 0];
$perTable = [];

$only = array_slice($argv, 1);
foreach ($S as $tn => $t) {
    if (($t['scope'] ?? '') !== 'city' && $tn !== 'web_notices') { if (!in_array($tn, ['web_notices'])) continue; }
    if (empty($t['source']) || in_array($tn, $SPECIAL)) continue;
    if ($only && !in_array($tn, $only)) continue;
    $src = $t['source'];
    foreach (SRC as $code => $db) {
        $cid = $cities[$code];
        $st = tname($db, $src);
        if ($st === null) { note($tn, '*', 'source table absent', $code, 0); continue; }
        if (isset($KEYS[$tn])) {
            [$sk, $tk] = $KEYS[$tn];
        } else {
            $pk = array_values(array_filter(cols($db, $st), fn($c) => $c['k'] === 'PRI'));
            if (count($pk) !== 1) { note($tn, '*', 'no single PK', $code, 0); continue; }
            $sk = [$pk[0]['n']];
            $tk = ['legacy_id'];
        }
        $srows = [];
        foreach (q(srcSelect($db, $st)) as $r) {
            $k = implode("\x1f", array_map(fn($c) => (string) $r[cname($db, $st, $c)], $sk));
            if (isset($srows[$k])) note($tn, '*', 'duplicate source key', $code, crc32($k));
            $srows[$k] = $r;
        }
        $trows = [];
        $sql = "select * from $T.`$tn` where city_id=$cid";
        if ($tn === 'profession_meeting_careers') {
            $sql = "select x.*, m.legacy_id `@m`, lm.legacy_id `@c` from $T.`$tn` x join $T.profession_meetings m on m.id=x.profession_meeting_id left join $T.lookup_legacy_map lm on lm.city_id=x.city_id and lm.lookup_table='careers' and lm.lookup_id=x.career_id where x.city_id=$cid";
        }
        foreach (q($sql) as $r) {
            $k = implode("\x1f", array_map(fn($c) => (string) $r[$c], $tk));
            if (isset($trows[$k])) note($tn, '*', 'duplicate target key', $code, $r['id']);
            $trows[$k] = $r;
        }
        $stats['rows_src'] += count($srows);
        $stats['rows_tgt'] += count($trows);
        @$perTable[$tn][$code] = count($srows) . '/' . count($trows);

        // required FK columns for explaining drops
        foreach ($srows as $k => $s) {
            if (isset($trows[$k])) continue;
            $e = excFor($cid, $src, $k);
            $id = ctype_digit((string) $k) ? (int) $k : crc32($k);
            if ($e) {
                // verify the exception is truthful
                $consumed[$e['id']] = 1;
                $col = cname($db, $st, (string) $e['column_name']);
                $why = 'unverified';
                if ($e['kind'] === 'required_missing' && $col) {
                    $tc = null;
                    foreach ($t['columns'] as $c) if (strcasecmp($c['source'] ?? '', $col) === 0) $tc = $c;
                    $v = $s[$col];
                    if ($tc) {
                        $ref = $tc['references'] ?? '';
                        $tr = $tc['transform'] ?? null;
                        if ($v === null) $why = 'source ref is NULL';
                        elseif ($tr === 'account') $why = isset($acct[$cid][(int) $v]) ? 'FALSE: account exists' : ((int) $v <= 0 ? "source ref $v" : 'source ref dangling');
                        elseif ($tr === 'lookup') $why = isset($lmap[$cid][$ref][(int) $v]) ? 'FALSE: lookup exists' : ((int) $v <= 0 ? "source ref $v" : 'source ref dangling');
                        else $why = isset(idmap($ref, $cid)[(int) $v]) ? 'FALSE: parent exists' : ((int) $v <= 0 ? "source ref $v" : 'source ref dangling');
                    }
                }
                note($tn, '*', "row not transferred; exception {$e['kind']} on {$e['column_name']} ($why)", $code, $id);
            } else {
                note($tn, '*', 'UNEXPLAINED missing row', $code, $id);
            }
        }
        foreach ($trows as $k => $r) {
            if (!isset($srows[$k])) note($tn, '*', 'UNEXPLAINED extra target row', $code, $r['id']);
        }

        // cells
        foreach ($t['columns'] as $c) {
            $cn = $c['name'];
            if (in_array($cn, ['id', 'city_id'])) continue;
            $sc = $c['source'] ?? null;
            if ($cn === 'legacy_id') continue;
            if ($sc === null) {
                // unmapped column: should be empty unless derived
                foreach ($trows as $k => $r) {
                    if ($r[$cn] !== null && !in_array($cn, ['created_at', 'updated_at'])) note($tn, $cn, 'unmapped column has value', $code, $r['id']);
                }
                continue;
            }
            if (str_contains($sc, '.')) continue; // handled separately
            $scol = cname($db, $st, $sc);
            if ($scol === null) {
                foreach ($trows as $k => $r) if ($r[$cn] !== null) note($tn, $cn, "source column $sc absent but target has value", $code, $r['id']);
                continue;
            }
            $tr = $c['transform'] ?? null;
            $type = $c['type'];
            $ref = $c['references'] ?? null;
            if ($tr === null && $ref !== null) $tr = 'fk';
            if ($byIndexColumn = $tr === 'opinion_index') $tr = 'lookup';
            $stype = '';
            foreach (cols($db, $st) as $cc) if ($cc['n'] === $scol) $stype = $cc['t'];
            foreach ($trows as $k => $r) {
                if (!isset($srows[$k])) continue;
                $v = $srows[$k][$scol];
                $a = $r[$cn];
                $stats['cells']++;
                $id = $r['id'];
                $e = excFor($cid, $src, $k, $scol);
                $eName = $e ? "exception {$e['kind']}" : null;
                $bad = null; // class
                if ($byIndexColumn) { // the source holds the list's Index, not its ID
                    $byIndex ??= [];
                    $byIndex[$cid] ??= array_column(q('select `Index` i, ID id from '.SRC[$code].'.checklist_opinion'), 'id', 'i');
                    $v = $v === null || $v === '' ? null : ($byIndex[$cid][(int) $v] ?? $v);
                }
                switch ($tr) {
                    case 'lookup':
                    case 'project':
                    case 'person':
                    case 'account':
                    case 'fk':
                        if ($v === null || $v === '') { if ($a !== null) $bad = 'ref: source empty, target set'; break; }
                        $iv = (int) $v;
                        if ($tr === 'lookup') { $exp = $lmap[$cid][$ref][$iv] ?? null; }
                        elseif ($tr === 'account') { $exp = $acct[$cid][$iv] ?? null; }
                        else { $exp = idmap($ref, $cid)[$iv] ?? null; }
                        if ($exp !== null) {
                            if ($a === null) $bad = 'ref: resolvable but target NULL';
                            elseif ((int) $a !== $exp) $bad = 'ref: WRONG target row';
                        } else {
                            if ($a !== null) $bad = 'ref: source unresolvable but target set';
                            elseif ($iv === -1 || $iv === 0) note($tn, $cn, "ok-null: source $iv not answered", $code, $id);
                            elseif ($e) { $consumed[$e['id']] = 1; note($tn, $cn, "explained: dangling ref, $eName", $code, $id); }
                            else $bad = 'ref: dangling source value dropped without exception';
                        }
                        break;
                    case 'yes_no': // Yes/No in any case -> yes/no
                        $x = $v === null || trim((string) $v) === '' ? null : mb_strtolower(trim((string) $v));
                        if ($x !== ($a === null ? null : (string) $a)) $bad = 'yes_no: DIFFERENT';
                        break;
                    case 'trim':
                        $x = $v === null ? null : trim((string) $v);
                        if ($x === '') $x = null;
                        if ($x === null && $a === '' && !($c['nullable'] ?? false)) { note($tn, $cn, $e ? "explained: required text empty, $eName" : 'required text empty, NO exception', $code, $id); if ($e) $consumed[$e['id']] = 1; break; }
                        if ($x !== ($a === null ? null : (string) $a)) $bad = strclass($x, $a);
                        break;
                    case 'date':
                        $zero = $v === null || str_starts_with((string) $v, '0000') || (string) $v < '1900';
                        if ($zero) {
                            if ($a !== null) $bad = 'date: invalid source but target set';
                            elseif ($v === null) break;
                            elseif ($e) { $consumed[$e['id']] = 1; note($tn, $cn, "explained: invalid date, $eName", $code, $id); if ((string)$e['original_value'] !== (string)$v) $bad = 'date: exception original_value differs from source'; }
                            else $bad = 'date: invalid source nulled, no exception';
                        } else {
                            $x = (string) $v;
                            if (str_starts_with($type, 'datetime') && strlen($x) === 10) $x .= ' 00:00:00';
                            if (str_starts_with($type, 'date') && !str_starts_with($type, 'datetime')) $x = substr($x, 0, 10);
                            if ($a === null) { $bad = $e ? null : 'date: valid source nulled without exception'; if ($e) { $consumed[$e['id']] = 1; note($tn, $cn, "valid-looking date nulled, $eName (year " . substr($x, 0, 4) . ')', $code, $id); } }
                            elseif ((string) $a !== $x) $bad = 'date: different value';
                            if (substr($x, 5, 2) === '00' || substr($x, 8, 2) === '00') note($tn, $cn, 'info: partial-zero date in source', $code, $id);
                        }
                        break;
                    case 'bool01':
                        $map = ['1' => 1, '0' => 0, 'YES' => 1, 'Yes' => 1, 'NO' => 0, 'No' => 0, 'Paid' => 1, 'Not Paid' => 0];
                        if ($v === null || $v === '') { if ($a !== null) { if (!($c['nullable'] ?? false)) note($tn, $cn, 'bool: empty source -> required target ' . $a, $code, $id); else $bad = 'bool: empty source but target set'; } break; }
                        if (array_key_exists((string) $v, $map)) { if ($a === null || (int) $a !== $map[(string) $v]) $bad = 'bool: wrong value (source ' . $v . ', target ' . var_export($a, true) . ')'; break; }
                        $lbl = preg_match('/^-?\d+$/', (string) $v) ? (string) $v : 'non-numeric';
                        if (!($c['nullable'] ?? false)) { note($tn, $cn, "bool: unrecognised source ($lbl) -> required target " . var_export($a, true) . ($e ? " with $eName" : ' NO exception'), $code, $id); if ($e) $consumed[$e['id']] = 1; break; }
                        if ($a !== null) $bad = "bool: unrecognised source ($lbl) but target " . $a;
                        elseif ((string) $v === '-1') note($tn, $cn, 'ok-null: -1 not answered', $code, $id);
                        elseif ($e) { $consumed[$e['id']] = 1; note($tn, $cn, "explained: bool $lbl, $eName", $code, $id); }
                        else $bad = "bool: unrecognised source ($lbl) nulled without exception";
                        break;
                    case 'sex':
                        $x = $v === 'ذكر' ? 'male' : ($v === 'أنثى' ? 'female' : null);
                        if ($x !== $a) $bad = 'sex: wrong';
                        elseif ($x === null && $v !== null && trim($v) !== '') { if ($e) { $consumed[$e['id']] = 1; note($tn, $cn, "explained: $eName", $code, $id); } else $bad = 'sex: unrecognised nulled without exception'; }
                        break;
                    case 'stage':
                        $x = $v === 'zero' ? 'preliminary' : ($v === 'project' ? 'full' : "?$v");
                        if ($x !== $a) $bad = 'stage: wrong';
                        break;
                    case 'currency':
                        $x = $v === 'SP' || $v === 'SYP' ? 'SYP' : ($v === 'USD' ? 'USD' : null);
                        if ($x !== $a) $bad = 'currency: wrong';
                        break;
                    case 'money':
                    default:
                        if (str_starts_with($type, 'dec:')) {
                            $scale = (int) explode(',', $type)[1];
                            if ($v === null) { if ($a !== null) $bad = 'num: source null, target set'; break; }
                            if ($a === null) { $bad = $e ? null : 'num: value dropped to null without exception'; if ($e) { $consumed[$e['id']] = 1; note($tn, $cn, "explained: nulled, $eName", $code, $id); } break; }
                            $sv = (float) $v; $av = (float) $a;
                            $d = abs($sv - $av);
                            $exp = round($sv, $scale);
                            $tol = max(1e-9, abs($sv) * 1e-14);
                            if (abs($exp - $av) > $tol) {
                                if ($d <= 0.5 * pow(10, -$scale) + $tol) note($tn, $cn, 'num: within half unit but not half-up', $code, $id);
                                else { $bad = $e ? null : 'num: different value, no exception'; if ($e) { $consumed[$e['id']] = 1; note($tn, $cn, "explained: value changed, $eName", $code, $id); } }
                            } elseif ($d > $tol) {
                                $cls = $d > 0.005 + $tol ? 'num: rounded by more than 0.005' : 'num: rounded (<=0.005)';
                                if ($e) { $consumed[$e['id']] = 1; note($tn, $cn, "$cls, $eName", $code, $id); }
                                else note($tn, $cn, "$cls, no exception", $code, $id);
                            }
                            break;
                        }
                        if (preg_match('/^(int|uint|smallint|tinyint|bool)/', $type)) {
                            if ($v === null || $v === '') { if ($a !== null) { if ($type === 'bool' && !($c['nullable'] ?? false)) note($tn, $cn, 'bool(no transform): empty -> ' . $a, $code, $id); else $bad = 'int: source empty, target set'; } break; }
                            if ($a === null) { if ($e) { $consumed[$e['id']] = 1; note($tn, $cn, "explained: int nulled, $eName", $code, $id); } else $bad = 'int: value dropped to null (source ' . (is_numeric($v) && abs($v) < 2 ? $v : 'other') . ')'; break; }
                            if (!is_numeric($v) || (float) $v != (float) $a) $bad = 'int: different value';
                            break;
                        }
                        if ($type === 'json') {
                            if ($v === null || $v === '') { if ($a !== null && $a !== '') $bad = 'json: source empty target set'; break; }
                            if ((string) $v !== (string) $a) {
                                if ($a !== null && json_decode($v, true) === json_decode($a, true) && json_last_error() === 0) note($tn, $cn, 'json: reformatted but equal', $code, $id);
                                else { $bad = $e ? null : ($a === null ? 'json: dropped without exception' : 'json: different'); if ($e) { $consumed[$e['id']] = 1; note($tn, $cn, "explained json, $eName", $code, $id); } }
                            }
                            break;
                        }
                        if (str_starts_with($type, 'datetime')) {
                            $x = ($v === null || str_starts_with((string) $v, '0000')) ? null : (string) $v;
                            if ($x !== $a) $bad = 'datetime: different';
                            break;
                        }
                        if (str_starts_with($type, 'enum')) {
                            $x = $v === null ? null : strtolower((string) $v);
                            if ($tn === 'profession_meetings' && $cn === 'record_state') $x = ($v === null || $v === '') ? 'final' : ($v === 'Draft' ? 'draft' : ($v === 'Finalized' ? 'final' : "?$v"));
                            if ($x !== $a) $bad = 'enum: different';
                            break;
                        }
                        // strings
                        $x = $v === null ? null : (string) $v;
                        if ($x !== ($a === null ? null : (string) $a)) {
                            if ($x === null && $a === '' && !($c['nullable'] ?? false)) { note($tn, $cn, $e ? "explained: required empty, $eName" : 'required text: NULL stored as empty, no exception', $code, $id); if ($e) $consumed[$e['id']] = 1; break; }
                            $bad = strclass($x, $a);
                            if ($e) { $consumed[$e['id']] = 1; note($tn, $cn, "explained: $bad, $eName", $code, $id); $bad = null; }
                        } elseif ($x === '' && !($c['nullable'] ?? false) && $e) { $consumed[$e['id']] = 1; note($tn, $cn, "explained: required text empty, $eName", $code, $id); }
                        break;
                }
                if ($bad !== null) note($tn, $cn, $bad, $code, $id);
            }
        }
        $stats['rows_matched'] += count(array_intersect_key($srows, $trows));
        unset($srows, $trows);
    }
    fwrite(STDERR, "$tn done\n");
}
function strclass($x, $a): string
{
    if ($x === '' && $a === null) return "str: '' -> NULL";
    if ($x === null && $a === '') return "str: NULL -> ''";
    if ($x === null) return 'str: source NULL, target set';
    if ($a === null) return trim($x) === '' ? 'str: whitespace-only -> NULL' : 'str: VALUE LOST (target NULL)';
    $a = (string) $a;
    if (trim($x) === $a) return 'str: outer whitespace trimmed';
    if (preg_replace('/^[\s\x{00A0}\x{200E}\x{200F}]+|[\s\x{00A0}\x{200E}\x{200F}]+$/u', '', $x) === $a) return 'str: outer unicode whitespace trimmed';
    if (str_replace("\r\n", "\n", $x) === str_replace("\r\n", "\n", $a)) return 'str: line endings changed';
    if ($a !== '' && str_starts_with($x, $a)) return 'str: TRUNCATED';
    return 'str: DIFFERENT';
}

file_put_contents(OUT . '/generic_findings.json', json_encode(['stats' => $stats, 'findings' => $findings, 'perTable' => $perTable, 'consumed' => array_keys($consumed)], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
echo json_encode($stats), "\n";
foreach ($findings as $tn => $cs) foreach ($cs as $cn => $cls) foreach ($cls as $k => $per) {
    echo "$tn.$cn | $k |";
    foreach ($per as $c => [$n, $ids]) echo " $c=$n(", implode(',', array_slice($ids, 0, 4)), ")";
    echo "\n";
}
