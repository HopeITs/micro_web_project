<?php
require __DIR__ . '/lib.php';
$T = TGT; $C = cities();
$F = []; $n = 0; $cells = 0;
function note($k, $c, $id) { global $F; $F[$k][$c] ??= [0, []]; $F[$k][$c][0]++; if (count($F[$k][$c][1]) < 6) $F[$k][$c][1][] = $id; }
$lmap = [];
foreach (q("select * from $T.lookup_legacy_map where lookup_table='attachment_types'") as $r) $lmap[$r['city_id']][$r['legacy_id']] = $r['lookup_id'];
$allPaths = [];
$known = [];
foreach (SRC as $code => $db) {
    $cid = $C[$code];
    $pm = []; foreach (q("select id, legacy_id from $T.projects where city_id=$cid") as $r) $pm[$r['legacy_id']] = $r['id'];
    $pe = []; foreach (q("select id, legacy_id from $T.people where city_id=$cid") as $r) $pe[$r['legacy_id']] = $r['id'];
    $t = []; foreach (q("select * from $T.attachments where city_id=$cid") as $r) { if (isset($t[$r['legacy_id']])) note('duplicate legacy id', $code, $r['id']); $t[$r['legacy_id']] = $r; }
    $src = [];
    foreach (q("select * from $db.image") as $s) $src[(int) $s['Image_ID']] = ['image', $s];
    foreach (q("select File_ID, File_Name, MicroProject_ID, length(File_Content) len, sha2(File_Content,256) h, hex(substring(File_Content,1,4)) mg from $db.`file`") as $s) $src[-(int) $s['File_ID']] = ['file', $s];
    foreach (q("select * from $db.profession_meeting_attachment") as $s) $src[-(int) $s['ID'] - 10000000] = ['meeting', $s];
    foreach ($src as $lid => [$kind, $s]) {
        $n++;
        $r = $t[$lid] ?? null;
        if (!$r) { note("missing $kind row", $code, abs($lid)); continue; }
        unset($t[$lid]);
        $expP = $s['MicroProject_ID'] === null ? null : ($pm[$s['MicroProject_ID']] ?? 'UNRESOLVED');
        $cells += 3;
        if ($expP === 'UNRESOLVED') { note("$kind project dangling in source; target " . ($r['project_id'] === null ? 'null' : 'SET'), $code, $r['id']); }
        elseif ($expP != $r['project_id']) note("$kind WRONG project_id", $code, $r['id']);
        $sp = $s['Person_ID'] ?? null;
        $expPe = $sp === null ? null : ($pe[$sp] ?? 'UNRESOLVED');
        if ($expPe === 'UNRESOLVED') note("$kind person dangling in source (value " . ((int) $sp <= 0 ? $sp : 'positive') . "); target " . ($r['person_id'] === null ? 'null' : 'SET'), $code, $r['id']);
        elseif ($expPe != $r['person_id']) note("$kind WRONG person_id", $code, $r['id']);
        $ty = $s['ImageType_ID'] ?? null;
        $expT = $ty === null ? null : ($lmap[$cid][$ty] ?? 'UNRESOLVED');
        if ($expT === 'UNRESOLVED') note("$kind type dangling in source; target " . ($r['attachment_type_id'] === null ? 'null' : 'SET'), $code, $r['id']);
        elseif ($expT != $r['attachment_type_id']) note("$kind WRONG attachment_type_id", $code, $r['id']);
        if ($r['legacy_source'] !== $kind) note("$kind wrong legacy_source", $code, $r['id']);
        if ($r['deleted_at'] !== null) note('deleted_at set', $code, $r['id']);
        if ($r['profession_meeting_id'] !== null && $kind !== 'meeting') note('meeting id set', $code, $r['id']);
        foreach (['uploaded_by_account_id', 'uploaded_at'] as $c) if ($r[$c] !== null && $kind !== 'meeting') note("$c set without source", $code, $r['id']);
        if ($kind === 'image') {
            $p = (string) $s['image_NewPath'];
            $cells += 3;
            $sp2 = (string) $r['stored_path'];
            if (!str_ends_with($sp2, '/' . $p)) {
                $b = str_ends_with($sp2, '/' . trim($p)) ? 'trimmed' : (str_ends_with($sp2, '/' . basename(str_replace('\\', '/', $p))) ? 'directory part dropped' : (mb_strlen($sp2) >= 500 ? 'truncated' : 'different'));
                note("image stored_path does not end with source path ($b)", $code, $r['id']);
            } else {
                $pre = substr($sp2, 0, strlen($sp2) - strlen($p));
                note("info image stored_path prefix '$pre'", $code, $r['id']);
            }
            if ((string) $r['original_name'] !== $p && (string) $r['original_name'] !== trim(basename(str_replace('\\', '/', $p)))) note('image original_name differs' . (mb_strlen($r['original_name']) == 255 ? ' (truncated)' : (trim($p) !== $p ? ' (whitespace)' : '')), $code, $r['id']);
            $ext = strtolower(pathinfo(trim($p), PATHINFO_EXTENSION));
            if (strtolower((string) $r['extension']) !== $ext) note('image extension differs' . (strlen($ext) > 10 ? ' (longer than 10)' : ($ext === '' ? ' (source none)' : '')), $code, $r['id']);
            if (preg_match('/[\/\\\\]/', $p)) note('info image path contains a directory', $code, $r['id']);
            if (str_contains($p, '..') && preg_match('~(^|[/\\\\])\.\.([/\\\\]|$)~', $p)) note('image path traversal', $code, $r['id']);
            @$allPaths[$sp2][] = [$r['person_id'], $r['project_id'], $code, $r['id']];
        } elseif ($kind === 'file') {
            $cells += 2;
            if ((string) $r['original_name'] !== (string) $s['File_Name']) note('file original_name differs' . (trim($s['File_Name']) === $r['original_name'] ? ' (trim)' : ''), $code, $r['id']);
            $ext = strtolower(pathinfo(trim((string) $s['File_Name']), PATHINFO_EXTENSION));
            if (strtolower((string) $r['extension']) !== $ext) note('file extension differs', $code, $r['id']);
            $path = ROOT . '/storage/app/private/' . $r['stored_path'];
            $known[str_replace('\\', '/', $r['stored_path'])] = 1;
            if (!is_file($path)) note('file: stored file MISSING', $code, $r['id']);
            else {
                $h = hash_file('sha256', $path);
                if ($h !== $s['h'] || filesize($path) !== (int) $s['len']) note('file: bytes DIFFER', $code, $r['id']);
                else note('info file: bytes identical, length ' . ((int) $s['len'] === 0 ? 'ZERO (empty content)' : 'non-zero'), $code, $r['id']);
            }
        }
    }
    foreach ($t as $r) note('extra target attachment', $code, $r['id']);
    // image files present locally?
    $exist = 0; $tot = 0;
    foreach (q("select stored_path from $T.attachments where city_id=$cid and legacy_source='image'") as $r) { $tot++; if (is_file(ROOT . '/storage/app/private/' . $r['stored_path'])) $exist++; }
    echo "$code image files present under storage/app/private: $exist of $tot\n";
}
$base = ROOT . '/storage/app/private/attachments';
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
$files = 0; $orph = 0;
foreach ($it as $f) { $files++; $rel = str_replace('\\', '/', substr($f->getPathname(), strlen(ROOT . '/storage/app/private/'))); if (!isset($known[$rel])) $orph++; }
echo "files under attachments/: $files, not referenced by a migrated 'file' row: $orph\n";
$sharedP = 0; $sharedDiff = [];
foreach ($allPaths as $p => $x) if (count($x) > 1) { $sharedP++; if (count(array_unique(array_map(fn($y) => $y[0] . '|' . $y[1], $x))) > 1) $sharedDiff[$x[0][2]][] = implode('+', array_column($x, 3)); if (count(array_unique(array_column($x, 2))) > 1) @$cross++; }
echo "stored paths used by more than one row: $sharedP; with different person/project: ", json_encode(array_map(fn($a) => [count($a), array_slice($a, 0, 3)], $sharedDiff)), " cross-city shared paths: ", $cross ?? 0, "\n";
echo "source attachment rows=$n cells=$cells\n";
foreach ($F as $k => $p) { echo $k, ' |'; foreach ($p as $c => [$x, $ids]) echo " $c=$x(", implode(',', $ids), ")"; echo "\n"; }
