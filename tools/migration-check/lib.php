<?php
// Shared read-only helpers for the independent reconciliation.
ini_set('memory_limit', '3G');
mb_internal_encoding('UTF-8');
// Settings come from the environment: MIG_TARGET (required), MIG_SOURCE_PREFIX (default m5src_), MIG_DB_USER and MIG_DB_PASSWORD.
define('PREFIX', getenv('MIG_SOURCE_PREFIX') ?: 'm5src_');
define('SRC', array_combine($codes = ['ALP', 'DAM', 'HMS', 'LAT', 'WAD'], array_map(fn ($c) => PREFIX.strtolower($c), $codes)));
define('TGT', preg_match('/^[A-Za-z0-9_]+$/', (string) getenv('MIG_TARGET')) ? getenv('MIG_TARGET') : exit("Set MIG_TARGET to the migrated database.
"));
define('ROOT', dirname(__DIR__, 2));
// Findings files go to storage (ignored by git), never next to the scripts.
define('OUT', dirname(__DIR__, 2).'/storage/app/private/migration-check');
if (! is_dir(OUT)) {
    mkdir(OUT, 0770, true);
}

function db(bool $unbuffered = false): PDO
{
    static $c = [];
    $k = $unbuffered ? 'u' : 'b';
    if (!isset($c[$k])) {
        $p = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', getenv('MIG_DB_USER') ?: 'root', getenv('MIG_DB_PASSWORD') ?: '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => !$unbuffered,
        ]);
        $p->exec('SET SESSION TRANSACTION READ ONLY');
        $c[$k] = $p;
    }
    return $c[$k];
}

function q(string $sql, array $p = []): array
{
    if (!preg_match('/^\s*(select|show)\b/i', $sql)) {
        throw new RuntimeException('read only: ' . substr($sql, 0, 40));
    }
    $s = db()->prepare($sql);
    $s->execute($p);
    return $s->fetchAll();
}

function spec(): array
{
    static $j;
    return $j ??= json_decode(file_get_contents(ROOT . '/docs/planning/m1/'.basename((require ROOT.'/database/schema/approved.php')['file']).''), true);
}

function cols(string $db, string $table): array
{
    static $c = [];
    return $c["$db.$table"] ??= q('select column_name n, data_type t, column_type ct, column_key k, is_nullable nu from information_schema.columns where table_schema=? and table_name=? order by ordinal_position', [$db, $table]);
}

function tables(string $db): array
{
    static $c = [];
    return $c[$db] ??= array_column(q('select table_name n from information_schema.tables where table_schema=? order by table_name', [$db]), 'n');
}

/** Real name of a source table / column (case-insensitive). */
function tname(string $db, string $table): ?string
{
    foreach (tables($db) as $t) {
        if (strcasecmp($t, $table) === 0) return $t;
    }
    return null;
}

function cname(string $db, string $table, string $col): ?string
{
    foreach (cols($db, $table) as $c) {
        if (strcasecmp($c['n'], $col) === 0) return $c['n'];
    }
    return null;
}

function cities(): array
{
    static $c;
    if ($c === null) {
        $c = [];
        foreach (q('select * from ' . TGT . '.cities') as $r) $c[$r['code']] = (int) $r['id'];
    }
    return $c;
}
