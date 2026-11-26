<?php

namespace App\Services\Transfer;

use App\Support\UnifiedSchema;
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
        $schema = UnifiedSchema::tables();
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
        // legacy_user_id 0 is the placeholder of deleted desktop users, not a source row.
        $add('rows', 'user → legacy_staff_users', $src->table('user')->count(), DB::table('legacy_staff_users')->where('city_id', $city->id)->where('legacy_user_id', '>', 0)->count());
        $outOfService = $src->table('user')->where('UserRoleID', 6)->pluck('UserID')->all();
        $granted = DB::table('legacy_staff_users as u')->join('account_legacy_links as l', 'l.legacy_staff_user_id', '=', 'u.id')->where('u.city_id', $city->id)->whereIn('u.legacy_user_id', $outOfService ?: [-1]);
        $add('staff', 'out-of-service users hold no role or capability', 0, (clone $granted)->join('account_city_roles as r', 'r.account_id', '=', 'l.account_id')->count()
            + (clone $granted)->join('account_city_capabilities as c', 'c.account_id', '=', 'l.account_id')->count());
        $add('rows', 'user_notification → web_notices', $src->table('user_notification')->count(), DB::table('web_notices')->where('city_id', $city->id)->count());
        $images = in_array('image', $tables, true) ? $src->table('image')->count() : 0;
        $files = in_array('file', $tables, true) ? $src->table('file')->count() : 0;
        $meet = in_array('profession_meeting_attachment', $tables, true) ? $src->table('profession_meeting_attachment')->count() : 0;
        $add('rows', 'image + file + meeting attachments → attachments', $images + $files + $meet, DB::table('attachments')->where('city_id', $city->id)->count());

        // 1b. Visit answers: the same count of every answer value, per form and question.
        foreach (['visit' => ['visit', 7], 'rehabvisit' => ['rehab', 15], 'office_monitoring' => ['office_monitoring', 6]] as $table => [$form, $max]) {
            if (! in_array($table, $tables, true)) {
                continue;
            }
            $wanted = [];
            $query = $table === 'visit' ? $src->table('visit') : $src->table($table)->join('visit', 'visit.ID', '=', "{$table}.Visit_ID");
            for ($q = 1; $q <= $max; $q++) {
                foreach ((clone $query)->selectRaw("`{$table}`.`Ans{$q}_Value` AS v, COUNT(*) AS n")
                    ->whereRaw("(`{$table}`.`Ans{$q}_Value` IS NOT NULL OR TRIM(IFNULL(`{$table}`.`Ans{$q}`, '')) <> '')")->groupBy('v')->get() as $r) {
                    $wanted["{$q}:{$r->v}"] = (int) $r->n;
                }
            }
            $found = DB::table('visit_answers')->where('city_id', $city->id)->where('form', $form)
                ->selectRaw('question_number, answer_value, COUNT(*) AS n')->groupBy('question_number', 'answer_value')->get()
                ->mapWithKeys(fn ($r) => ["{$r->question_number}:{$r->answer_value}" => (int) $r->n])->all();
            ksort($wanted);
            ksort($found);
            $different = array_sum(array_map(fn ($k) => abs(($wanted[$k] ?? 0) - ($found[$k] ?? 0)), array_keys($wanted + $found)));
            $add('answers', "{$table} → visit_answers: values per question", array_sum($wanted), array_sum($wanted) - $different);
        }

        // 1c. Files stored in the database: on disk byte for byte, empty ones recorded.
        if (in_array('file', $tables, true)) {
            $stored = DB::table('attachments')->where('city_id', $city->id)->where('legacy_source', 'file')->pluck('stored_path', 'legacy_id')->all();
            $withContent = $equal = 0;
            foreach ($src->table('file')->cursor() as $r) {
                if ((string) $r->File_Content === '') {
                    continue;
                }
                $withContent++;
                $path = $stored[-(int) $r->File_ID] ?? null;
                $full = $path === null ? null : storage_path("app/private/{$path}");
                $equal += $full !== null && is_file($full) && hash_file('sha256', $full) === hash('sha256', (string) $r->File_Content) ? 1 : 0;
            }
            $add('files', 'file contents on disk', $withContent, $equal);
            $add('files', 'empty files recorded', $src->table('file')->count() - $withContent, $exc('file', 'File_Content', ['file_empty']));
        }

        // 2. Lists: every source list row has a map entry.
        foreach ($schema as $name => $t) {
            if (in_array($t['scope'], ['lookup', 'city_lookup'], true) && in_array($t['source'], $tables, true)) {
                $add('lists', "{$t['source']} → {$name}", $src->table($t['source'])->count(),
                    DB::table('lookup_legacy_map')->where('city_id', $city->id)->where('lookup_table', $name)->count());
            }
        }

        $primaryKey = function (string $table) use ($src, $sourceDb): ?string {
            $cols = collect($src->select("SELECT column_name AS c FROM information_schema.key_column_usage WHERE table_schema = ? AND table_name = ? AND constraint_name = 'PRIMARY'", [$sourceDb, $table]))->pluck('c');

            return $cols->count() === 1 ? $cols->first() : null;
        };

        // 2b. Lists: the value each source row now points to still carries its parents and attributes (mark, type, limits).
        foreach ($schema as $name => $t) {
            if (! in_array($t['scope'], ['lookup', 'city_lookup'], true) || ! in_array($t['source'], $tables, true)) {
                continue;
            }
            $attributes = array_filter($t['columns'], fn ($c) => isset($c['source']) && ! in_array($c['name'], ['city_id', 'name_ar', 'name_en'], true));
            if ($attributes === []) {
                continue;
            }
            $map = DB::table('lookup_legacy_map')->where('city_id', $city->id)->where('lookup_table', $name)->pluck('lookup_id', 'legacy_id')->all();
            $targets = DB::table($name)->whereIn('id', array_values($map) ?: [0])->get()->keyBy('id');
            $parents = [];
            foreach ($attributes as $c) {
                if (isset($c['references'])) {
                    $parents[$c['references']] ??= DB::table('lookup_legacy_map')->where('city_id', $city->id)->where('lookup_table', $c['references'])->pluck('lookup_id', 'legacy_id')->all();
                }
            }
            $key = $primaryKey($t['source']) ?? 'ID';
            $compared = $different = 0;
            foreach ($src->table($t['source'])->get() as $row) {
                $row = (array) $row;
                $target = $targets[$map[$row[$key]] ?? 0] ?? null;
                if ($target === null) {
                    continue; // already counted by the map check above
                }
                $compared++;
                foreach ($attributes as $c) {
                    $raw = $row[$c['source']] ?? null;
                    [$type, $arg] = array_pad(explode(':', $c['type'], 2), 2, null);
                    $expected = match (true) {
                        isset($c['references']) => $raw === null || $raw === '' ? null : ($parents[$c['references']][(string) $raw] ?? null),
                        in_array($type, ['str', 'char'], true) => ($v = Transforms::trim($raw)[0]) === null ? null : mb_substr($v, 0, (int) $arg),
                        in_array($type, ['int', 'uint', 'smallint', 'tinyint'], true) => Transforms::number($raw)[0],
                        $type === 'dec' => Transforms::money($raw)[0],
                        $type === 'bool' => Transforms::bool01($raw)[0],
                        default => $raw,
                    };
                    $actual = $target->{$c['name']};
                    $same = is_numeric($expected) && is_numeric($actual) ? abs((float) $expected - (float) $actual) < 0.005
                        : (string) (is_bool($expected) ? (int) $expected : $expected) === (string) $actual;
                    // A required text or flag left empty at the source is stored as empty or false, never as a value.
                    if (! $same && ! ($expected === null && ! $c['nullable'] && in_array((string) $actual, ['', '0'], true))) {
                        $different++;

                        continue 2;
                    }
                }
            }
            $add('list values', "{$t['source']} → {$name}: parents and attributes", $compared, $compared - $different);
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
        foreach ($schema as $name => $t) {
            $source = $t['source'] ?? null;
            if ($t['scope'] !== 'city' || $source === null || ! preg_match('/^[a-z_]+$/', $source) || ! in_array($source, $tables, true)) {
                continue;
            }
            foreach ($t['columns'] as $c) {
                if (! isset($c['references'], $c['source']) || str_contains($c['source'], '.') || ! isset($columns[$source][$c['source']])
                    || ($c['transform'] ?? null) === 'opinion_index') { // 0 is a real value there: checked row by row below
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
                $resolved = DB::table($name)->where('city_id', $city->id)->whereNotNull($c['name'])
                    ->when($c['references'] === 'web_accounts', fn ($q) => $q->whereNotIn($c['name'], $this->placeholderAccounts($city->id)))->count();
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

        // 4b. Values spelled one way: every transferred row holds exactly the expected spelling.
        foreach ($schema as $name => $t) {
            foreach ($t['columns'] as $c) {
                if (($c['transform'] ?? null) !== 'yes_no' || ! isset($columns[$t['source']][$c['source']]) || ($pk = $primaryKey($t['source'])) === null) {
                    continue;
                }
                $actual = DB::table($name)->where('city_id', $city->id)->whereNotNull('legacy_id')->pluck($c['name'], 'legacy_id');
                $compared = $wrong = 0;
                foreach ($src->table($t['source'])->select([$pk.' as k', $c['source'].' as v'])->cursor() as $row) {
                    if (! $actual->has($row->k)) {
                        continue; // not transferred: counted by the row check
                    }
                    $compared++;
                    $wrong += $actual[$row->k] === Transforms::yesNo($row->v)[0] ? 0 : 1;
                }
                $add('values', "{$name}.{$c['name']} ← {$t['source']}.{$c['source']}", $compared, $compared - $wrong);
            }
        }

        // 5. Correct target, row by row: each reference points to the record of the very source value, and is empty only when it must be.
        foreach ($schema as $name => $t) {
            $source = $t['source'] ?? null;
            if ($t['scope'] !== 'city' || $source === null || ! preg_match('/^[a-z_]+$/', $source) || ! in_array($source, $tables, true) || ($pk = $primaryKey($source)) === null) {
                continue;
            }
            foreach ($t['columns'] as $c) {
                if (! isset($c['references'], $c['source']) || str_contains($c['source'], '.') || ! isset($columns[$source][$c['source']])) {
                    continue;
                }
                $expected = $this->expectedTargets($src, $city->id, $c, $schema);
                $actual = DB::table($name)->where('city_id', $city->id)->whereNotNull('legacy_id')->pluck($c['name'], 'legacy_id');
                $compared = $wrong = 0;
                foreach ($src->table($source)->select([$pk.' as k', $c['source'].' as v'])->cursor() as $row) {
                    if (! $actual->has($row->k)) {
                        continue; // not transferred: counted by the row check
                    }
                    $compared++;
                    $want = $expected($row->v);
                    $wrong += (string) $actual[$row->k] === (string) $want ? 0 : 1;
                }
                $add('targets', "{$name}.{$c['name']} ← {$source}.{$c['source']}", $compared, $compared - $wrong);
            }
        }

        $summary = collect($checks)->countBy('status')->all();

        return ['city' => $city->code, 'run' => $run->id, 'source' => $sourceDb, 'checked_at' => now()->toIso8601String(),
            'summary' => $summary + ['equal' => 0, 'explained' => 0, 'unexplained' => 0],
            'exceptions' => DB::table('migration_exceptions')->where('migration_run_id', $run->id)->selectRaw('kind, COUNT(*) n')->groupBy('kind')->orderByDesc('n')->pluck('n', 'kind')->all(),
            'run_counts' => json_decode((string) $run->counts, true), 'checks' => $checks];
    }

    private function placeholderAccounts(int $city): array
    {
        return DB::table('legacy_staff_users as u')->join('account_legacy_links as l', 'l.legacy_staff_user_id', '=', 'u.id')
            ->where('u.city_id', $city)->where('u.legacy_user_id', 0)->pluck('l.account_id')->all();
    }

    /** source value → the id the column must hold (null: empty), derived from the target's own legacy keys. */
    private function expectedTargets($src, int $city, array $c, array $schema): \Closure
    {
        $ref = $c['references'];
        $lists = fn (string $list) => DB::table('lookup_legacy_map')->where('city_id', $city)->where('lookup_table', $list)->pluck('lookup_id', 'legacy_id')->all();
        if (($c['transform'] ?? null) === 'opinion_index') {
            $map = $lists($ref);
            $byIndex = [];
            foreach ($src->table($schema[$ref]['source'])->get() as $r) {
                $byIndex[(int) $r->Index] = $map[$r->ID] ?? null;
            }

            return fn ($v) => $v === null || $v === '' ? null : ($byIndex[(int) $v] ?? null);
        }
        if ($ref === 'web_accounts') {
            $map = DB::table('legacy_staff_users as u')->join('account_legacy_links as l', 'l.legacy_staff_user_id', '=', 'u.id')->where('u.city_id', $city)->pluck('l.account_id', 'u.legacy_user_id')->all();
            $placeholder = in_array($c['name'], ['account_id'], true) ? ($map[0] ?? null) : null;

            return fn ($v) => $v === null || $v === '' ? ($c['nullable'] ? null : $placeholder) : ((int) $v === 0 ? null : ($map[(int) $v] ?? null));
        }
        if (in_array($schema[$ref]['scope'] ?? '', ['lookup', 'city_lookup'], true)) {
            $map = $lists($ref);

            return fn ($v) => $v === null || $v === '' ? null : ($map[$v] ?? null);
        }
        $map = DB::table($ref)->where('city_id', $city)->whereNotNull('legacy_id')->pluck('id', 'legacy_id')->all();

        return fn ($v) => $v === null || $v === '' || (is_numeric($v) && (int) $v === 0) ? null : ($map[$v] ?? null);
    }
}
