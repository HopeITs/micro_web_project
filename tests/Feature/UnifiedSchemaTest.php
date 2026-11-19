<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The database built by the migrations must match the approved unified schema v1.1 exactly.
 */
class UnifiedSchemaTest extends TestCase
{
    use RefreshDatabase;

    private const APPROVED_SHA = 'a783e7f335baac175f1fa3bd01905725200b54978c53a4a4b6f7f72b6e93047e';

    private function schema(): array
    {
        return json_decode(file_get_contents(base_path('docs/planning/m1/unified-schema-v1.1.json')), true);
    }

    public function test_the_schema_file_is_the_approved_version(): void
    {
        $this->assertSame(self::APPROVED_SHA, $this->schema()['sha256']);
    }

    public function test_every_approved_table_and_column_exists(): void
    {
        $tables = $this->schema()['tables'];
        $this->assertCount(138, $tables);

        foreach ($tables as $name => $table) {
            $this->assertTrue(Schema::hasTable($name), "missing table {$name}");
            $expected = array_column($table['columns'], 'name');
            $actual = Schema::getColumnListing($name);
            sort($expected);
            sort($actual);
            $this->assertSame($expected, $actual, "columns of {$name}");
        }
    }

    public function test_no_table_outside_the_approved_schema(): void
    {
        $approved = array_keys($this->schema()['tables']);
        $extra = array_diff(
            array_map(fn ($t) => $t['name'], Schema::getTables()),
            [...$approved, 'migrations', 'sqlite_sequence'],
        );
        $this->assertSame([], array_values($extra));
    }

    public function test_project_numbers_are_unique_per_city_only(): void
    {
        $indexes = collect(Schema::getIndexes('projects'))->where('unique', true)->pluck('columns')->all();
        $this->assertContains(['city_id', 'project_number'], $indexes);
        $this->assertNotContains(['project_number'], $indexes);
    }
}
