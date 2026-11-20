<?php

namespace App\Support;

use RuntimeException;

/** The approved unified schema (database/schema/approved.php), loaded once and checked against its SHA-256. */
final class UnifiedSchema
{
    private static ?array $schema = null;

    /** @return array{version:string, file:string, sha256:string} */
    public static function approved(): array
    {
        return require dirname(__DIR__, 2).'/database/schema/approved.php';
    }

    public static function load(): array
    {
        if (self::$schema === null) {
            $approved = self::approved();
            $schema = json_decode(file_get_contents(dirname(__DIR__, 2).'/'.$approved['file']), true);
            if (($schema['sha256'] ?? null) !== $approved['sha256']) {
                throw new RuntimeException("The schema file is not the approved {$approved['version']} ({$approved['sha256']}).");
            }
            self::$schema = $schema;
        }

        return self::$schema;
    }

    public static function tables(): array
    {
        return self::load()['tables'];
    }
}
