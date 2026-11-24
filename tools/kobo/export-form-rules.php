<?php
/*
 * Reads the XLSForm exports of the Kobo forms (one <form_uid>.xlsx each; sheets survey / choices / settings) and writes
 * resources/kobo/form-rules.json: for every field path the rules the question catalogue lacks — required, relevant (show-if),
 * constraint (+ message), hint and appearance. ${name} references are resolved to field paths. Input is read-only.
 *
 *   php tools/kobo/export-form-rules.php "D:\MyProjects\micro_web\kobo m&e forms xls"
 */
require __DIR__.'/../../vendor/autoload.php';

use OpenSpout\Reader\XLSX\Reader;

$dir = rtrim($argv[1] ?? '', '\/');
if ($dir === '' || ! is_dir($dir)) {
    fwrite(STDERR, "usage: php tools/kobo/export-form-rules.php <folder of XLSForm files>\n");
    exit(1);
}
$catalog = json_decode((string) file_get_contents(__DIR__.'/../../resources/kobo/question-catalog.json'), true);
$out = [];
$report = ['forms' => 0, 'fields' => 0, 'unknown_reference' => [], 'not_in_catalog' => [], 'group_relevant' => 0, 'unsupported' => []];

foreach (glob($dir.'/*.xlsx') as $file) {
    $uid = basename($file, '.xlsx');
    $reader = new Reader();
    $reader->open($file);
    $rows = [];
    foreach ($reader->getSheetIterator() as $sheet) {
        if ($sheet->getName() !== 'survey') {
            continue;
        }
        $head = null;
        foreach ($sheet->getRowIterator() as $row) {
            $cells = array_map(fn ($c) => $c->getValue(), $row->getCells());
            if ($head === null) {
                $head = array_map(fn ($h) => trim((string) $h), $cells);

                continue;
            }
            $rows[] = array_combine($head, array_pad(array_map(fn ($v) => is_string($v) ? trim($v) : $v, $cells), count($head), ''));
        }
    }
    $reader->close();

    $stack = [];
    $groupRelevant = [];
    $fields = [];
    foreach ($rows as $r) {
        $type = (string) ($r['type'] ?? '');
        $name = (string) ($r['name'] ?? '');
        if (str_starts_with($type, 'begin')) {
            $stack[] = $name;
            $groupRelevant[] = (string) ($r['relevant'] ?? '');
            $report['group_relevant'] += ($r['relevant'] ?? '') !== '' ? 1 : 0;

            continue;
        }
        if (str_starts_with($type, 'end')) {
            array_pop($stack);
            array_pop($groupRelevant);

            continue;
        }
        if ($name === '' || in_array($type, ['note', 'calculate', 'start', 'end', 'today', 'deviceid', 'username', 'phonenumber', 'audit'], true)) {
            continue;
        }
        $path = implode('/', [...$stack, $name]);
        $label = '';
        foreach (['label::Arabic (arab)', 'label'] as $key) {
            if (($r[$key] ?? '') !== '') {
                $label = (string) $r[$key];
                break;
            }
        }
        $fields[$path] = ['name' => $name, 'group' => array_filter($groupRelevant), 'row' => $r, 'label' => $label];
    }
    $byName = [];
    foreach ($fields as $path => $f) {
        $byName[$f['name']][] = $path;
    }
    $resolve = function (string $expr, string $from) use ($byName, $uid, &$report): string {
        return preg_replace_callback('/\$\{([^}]+)\}/', function ($m) use ($byName, $from, $uid, &$report) {
            $paths = $byName[$m[1]] ?? [];
            if ($paths === []) {
                $report['unknown_reference'][] = "$uid: ".$m[1];

                return $m[0];
            }
            // Several fields may share a name (repeat groups): prefer the one in the same group as the field itself.
            $group = substr($from, 0, (int) strrpos($from, '/'));
            foreach ($paths as $p) {
                if (substr($p, 0, (int) strrpos($p, '/')) === $group) {
                    return '${'.$p.'}';
                }
            }

            return '${'.$paths[0].'}';
        }, $expr);
    };
    $known = array_column($catalog[$uid]['fields'] ?? [], 'path');
    foreach ($fields as $path => $f) {
        $r = $f['row'];
        $rule = [];
        $required = strtolower((string) ($r['required'] ?? ''));
        if (in_array($required, ['true', '1', 'yes', 'true()'], true)) {
            $rule['required'] = true;
        }
        $relevant = array_values(array_filter([...array_map(fn ($g) => $resolve($g, $path), $f['group']), $resolve((string) ($r['relevant'] ?? ''), $path)]));
        if ($relevant !== []) {
            $rule['relevant'] = $relevant;
        }
        if (($r['constraint'] ?? '') !== '') {
            $rule['constraint'] = $resolve((string) $r['constraint'], $path);
            foreach (['constraint_message::Arabic (arab)', 'constraint_message'] as $key) {
                if (($r[$key] ?? '') !== '') {
                    $rule['message'] = (string) $r[$key];
                    break;
                }
            }
        }
        foreach (['hint::Arabic (arab)', 'hint'] as $key) {
            if (($r[$key] ?? '') !== '') {
                $rule['hint'] = (string) $r[$key];
                break;
            }
        }
        if (($r['appearance'] ?? '') !== '') {
            $rule['appearance'] = (string) $r['appearance'];
        }
        foreach ([...($rule['relevant'] ?? []), $rule['constraint'] ?? ''] as $expr) {
            $stripped = preg_replace(['/\$\{[^}]+\}/', "/'[^']*'/", '/selected\(|\)|,|\band\b|\bor\b|!=|=|\s+/'], '', (string) $expr);
            if (trim((string) $stripped) !== '') {
                $report['unsupported'][] = "$uid $path: $expr";
            }
        }
        if ($rule !== []) {
            $out[$uid][$path] = $rule;
        }
        $report['fields']++;
        if (! in_array($path, $known, true) && ! in_array($f['row']['type'], ['geopoint'], true)) {
            $report['not_in_catalog'][] = "$uid $path";
        }
    }
    $report['forms']++;
}
ksort($out);
file_put_contents(__DIR__.'/../../resources/kobo/form-rules.json', json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo json_encode(['forms' => $report['forms'], 'fields' => $report['fields'], 'group_relevant' => $report['group_relevant'], 'unknown_reference' => count($report['unknown_reference']), 'not_in_catalog' => count($report['not_in_catalog']), 'unsupported' => count($report['unsupported'])]), "\n";
foreach (['unknown_reference', 'not_in_catalog', 'unsupported'] as $k) {
    foreach (array_slice(array_filter($report[$k], fn ($l) => ! str_contains($l, "aLP57orWHmvSSrYgGyzWLf")), 0, 8) as $line) {
        echo "$k: $line\n";
    }
}
