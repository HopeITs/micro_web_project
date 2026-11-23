<?php

/*
 * M4.20 official papers: builds the blank download set from the owner's papers folder (owner decision 21 Nov 2026).
 *
 *   php tools/papers/clean-templates.php "<source folder>" "<output folder>"
 *
 * - Reads the 242 files listed in docs/planning/official-papers/inventory.json (paper F01–F33, city, version).
 * - Word files: every typed text box is emptied (sample names, national numbers, amounts, dates and the hint labels); the
 *   full-page background picture and the box positions stay, so staff can still type into the boxes. The document author
 *   fields are cleared too. PDFs are copied unchanged (they have no fillable text).
 * - Files are stored once by content (<output>/<sha256>.<ext>), so a paper identical in several cities is kept once.
 * - Writes config/papers.php: the 33 papers and one entry per paper, city, place and version with the cleaned file's SHA-256.
 *   `php artisan papers:import <output folder>` then installs the files into storage/app/papers and refuses any mismatch.
 *
 * City rules (owner, 21 Nov): Idlib (013 IDL) is part of Aleppo, kept as place IDL; the Al Wadi folder is city WAD; the
 * non-Syrian folder takes its city from the file name. The LAT file with a stray kasra in its name is an Aleppo copy.
 * Nothing from the papers' text is written to the repository: config/papers.php holds only names, keys and hashes.
 */

declare(strict_types=1);

/** A fixed time for the rewritten parts, so running the tool twice gives byte-identical files (and the same SHA-256 in config/papers.php). */
const FIXED_TIME = 1795000000;

if ($argc < 3) {
    fwrite(STDERR, "Usage: php tools/papers/clean-templates.php <source folder> <output folder>\n");
    exit(1);
}
[$source, $output] = [rtrim($argv[1], '/\\'), rtrim($argv[2], '/\\')];
$root = dirname(__DIR__, 2);
$inventory = json_decode((string) file_get_contents($root.'/docs/planning/official-papers/inventory.json'), true);
if (! is_dir($source) || ! is_array($inventory)) {
    fwrite(STDERR, "Source folder or inventory missing.\n");
    exit(1);
}
if (! is_dir($output) && ! mkdir($output, 0775, true)) {
    fwrite(STDERR, "Cannot create $output\n");
    exit(1);
}

$cityOf = static function (array $file): ?array {
    $path = $file['path'];
    if (str_contains($path, 'ِLAT')) {
        return ['ALP', null];                       // misnamed Aleppo copy in the LAT folder (prints حلب)
    }
    $code = $file['city_folder'] === 'NON_SYRIAN' ? ($file['name_city'] ?? null) : $file['city_folder'];

    return match ($code) {
        'ALP', 'HMS', 'LAT' => [$code, null],
        'DMS' => ['DAM', null],                     // the papers folder says DMS, the database says DAM
        'IDL' => ['ALP', 'IDL'],
        'WADI' => ['WAD', null],
        default => null,
    };
};

/** Empties every text run inside the text boxes of one Word XML part. */
$clearBoxes = static function (string $xml): string {
    return (string) preg_replace_callback('#<w:txbxContent\b.*?</w:txbxContent>#s', static function (array $box): string {
        return (string) preg_replace('#(<w:t(?:\s[^>]*)?>)[^<]*(</w:t>)#', '$1$2', $box[0]);
    }, $xml);
};

$cleanDocx = static function (string $from, string $to) use ($clearBoxes): void {
    copy($from, $to);
    $zip = new ZipArchive;
    if ($zip->open($to) !== true) {
        throw new RuntimeException("Cannot open $from");
    }
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = (string) $zip->getNameIndex($i);
        if (preg_match('#^word/(document|header\d*|footer\d*)\.xml$#', $name) === 1) {
            $zip->addFromString($name, $clearBoxes((string) $zip->getFromIndex($i)));
            $zip->setMtimeName($name, FIXED_TIME);
        } elseif ($name === 'docProps/core.xml') {
            $core = (string) $zip->getFromIndex($i);
            $core = (string) preg_replace('#(<(dc:creator|cp:lastModifiedBy|dc:title|dc:subject|cp:keywords|dc:description)[^>]*>)[^<]*(</\2>)#', '$1$3', $core);
            $zip->addFromString($name, $core);
            $zip->setMtimeName($name, FIXED_TIME);
        }
    }
    $zip->close();
    // A left-over text run in any box would be a leak: verify before keeping the file.
    $check = new ZipArchive;
    $check->open($to);
    $document = (string) $check->getFromName('word/document.xml');
    $check->close();
    preg_match_all('#<w:txbxContent\b.*?</w:txbxContent>#s', $document, $boxes);
    foreach ($boxes[0] as $box) {
        if (preg_match('#<w:t(?:\s[^>]*)?>[^<]+</w:t>#', $box) === 1) {
            throw new RuntimeException("Text left in a box of $from");
        }
    }
};

