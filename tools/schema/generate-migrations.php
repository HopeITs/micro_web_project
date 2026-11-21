<?php

/*
 * Generates the M2 migrations from the approved unified schema (the version declared in database/schema/approved.php).
 * One migration per schema group creates the tables; a final migration adds every foreign key, so the
 * table order never matters. Re-run after an approved schema change; do not edit the generated files.
 *
 * Databases already built from v1.4 exist, so a table that a later addendum adds as new (its `addendum` text starts
 * with the version, e.g. "v1.5 ... جديد") goes into its own migration per version, numbered after the foreign-key
 * migration (v1.5 → 000105) and carrying its own foreign keys; `php artisan migrate` then adds only those tables.
 * The optional per-table `indexes` key lists composite non-unique indexes.
 *
 * Usage: php tools/schema/generate-migrations.php
 */

$root = dirname(__DIR__, 2);
$approvedSchema = require $root.'/database/schema/approved.php';
$schemaFile = $root.'/'.$approvedSchema['file'];
$schema = json_decode(file_get_contents($schemaFile), true);
$approved = $approvedSchema['sha256'];
if ($schema['sha256'] !== $approved) {
    fwrite(STDERR, "Schema SHA {$schema['sha256']} is not the approved SHA.\n");
    exit(1);
}

$dir = $root.'/database/migrations';
foreach (glob($dir.'/2026_11_20_*.php') as $old) {
    unlink($old);
}

function shortName(string $table, string $suffix): string
{
    $name = $table.'_'.$suffix;

    return strlen($name) <= 60 ? $name : substr($table, 0, 40).'_'.substr(md5($name), 0, 8).'_'.substr($suffix, -6);
}

function columnLine(array $c, string $scope): string
{
    $n = $c['name'];
    [$type, $arg] = array_pad(explode(':', $c['type'], 2), 2, null);
    $precision = $scope === 'explicit' ? ', 6' : '';
    $line = match ($type) {
        'id' => "\$table->id('{$n}')",
        'fk' => "\$table->unsignedBigInteger('{$n}')",
        'str' => "\$table->string('{$n}', {$arg})",
        'char' => "\$table->char('{$n}', {$arg})",
        'text' => "\$table->text('{$n}')",
        'json' => "\$table->json('{$n}')",
        'int' => "\$table->integer('{$n}')",
        'uint' => "\$table->unsignedInteger('{$n}')",
        'smallint' => "\$table->smallInteger('{$n}')",
        'tinyint' => "\$table->tinyInteger('{$n}')",
        'bool' => "\$table->boolean('{$n}')",
        'date' => "\$table->date('{$n}')",
        'datetime' => "\$table->dateTime('{$n}'{$precision})",
        'dec' => "\$table->decimal('{$n}', ".str_replace(',', ', ', $arg).')',
        'enum' => "\$table->enum('{$n}', ['".implode("', '", explode(',', $arg))."'])",
        'varbinary' => "\$table->binary('{$n}', {$arg})",
        'binary' => "\$table->binary('{$n}', {$arg}, true)",
        default => throw new RuntimeException("Unknown type {$c['type']}"),
    };
    if ($type === 'id') {
        return $line.';';
    }
    if ($c['nullable']) {
        $line .= '->nullable()';
    } elseif ($type === 'bool') {
        $line .= $n === 'is_active' ? '->default(true)' : '->default(false)';
    } elseif ($n === 'sort_order') {
        $line .= '->default(0)';
    }

    return $line.';';
}

$groups = [];
foreach ($schema['tables'] as $name => $t) {
    $groups[$t['group']][$name] = $t;
}
// The staff group is created first: other groups reference web_accounts, but only through the final FK migration.
$order = array_keys($schema['groups']);
$seq = 1;
$fks = [];

/** The version that added this table as new after v1.4 (e.g. "1.5"), or null for a table of the v1.4 base. */
function addedIn(array $t): ?string
{
    if (preg_match('/\Av(\d+)\.(\d+)\b/u', (string) ($t['addendum'] ?? ''), $m) !== 1 || ! str_contains($t['addendum'], 'جديد')) {
        return null;
    }

    return version_compare($m[1].'.'.$m[2], '1.4', '>') ? $m[1].'.'.$m[2] : null;
}

$additive = [];
foreach ($groups as $group => $tables) {
    foreach ($tables as $name => $t) {
        if (($version = addedIn($t)) !== null) {
            $additive[$version][$name] = $t;
            unset($groups[$group][$name]);
        }
    }
}

