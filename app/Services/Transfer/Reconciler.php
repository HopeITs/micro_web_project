<?php

namespace App\Services\Transfer;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * M3.3 — independent check of one imported city. It re-reads the restored source and compares it with the
 * unified tables using only legacy ids and the recorded exceptions (never the importer's in-memory maps):
 * row counts per table, project-number sets, money totals per currency, and resolution of every reference
 * column. A difference that is not explained by a recorded exception is reported as unexplained.
 */
final class Reconciler
{
    public function check(string $cityCode, string $sourceDb): array
    {
        if (preg_match('/^[A-Za-z0-9_]+$/', $sourceDb) !== 1) {
            throw new RuntimeException('Invalid source database name.');
        }
        config(['database.connections.source.database' => $sourceDb]);
        DB::purge('source');
        $src = DB::connection('source');
        $schema = json_decode(file_get_contents(base_path('docs/planning/m1/unified-schema-v1.1.json')), true)['tables'];
        $city = DB::table('cities')->where('code', strtoupper($cityCode))->first() ?? throw new RuntimeException('City not imported.');
        $run = DB::table('migration_runs')->where('city_id', $city->id)->where('status', 'completed')->orderByDesc('id')->first() ?? throw new RuntimeException('No completed run.');
        $tables = collect($src->select('SELECT table_name AS t FROM information_schema.tables WHERE table_schema = ?', [$sourceDb]))->pluck('t')->all();
        $columns = [];
        foreach ($src->select('SELECT table_name AS t, column_name AS c FROM information_schema.columns WHERE table_schema = ?', [$sourceDb]) as $r) {
            $columns[$r->t][$r->c] = true;
        }
        $exc = fn (string $table, ?string $column, array $kinds) => DB::table('migration_exceptions')->where('migration_run_id', $run->id)
            ->where('source_table', $table)->when($column, fn ($q) => $q->where('column_name', $column))->whereIn('kind', $kinds)->count();

        $checks = [];
        $add = function (string $group, string $name, $source, $target, int $explained = 0, ?string $note = null) use (&$checks) {
            $diff = is_numeric($source) && is_numeric($target) ? round((float) $source - (float) $target, 2) : ($source === $target ? 0 : 1);
            $checks[] = ['group' => $group, 'check' => $name, 'source' => $source, 'target' => $target, 'explained' => $explained,
                'status' => $diff == 0 ? 'equal' : (abs($diff) == $explained ? 'explained' : 'unexplained'), 'note' => $note];
        };

        // 1. Rows per city table (simple sources only).
        foreach ($schema as $name => $t) {
            $source = $t['source'] ?? null;
            if ($t['scope'] !== 'city' || $source === null || ! preg_match('/^[a-z_]+$/', $source) || ! in_array($source, $tables, true)) {
                continue;
            }
            $add('rows', "{$source} → {$name}", $src->table($source)->count(), DB::table($name)->where('city_id', $city->id)->count(), $exc($source, null, ['required_missing']));
        }
        $add('rows', 'user → legacy_staff_users', $src->table('user')->count(), DB::table('legacy_staff_users')->where('city_id', $city->id)->count());
        $add('rows', 'user_notification → web_notices', $src->table('user_notification')->count(), DB::table('web_notices')->where('city_id', $city->id)->count());
        $images = in_array('image', $tables, true) ? $src->table('image')->count() : 0;
        $files = in_array('file', $tables, true) ? $src->table('file')->count() : 0;
        $meet = in_array('profession_meeting_attachment', $tables, true) ? $src->table('profession_meeting_attachment')->count() : 0;
        $add('rows', 'image + file + meeting attachments → attachments', $images + $files + $meet, DB::table('attachments')->where('city_id', $city->id)->count());

        // 2. Lists: every source list row has a map entry.
        foreach ($schema as $name => $t) {
            if (in_array($t['scope'], ['lookup', 'city_lookup'], true) && in_array($t['source'], $tables, true)) {
                $add('lists', "{$t['source']} → {$name}", $src->table($t['source'])->count(),
                    DB::table('lookup_legacy_map')->where('city_id', $city->id)->where('lookup_table', $name)->count());
            }
        }

        // 3. Project numbers and money. Expected money = sum of the source values rounded half-up per row (the approved DECIMAL(15,2) rule).
        $halfUp = fn ($query, string $column) => round($query->pluck($column)->sum(fn ($v) => $v === null ? 0 : round((float) $v, 2, PHP_ROUND_HALF_UP)), 2);
        $srcNumbers = $src->table('microproject')->pluck('MP_ID')->map(fn ($v) => (int) $v)->sort()->values()->all();
        $tgtNumbers = DB::table('projects')->where('city_id', $city->id)->pluck('project_number')->map(fn ($v) => (int) $v)->sort()->values()->all();
        $add('numbers', 'project numbers (set equality)', count($srcNumbers), count(array_intersect($srcNumbers, $tgtNumbers)), 0, $srcNumbers === $tgtNumbers ? 'identical sets' : 'sets differ');
        $add('numbers', 'next project number = highest source number + 1', (int) $src->table('microproject')->max('MP_ID') + 1,
            (int) DB::table('project_number_sequences')->where('city_id', $city->id)->value('next_number'));
        $add('money', 'requested amount (sum)', $halfUp($src->table('microproject'), 'MP_RequestedAmount'), round((float) DB::table('projects')->where('city_id', $city->id)->sum('requested_amount'), 2));
        foreach ($src->table('loan')->distinct()->pluck('Currency') as $currency) {
            $code = Transforms::currency($currency)[0];
            $add('money', "loans {$code} (sum)", $halfUp($src->table('loan')->where('Currency', $currency), 'Loan_Amount'), round((float) DB::table('loans')->where('city_id', $city->id)->where('currency', $code)->sum('amount'), 2));
        }
        foreach (['Paid' => true, 'Not Paid' => false] as $label => $paid) {
            $add('money', "instalments {$label} (sum)", $halfUp($src->table('payment')->where('Pay_IsPaid', $label), 'Pay_Amount'),
                round((float) DB::table('loan_installments')->where('city_id', $city->id)->where('is_paid', $paid)->sum('amount'), 2),
                0, 'sum of the source values rounded to 2 decimals per row (DECIMAL 15,2 rule)');
        }

        // 4. References: non-empty source values vs resolved target values, per reference column.
        $primaryKey = function (string $table) use ($src, $sourceDb): ?string {
            $cols = collect($src->select("SELECT column_name AS c FROM information_schema.key_column_usage WHERE table_schema = ? AND table_name = ? AND constraint_name = 'PRIMARY'", [$sourceDb, $table]))->pluck('c');

            return $cols->count() === 1 ? $cols->first() : null;
        };
        foreach ($schema as $name => $t) {
            $source = $t['source'] ?? null;
            if ($t['scope'] !== 'city' || $source === null || ! preg_match('/^[a-z_]+$/', $source) || ! in_array($source, $tables, true)) {
                continue;
            }
            foreach ($t['columns'] as $c) {
                if (! isset($c['references'], $c['source']) || str_contains($c['source'], '.') || ! isset($columns[$source][$c['source']])) {
                    continue;
                }
                $col = '`'.$c['source'].'`';
                $isList = ($c['transform'] ?? null) === 'lookup';
                $query = $src->table($source)->whereNotNull($c['source'])->whereRaw("TRIM(CAST({$col} AS CHAR)) <> ''");
                if ($isList) {
                    // Values of 0 or below mean "not chosen", unless the list really has that id (project states: 0 = rejected).
                    $ids = DB::table('lookup_legacy_map')->where('city_id', $city->id)->where('lookup_table', $c['references'])->where('legacy_id', '<=', 0)->pluck('legacy_id')->all();
                    $query->where(fn ($q) => $q->whereRaw("CAST({$col} AS SIGNED) > 0")->when($ids, fn ($w) => $w->orWhereIn($c['source'], $ids)));
                } else {
                    $query->whereRaw("CAST({$col} AS CHAR) <> '0'");
                }
                $nonEmpty = (clone $query)->count();
                $resolved = DB::table($name)->where('city_id', $city->id)->whereNotNull($c['name'])->count();
                // Each source row counts once: unresolved on this column, or not transferred at all while it had a value here.
                $unresolved = DB::table('migration_exceptions')->where('migration_run_id', $run->id)->where('source_table', $source)
                    ->where('column_name', $c['source'])->whereIn('kind', ['reference_missing', 'account_unmapped'])->distinct()->pluck('source_key')->all();
                $skippedKeys = array_diff(DB::table('migration_exceptions')->where('migration_run_id', $run->id)->where('source_table', $source)
                    ->where('kind', 'required_missing')->distinct()->pluck('source_key')->all(), $unresolved);
                $pk = $primaryKey($source);
                $skippedWithValue = $skippedKeys && $pk ? (clone $query)->whereIn($pk, $skippedKeys)->count() : 0;
                $explained = count($unresolved) + $skippedWithValue;
                $add('references', "{$name}.{$c['name']} ← {$source}.{$c['source']}", $nonEmpty, $resolved, $explained);
            }
        }

        $summary = collect($checks)->countBy('status')->all();

        return ['city' => $city->code, 'run' => $run->id, 'source' => $sourceDb, 'checked_at' => now()->toIso8601String(),
            'summary' => $summary + ['equal' => 0, 'explained' => 0, 'unexplained' => 0],
            'exceptions' => DB::table('migration_exceptions')->where('migration_run_id', $run->id)->selectRaw('kind, COUNT(*) n')->groupBy('kind')->orderByDesc('n')->pluck('n', 'kind')->all(),
            'run_counts' => json_decode((string) $run->counts, true), 'checks' => $checks];
    }
}