$forms = [];
foreach ($inventory['forms'] as $form) {
    $forms[$form['id']] = ['key' => $form['key'], 'name_ar' => $form['name_ar'], 'group' => $form['group'], 'fillable' => (bool) $form['fillable']];
}

$entries = [];
$cleaned = [];
$skipped = [];
$tmp = $output.'/.tmp';
@mkdir($tmp);
foreach ($inventory['files'] as $file) {
    $path = $source.'/'.$file['path'];
    $city = $cityOf($file);
    if (! is_file($path) || $city === null || ! isset($forms[$file['form_id']])) {
        $skipped[] = $file['path'].(is_file($path) ? ' (no city or paper)' : ' (missing)');

        continue;
    }
    $format = strtolower($file['format']);
    // Identical source files (the same paper copied to several city folders) are cleaned once, so they share one output file.
    $sourceSha = hash_file('sha256', $path);
    if (! isset($cleaned[$sourceSha])) {
        $work = $tmp.'/'.bin2hex(random_bytes(6)).'.'.$format;
        $format === 'docx' ? $cleanDocx($path, $work) : copy($path, $work);
        $cleaned[$sourceSha] = hash_file('sha256', $work);
        $target = $output.'/'.$cleaned[$sourceSha].'.'.$format;
        is_file($target) ? unlink($work) : rename($work, $target);
    }
    $sha = $cleaned[$sourceSha];
    $target = $output.'/'.$sha.'.'.$format;

    $variant = match ($file['variant']) {
        'non_syrian' => 'non_syrian', 'generic_syr' => 'generic', default => 'syrian'
    };
    [$code, $place] = $city;
    $slot = implode('|', [$file['form_id'], $code, $place ?? '', $variant, $format]);
    if (isset($entries[$slot])) {
        if ($entries[$slot]['sha256'] !== $sha) {
            $skipped[] = $file['path'].' (second copy for '.str_replace('|', ' ', $slot).', kept the first)';
        }

        continue;
    }
    $entries[$slot] = [
        'form' => $file['form_id'], 'city' => $code, 'place' => $place, 'variant' => $variant, 'format' => $format,
        'sha256' => $sha, 'bytes' => filesize($target), 'source' => $file['path'],
    ];
}
@rmdir($tmp);
ksort($entries);

$export = static fn ($value) => $value === null ? 'null' : var_export($value, true);
$lines = ["<?php\n", '/*', ' * M4.20 official papers: generated by tools/papers/clean-templates.php from the owner\'s papers folder (21 Nov 2026).', ' * Do not edit by hand. Files: storage/app/papers/<sha256>.<format>, installed by `php artisan papers:import <folder>`.', ' */', '', 'return [', "    'forms' => ["];
foreach ($forms as $id => $form) {
    $lines[] = '        '.$export($id).' => ['.implode(', ', array_map(fn ($k, $v) => $export($k).' => '.$export($v), array_keys($form), $form)).'],';
}
$lines[] = '    ],';
$lines[] = "    'files' => [";
foreach ($entries as $entry) {
    $lines[] = '        ['.implode(', ', array_map(fn ($k, $v) => $export($k).' => '.$export($v), array_keys($entry), $entry)).'],';
}
$lines[] = '    ],';
$lines[] = '];';
file_put_contents($root.'/config/papers.php', implode("\n", $lines)."\n");

$unique = count(array_unique(array_column($entries, 'sha256')));
echo count($entries)." entries, $unique files in $output\n";
foreach ($skipped as $line) {
    echo "skipped: $line\n";
}