/** The Schema::create block of one table; its foreign keys go to $fks, or inline when $inlineFks. */
function createBlock(string $name, array $t, array &$fks, bool $inlineFks): string
{
    $lines = [];
    $inline = [];
    foreach ($t['columns'] as $c) {
        $lines[] = '            '.columnLine($c, $t['scope']);
        if (! empty($c['unique'])) {
            $lines[] = "            \$table->unique('{$c['name']}', '".shortName($name, $c['name'].'_uq')."');";
        } elseif (! empty($c['index'])) {
            $lines[] = "            \$table->index('{$c['name']}', '".shortName($name, $c['name'].'_ix')."');";
        }
        if (isset($c['references'])) {
            if ($inlineFks) {
                $inline[] = "            \$table->foreign('{$c['name']}', '".shortName($name, $c['name'].'_fk')."')->references('id')->on('{$c['references']}')->restrictOnDelete()->restrictOnUpdate();";
            } else {
                $fks[$name][] = [$c['name'], $c['references']];
            }
            if (empty($c['index']) && empty($c['unique'])) {
                $lines[] = "            \$table->index('{$c['name']}', '".shortName($name, $c['name'].'_ix')."');";
            }
        }
    }
    foreach ($t['unique'] ?? [] as $u) {
        $lines[] = "            \$table->unique(['".implode("', '", $u)."'], '".shortName($name, implode('_', $u).'_uq')."');";
    }
    foreach ($t['indexes'] ?? [] as $ix) {
        $lines[] = "            \$table->index(['".implode("', '", $ix)."'], '".shortName($name, implode('_', $ix).'_ix')."');";
    }

    return "        Schema::create('{$name}', function (Blueprint \$table) {\n".implode("\n", [...$lines, ...$inline])."\n        });";
}

foreach ($order as $group) {
    if (empty($groups[$group])) {
        continue;
    }
    $up = [];
    $down = [];
    foreach ($groups[$group] as $name => $t) {
        $up[] = createBlock($name, $t, $fks, false);
        $down[] = "        Schema::dropIfExists('{$name}');";
    }
    $file = sprintf('%s/2026_11_20_%06d_create_%s_tables.php', $dir, $seq++, $group);
    file_put_contents($file, migration($group, implode("\n\n", $up), implode("\n", array_reverse($down)), $schema['sha256']));
}

$fkUp = [];
$fkDown = [];
foreach ($fks as $table => $list) {
    $add = [];
    $drop = [];
    foreach ($list as [$col, $ref]) {
        $fkName = shortName($table, $col.'_fk');
        $add[] = "            \$table->foreign('{$col}', '{$fkName}')->references('id')->on('{$ref}')->restrictOnDelete()->restrictOnUpdate();";
        $drop[] = "            \$table->dropForeign('{$fkName}');";
    }
    $fkUp[] = "        Schema::table('{$table}', function (Blueprint \$table) {\n".implode("\n", $add)."\n        });";
    $fkDown[] = "        Schema::table('{$table}', function (Blueprint \$table) {\n".implode("\n", $drop)."\n        });";
}
file_put_contents(sprintf('%s/2026_11_20_%06d_add_unified_foreign_keys.php', $dir, 99), migration('foreign keys', implode("\n\n", $fkUp), implode("\n\n", array_reverse($fkDown)), $schema['sha256']));

// Tables new in a later addendum: one migration per version, after the foreign keys, so existing v1.4 databases add only them.
uksort($additive, 'version_compare');
$inlineCount = 0;
foreach ($additive as $version => $tables) {
    [$major, $minor] = array_map('intval', explode('.', $version));
    $up = [];
    $down = [];
    foreach ($tables as $name => $t) {
        $up[] = createBlock($name, $t, $fks, true);
        $down[] = "        Schema::dropIfExists('{$name}');";
        $inlineCount += count(array_filter($t['columns'], fn ($c) => isset($c['references'])));
    }
    $slug = 'v'.$major.'_'.$minor;
    file_put_contents(sprintf('%s/2026_11_20_%06d_create_%s_tables.php', $dir, $major * 100 + $minor, $slug), migration("tables new in v{$version}", implode("\n\n", $up), implode("\n", array_reverse($down)), $schema['sha256']));
}

function migration(string $what, string $up, string $down, string $sha): string
{
    return <<<PHP
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Unified schema {$GLOBALS['approvedSchema']['version']} — {$what}.
 * Generated by tools/schema/generate-migrations.php from {$GLOBALS['approvedSchema']['file']}
 * (approved SHA-256 {$sha}). Do not edit by hand.
 */
return new class extends Migration
{
    public function up(): void
    {
{$up}
    }

    public function down(): void
    {
{$down}
    }
};

PHP;
}

echo 'generated '.($seq - 1).' table migrations and 1 foreign-key migration ('.array_sum(array_map('count', $fks))." foreign keys)\n";
echo 'generated '.count($additive).' addendum migration(s) for '.array_sum(array_map('count', $additive))." new table(s) ({$inlineCount} foreign keys inline)\n";
