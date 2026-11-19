<?php

/*
 * Generates the M2 migrations from the approved unified schema (docs/planning/m1/unified-schema-v1.1.json).
 * One migration per schema group creates the tables; a final migration adds every foreign key, so the
 * table order never matters. Re-run after an approved schema change; do not edit the generated files.
 *
 * Usage: php tools/schema/generate-migrations.php
 */

$root = dirname(__DIR__, 2);
$schemaFile = $root.'/docs/planning/m1/unified-schema-v1.1.json';
$schema = json_decode(file_get_contents($schemaFile), true);
$approved = 'a783e7f335baac175f1fa3bd01905725200b54978c53a4a4b6f7f72b6e93047e';
if ($schema['sha256'] !== $approved) {
    fwrite(STDERR, "Schema SHA {$schema['sha256']} is not the approved v1.1 SHA.\n");
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
foreach ($order as $group) {
    if (empty($groups[$group])) {
        continue;
    }
    $up = [];
    $down = [];
    foreach ($groups[$group] as $name => $t) {
        $lines = [];
        foreach ($t['columns'] as $c) {
            $lines[] = '            '.columnLine($c, $t['scope']);
            if (! empty($c['unique'])) {
                $lines[] = "            \$table->unique('{$c['name']}', '".shortName($name, $c['name'].'_uq')."');";
            } elseif (! empty($c['index'])) {
                $lines[] = "            \$table->index('{$c['name']}', '".shortName($name, $c['name'].'_ix')."');";
            }
            if (isset($c['references'])) {
                $fks[$name][] = [$c['name'], $c['references']];
                if (empty($c['index']) && empty($c['unique'])) {
                    $lines[] = "            \$table->index('{$c['name']}', '".shortName($name, $c['name'].'_ix')."');";
                }
            }
        }
        foreach ($t['unique'] ?? [] as $u) {
            $lines[] = "            \$table->unique(['".implode("', '", $u)."'], '".shortName($name, implode('_', $u).'_uq')."');";
        }
        $up[] = "        Schema::create('{$name}', function (Blueprint \$table) {\n".implode("\n", $lines)."\n        });";
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

function migration(string $what, string $up, string $down, string $sha): string
{
    return <<<PHP
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Unified schema v1.1 — {$what}.
 * Generated by tools/schema/generate-migrations.php from docs/planning/m1/unified-schema-v1.1.json
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
