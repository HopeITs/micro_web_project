<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The database built by the migrations must match the approved unified schema (database/schema/approved.php) exactly.
 */
class UnifiedSchemaTest extends TestCase
{
    use RefreshDatabase;


    private function schema(): array
    {
        return \App\Support\UnifiedSchema::load();
    }

    public function test_the_schema_file_is_the_approved_version(): void
    {
        $this->assertSame(\App\Support\UnifiedSchema::approved()['sha256'], $this->schema()['sha256']);
        $this->assertSame('7cbd347ebd5f3db58796724c01ab9339b263a1e1e300d7be4287fdde2098266a', \App\Support\UnifiedSchema::approved()['sha256'], 'approved.php changed: record the new approval first');
    }

    public function test_every_approved_table_and_column_exists(): void
    {
        $tables = $this->schema()['tables'];
        $this->assertCount(137, $tables);

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
