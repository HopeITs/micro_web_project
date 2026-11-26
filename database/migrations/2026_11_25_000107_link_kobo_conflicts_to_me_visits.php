<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Unified schema v1.7 (approved 25 November 2026), hand-written for databases built before it: the visit of a Kobo
 * sync conflict is an M&E visit. A database created from the v1.7 migrations already has the column, so this does
 * nothing there. The old values pointed at the wrong table and are cleared; the transfer fills them from the source.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('kobo_sync_conflicts', 'visit_id')) {
            return;
        }
        $this->dropForeignOn('visit_id');
        DB::table('kobo_sync_conflicts')->update(['visit_id' => null]);
        Schema::table('kobo_sync_conflicts', fn (Blueprint $table) => $table->renameColumn('visit_id', 'me_visit_id'));
        Schema::table('kobo_sync_conflicts', fn (Blueprint $table) => $table->foreign('me_visit_id', 'kobo_sync_conflicts_me_visit_id_fk')
            ->references('id')->on('me_visits')->restrictOnDelete()->restrictOnUpdate());
    }

    public function down(): void
    {
        // Not reversible: the old column pointed at the wrong table.
    }

    private function dropForeignOn(string $column): void
    {
        foreach (Schema::getForeignKeys('kobo_sync_conflicts') as $key) {
            if ($key['columns'] === [$column]) {
                Schema::table('kobo_sync_conflicts', fn (Blueprint $table) => $table->dropForeign($key['name'] ?? [$column]));
            }
        }
    }
};
