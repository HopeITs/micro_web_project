<?php

namespace App\Services\Transfer;

use App\Services\AccessRoles;
use App\Support\UnifiedSchema;
use Database\Seeders\CitiesSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * M3.2 — moves one restored desktop city database into the approved unified schema, following the approved
 * transfer specification (the approved version in database/schema/approved.php: source tables, source columns and
 * transforms). Reads the source through the SELECT-only `source` connection; writes the default connection.
 * Every value that cannot be moved exactly is written to migration_exceptions. A city is imported once:
 * the run refuses a city that already has projects.
 */
final class UnifiedImporter
{
    private const NAME_COLUMNS = ['parish' => ['Name_ar', 'Name_en'], 'level' => ['Level_Symbol', null], 'work' => ['W_Name', null], 'task' => ['T_Name', null],
        'state' => ['Name_ar', 'Name_en'], 'meresultranges' => ['Evaluation', 'Evaluation_en'], 'profession_section' => ['Name_Ar', null],
        'profession_question' => ['Text_Ar', null], 'profession_option' => ['Text_Ar', null], 'meprofitmarks' => [null, null],
        'language' => ['L_Name', null], 'priest' => ['Priest_Name', null]];

    private const SORT_COLUMNS = ['checklist_opinion' => 'Index', 'profession_section' => 'Sort_Order', 'profession_question' => 'Sort_Order', 'profession_option' => 'Sort_Order'];

    private const ROLE_MAP = [1 => 'administrator', 8 => 'administrator', 2 => 'data-entry', 3 => 'financial', 7 => 'legal', 9 => 'media', 4 => 'guest', 5 => 'guest'];

    /** Tables filled by dedicated steps instead of the generic column mapping. */
    private const SPECIAL = ['visit_answers', 'kobo_forms', 'attachments', 'web_notices', 'legacy_staff_users', 'account_city_tasks'];

    private array $schema;

    private int $cityId;

    private string $cityCode;

    private int $runId;

    private string $decision;

    private string $now;

    /** @var array<string, array<int|string, int>> */
    private array $lookup = [];

    /** @var array<string, array<int|string, int>> */
    private array $ids = [];

    /** @var array<int, int> legacy UserID => web account id */
    private array $accounts = [];

    /** @var array<string, list<string>> */
    private array $sourceColumns = [];

    /** @var array<string, ?string> */
    private array $sourcePk = [];

    private array $exceptions = [];

    private array $stats = ['read' => [], 'written' => [], 'skipped' => [], 'exceptions' => []];

    /** @var callable */
    private $log;

    public function __construct(private AccessRoles $roles) {}

    public function run(string $cityCode, string $sourceDb, ?string $archive = null, ?callable $log = null): array
    {
        $this->log = $log ?? fn () => null;
        $this->now = now()->format('Y-m-d H:i:s');
        $this->schema = UnifiedSchema::load(); // refuses anything but the approved version
        $this->openSource($sourceDb);
        $this->prepareCity($cityCode);
        if (DB::table('projects')->where('city_id', $this->cityId)->exists()) {
            throw new RuntimeException("{$this->cityCode} already has projects; import a city once into an empty target.");
        }
        $this->runId = DB::table('migration_runs')->insertGetId([
            'city_id' => $this->cityId, 'archive_name' => $archive ? basename($archive) : $sourceDb,
            'archive_sha256' => $archive && is_file($archive) ? hash_file('sha256', $archive) : str_repeat('0', 64),
            'status' => 'running', 'started_at' => $this->now, 'created_at' => $this->now, 'updated_at' => $this->now,
        ]);
        $this->decision = "import:m3:run-{$this->runId}";

        try {
            $this->say('lists');
            $this->importLookups();
            $this->say('staff users and accounts');
            $this->importStaff();
            foreach ($this->cityTableOrder() as $table) {
                $this->importTable($table);
                if ($table === 'visits') {
                    $this->importVisitAnswers();
                }
            }
            $this->say('derived links and special tables');
            $this->deriveChecklistProjects();
            $this->deriveMeVisitProjects();
            $this->importKoboForms();
            $this->remapKoboSubmissions();
            $this->applyLatakiaPersonExtras();
            $this->importAttachments();
            $this->importNotices();
            $this->importAccountTasks();
            // M3.5: the city continues its own numbering after the highest imported project number.
            $max = (int) DB::table('projects')->where('city_id', $this->cityId)->max('project_number');
            DB::table('project_number_sequences')->where('city_id', $this->cityId)->update(['next_number' => max($max + 1, 1), 'updated_at' => now()]);
            $this->stats['next_project_number'] = $max + 1;
            $this->flushExceptions();
            $this->stats['exceptions'] = DB::table('migration_exceptions')->where('migration_run_id', $this->runId)
                ->selectRaw('kind, COUNT(*) as n')->groupBy('kind')->pluck('n', 'kind')->all();
            DB::table('migration_runs')->where('id', $this->runId)->update(['status' => 'completed', 'finished_at' => now(), 'counts' => json_encode($this->stats), 'updated_at' => now()]);
        } catch (\Throwable $e) {
            $this->flushExceptions();
            DB::table('migration_runs')->where('id', $this->runId)->update(['status' => 'failed', 'finished_at' => now(), 'counts' => json_encode($this->stats + ['error' => $e->getMessage()]), 'updated_at' => now()]);
            throw $e;
        }

        return ['run' => $this->runId] + $this->stats;
    }

    // ------------------------------------------------------------------ setup

    private function openSource(string $db): void
    {
        if (preg_match('/^[A-Za-z0-9_]+$/', $db) !== 1) {
            throw new RuntimeException('Invalid source database name.');
        }
        config(['database.connections.source.database' => $db]);
        DB::purge('source');
        foreach (DB::connection('source')->select('SELECT table_name AS t, column_name AS c FROM information_schema.columns WHERE table_schema = ? ORDER BY ordinal_position', [$db]) as $r) {
            $this->sourceColumns[$r->t][] = $r->c;
        }
        $pks = [];
        foreach (DB::connection('source')->select("SELECT table_name AS t, column_name AS c FROM information_schema.key_column_usage WHERE table_schema = ? AND constraint_name = 'PRIMARY'", [$db]) as $r) {
            $pks[$r->t][] = $r->c;
        }
        foreach ($this->sourceColumns as $t => $_) {
            $this->sourcePk[$t] = isset($pks[$t]) && count($pks[$t]) === 1 ? $pks[$t][0] : null;
        }
        if (! isset($this->sourceColumns['microproject'])) {
            throw new RuntimeException("{$db} is not a desktop micro-projects database.");
        }
    }

    private function prepareCity(string $code): void
    {
        $code = strtoupper($code);
        $def = collect(CitiesSeeder::CITIES)->firstWhere('code', $code) ?? throw new RuntimeException("Unknown city code {$code}.");
        DB::table('cities')->updateOrInsert(['code' => $code], $def + ['created_at' => $this->now, 'updated_at' => $this->now]);
        $this->cityId = (int) DB::table('cities')->where('code', $code)->value('id');
        $this->cityCode = $code;
        if (! DB::table('project_number_sequences')->where('city_id', $this->cityId)->exists()) {
            DB::table('project_number_sequences')->insert(['city_id' => $this->cityId, 'next_number' => 1, 'created_at' => $this->now, 'updated_at' => $this->now]);
        }
    }

    private function source(string $table)
    {
        return DB::connection('source')->table($table);
    }

    private function has(string $table, ?string $column = null): bool
    {
        return isset($this->sourceColumns[$table]) && ($column === null || in_array($column, $this->sourceColumns[$table], true));
    }

    private function say(string $message): void
    {
        ($this->log)("{$this->cityCode}: {$message}");
    }

    private function exception(string $table, mixed $key, ?string $column, string $kind, mixed $value, ?string $resolution = null): void
    {
        $this->exceptions[] = [
            'migration_run_id' => $this->runId, 'source_table' => $table, 'source_key' => mb_substr((string) $key, 0, 60),
            'column_name' => $column, 'kind' => $kind, 'original_value' => $value === null ? null : mb_substr(is_scalar($value) ? (string) $value : json_encode($value), 0, 2000),
            'resolution' => $resolution, 'created_at' => $this->now, 'updated_at' => $this->now,
        ];
        if (count($this->exceptions) >= 1000) {
            $this->flushExceptions();
        }
    }

    private function flushExceptions(): void
    {
        foreach (array_chunk($this->exceptions, 500) as $chunk) {
            DB::table('migration_exceptions')->insert($chunk);
        }
        $this->exceptions = [];
    }

    // ------------------------------------------------------------------ value mapping

    private function columnSpec(array $c): array
    {
        [$type, $arg] = array_pad(explode(':', $c['type'], 2), 2, null);

        return [$type, $arg];
    }

    /** Applies the column's transform / reference resolution. Returns [value, ok]. */
    private function mapValue(string $table, string $sourceTable, mixed $key, array $c, mixed $raw): array
    {
        [$type, $arg] = $this->columnSpec($c);
        $tx = $c['transform'] ?? null;
        $problem = null;

        if (isset($c['references'])) {
            if ($raw === null || $raw === '' || (is_numeric($raw) && (int) $raw === 0 && $tx !== 'lookup')) {
                return [null, true];
            }
            $ref = $c['references'];
            $value = match (true) {
                $ref === 'web_accounts' => $this->accounts[(int) $raw] ?? null,
                $tx === 'lookup' || in_array($this->schema['tables'][$ref]['scope'] ?? '', ['lookup', 'city_lookup'], true) => $this->lookup[$ref][(string) $raw] ?? null,
                default => $this->ids[$ref][(string) $raw] ?? null,
            };
            if ($value === null) {
                // A list value of 0 or below that the list does not contain means "not chosen" in the desktop (e.g. -1).
                if ($tx === 'lookup' && is_numeric($raw) && (int) $raw <= 0) {
                    return [null, true];
                }
                $this->exception($sourceTable, $key, $c['source'] ?? $c['name'], $ref === 'web_accounts' ? 'account_unmapped' : 'reference_missing', $raw, "{$table}.{$c['name']} left empty");
            }

            return [$value, true];
        }

        [$value, $problem] = match ($tx) {
            'trim' => Transforms::trim($raw),
            'bool01' => Transforms::bool01($raw),
            'date' => Transforms::date($raw, $type === 'datetime'),
            'sex' => Transforms::sex($raw),
            'stage' => Transforms::stage($raw),
            'currency' => Transforms::currency($raw),
            'money' => Transforms::money($raw),
            default => $this->byType($type, $arg, $raw),
        };
        if ($c['name'] === 'record_state') {
            [$value, $problem] = match (strtolower((string) $raw)) {
                'draft' => ['draft', null], 'finalized', 'final', '' => ['final', null], default => ['final', 'state_unrecognised'],
            };
        }
        if ($type === 'str' && is_string($value) && mb_strlen($value) > (int) $arg) {
            $this->exception($sourceTable, $key, $c['source'] ?? $c['name'], 'truncated', $value, "cut to {$arg} characters");
            $value = mb_substr($value, 0, (int) $arg);
        }
        if ($problem !== null) {
            $this->exception($sourceTable, $key, $c['source'] ?? $c['name'], $problem, $raw, "{$table}.{$c['name']} = ".json_encode($value, JSON_UNESCAPED_UNICODE));
        }

        return [$value, true];
    }

    private function byType(string $type, ?string $arg, mixed $raw): array
    {
        return match ($type) {
            'str', 'char' => Transforms::trim($raw),
            'text' => [$raw === null ? null : (string) $raw, null],
            'int', 'uint', 'smallint', 'tinyint' => Transforms::number($raw),
            'dec' => Transforms::money($raw)[1] === 'money_not_numeric' ? [null, 'number_not_numeric'] : [$raw === null || $raw === '' ? null : round((float) $raw, (int) explode(',', $arg)[1]), null],
            'bool' => Transforms::bool01($raw),
            'date' => Transforms::date($raw),
            'datetime' => Transforms::date($raw, true),
            'json' => $raw === null || $raw === '' ? [null, null] : (json_decode((string) $raw) !== null || $raw === 'null' ? [(string) $raw, null] : [null, 'json_invalid']),
            'enum' => $raw === null ? [null, null] : (in_array((string) $raw, explode(',', (string) $arg), true) ? [(string) $raw, null] : [null, 'enum_unrecognised']),
            default => [$raw, null],
        };
    }

    // ------------------------------------------------------------------ lists

    private function importLookups(): void
    {
        $tables = array_filter($this->schema['tables'], fn ($t) => in_array($t['scope'], ['lookup', 'city_lookup'], true));
        $done = [];
        while (count($done) < count($tables)) {
            $progress = false;
            foreach ($tables as $name => $t) {
                if (isset($done[$name])) {
                    continue;
                }
                $deps = array_filter(array_map(fn ($c) => $c['references'] ?? null, $t['columns']), fn ($r) => $r && isset($tables[$r]) && $r !== $name);
                if (array_diff($deps, array_keys($done))) {
                    continue;
                }
                $this->importLookup($name, $t);
                $done[$name] = $progress = true;
            }
            if (! $progress) {
                throw new RuntimeException('Circular list references.');
            }
        }
    }

    private function importLookup(string $name, array $t): void
    {
        $src = $t['source'];
        $this->lookup[$name] = [];
        if (! $this->has($src)) {
            return;
        }
        [$nameAr, $nameEn] = self::NAME_COLUMNS[$src] ?? ['Name', $this->has($src, 'Name_en') ? 'Name_en' : null];
        $key = $this->sourcePk[$src] ?? 'ID';
        $cityScoped = $t['scope'] === 'city_lookup';
        $extra = array_filter($t['columns'], fn ($c) => isset($c['source']));
        $fkCols = array_values(array_filter(array_map(fn ($c) => isset($c['references']) && $c['name'] !== 'city_id' ? $c['name'] : null, $t['columns'])));

        // Existing values (from other cities) indexed by merge key: normalised name + resolved parent references.
        $index = [];
        $existing = DB::table($name)->when($cityScoped, fn ($q) => $q->where('city_id', $this->cityId))->get();
        foreach ($existing as $row) {
            $index[Transforms::nameKey($row->name_ar).'|'.implode(',', array_map(fn ($f) => $row->{$f}, $fkCols))] = $row->id;
        }
        $maps = [];
        $order = 0;
        foreach ($this->source($src)->orderBy($key)->cursor() as $r) {
            $r = (array) $r;
            $this->stats['read'][$src] = ($this->stats['read'][$src] ?? 0) + 1;
            $legacy = $r[$key];
            $label = $nameAr ? trim((string) ($r[$nameAr] ?? '')) : trim(($r['Min'] ?? '').' – '.($r['Max'] ?? ''));
            if ($label === '') {
                $label = '#'.$legacy;
                $this->exception($src, $legacy, $nameAr, 'name_empty', null, "name set to {$label}");
            }
            $row = ['name_ar' => mb_substr($label, 0, 255), 'name_en' => $nameEn && filled($r[$nameEn] ?? null) ? mb_substr(trim((string) $r[$nameEn]), 0, 255) : null];
            foreach ($extra as $c) {
                [$row[$c['name']]] = $this->mapValue($name, $src, $legacy, $c, $r[$c['source']] ?? null);
            }
            $mergeKey = Transforms::nameKey($row['name_ar']).'|'.implode(',', array_map(fn ($f) => $row[$f] ?? null, $fkCols));
            $id = $index[$mergeKey] ?? null;
            if ($id === null) {
                $active = $r['IsActive'] ?? $r['isActive'] ?? $r['Is_Active'] ?? 1;
                $id = DB::table($name)->insertGetId($row + [
                    'sort_order' => isset(self::SORT_COLUMNS[$src]) ? (int) $r[self::SORT_COLUMNS[$src]] : ++$order,
                    'is_active' => (int) $active !== 0, 'created_at' => $this->now, 'updated_at' => $this->now,
                ] + ($cityScoped ? ['city_id' => $this->cityId] : []));
                $index[$mergeKey] = $id;
                $this->stats['written'][$name] = ($this->stats['written'][$name] ?? 0) + 1;
            }
            $this->lookup[$name][(string) $legacy] = $id;
            $maps[] = ['city_id' => $this->cityId, 'lookup_table' => $name, 'legacy_id' => (int) $legacy, 'lookup_id' => $id, 'legacy_name' => mb_substr($label, 0, 255), 'created_at' => $this->now, 'updated_at' => $this->now];
        }
        foreach (array_chunk($maps, 500) as $chunk) {
            DB::table('lookup_legacy_map')->insert($chunk);
        }
    }

    // ------------------------------------------------------------------ staff

    private function importStaff(): void
    {
        if (! $this->has('user')) {
            return;
        }
        $roleCaps = AccessRoles::ROLES;
        foreach ($this->source('user')->orderBy('UserID')->cursor() as $u) {
            $this->stats['read']['user'] = ($this->stats['read']['user'] ?? 0) + 1;
            $outOfService = (int) $u->UserRoleID === 6;
            $staffId = DB::table('legacy_staff_users')->insertGetId([
                'city_id' => $this->cityId, 'legacy_user_id' => (int) $u->UserID, 'username' => mb_substr((string) $u->UserName, 0, 100),
                'email' => filled($u->Email ?? null) ? mb_substr(trim($u->Email), 0, 190) : null, 'password_hash' => $u->Password ?: null,
                'legacy_role_id' => $u->UserRoleID, 'is_visitor' => (bool) ($u->IsVisitor ?? 0), 'is_me' => (bool) ($u->IsME ?? 0),
                'is_communication' => (bool) ($u->IsCommunication ?? 0), 'is_cashier' => (bool) ($u->IsCashier ?? 0),
                'disabled_at' => $outOfService ? $this->now : null, 'created_at' => $this->now, 'updated_at' => $this->now,
            ]);
            $this->stats['written']['legacy_staff_users'] = ($this->stats['written']['legacy_staff_users'] ?? 0) + 1;

            // One web account per desktop user, unmerged and disabled until the duplicate-account review (M4.1)
            // enables or merges it. Old records point at it from the start, so no reference is lost.
            $base = strtolower($this->cityCode).'.'.(Str::slug((string) $u->UserName, '.') ?: 'user'.$u->UserID);
            $username = $base;
            for ($i = 2; DB::table('web_accounts')->where('username', $username)->exists(); $i++) {
                $username = $base.$i;
            }
            $accountId = DB::table('web_accounts')->insertGetId([
                'username' => $username, 'email' => null, 'password_hash' => null, 'password_changed_at' => null,
                'credential_legacy_user_id' => $staffId, 'disabled_at' => $this->now, 'created_at' => $this->now, 'updated_at' => $this->now,
            ]);
            DB::table('account_legacy_links')->insert(['account_id' => $accountId, 'city_id' => $this->cityId, 'legacy_staff_user_id' => $staffId,
                'decision_locator' => $this->decision, 'reviewed_at' => $this->now, 'revoked_at' => null]);
            $this->accounts[(int) $u->UserID] = $accountId;

            $roles = array_filter([self::ROLE_MAP[(int) $u->UserRoleID] ?? null, ($u->IsME ?? 0) ? 'monitoring' : null,
                ($u->IsCommunication ?? 0) ? 'communication' : null, ($u->IsCashier ?? 0) ? 'financial' : null]);
            $caps = [];
            foreach (array_unique($roles) as $role) {
                DB::table('account_city_roles')->insert(['account_id' => $accountId, 'city_id' => $this->cityId, 'role' => $role, 'decision_locator' => $this->decision, 'assigned_at' => $this->now, 'revoked_at' => null]);
                $caps = [...$caps, ...($roleCaps[$role] ?? [])];
            }
            foreach (array_unique($caps) as $cap) {
                DB::table('account_city_capabilities')->insert(['account_id' => $accountId, 'city_id' => $this->cityId, 'capability' => $cap, 'decision_locator' => $this->decision, 'granted_at' => $this->now, 'revoked_at' => null]);
            }
        }
        $this->stats['written']['web_accounts'] = count($this->accounts);
    }

    // ------------------------------------------------------------------ city tables

    /** City-scope tables in foreign-key order. */
    private function cityTableOrder(): array
    {
        $tables = array_filter($this->schema['tables'], fn ($t, $n) => $t['scope'] === 'city' && ! in_array($n, self::SPECIAL, true), ARRAY_FILTER_USE_BOTH);
        $order = [];
        $done = [];
        while (count($done) < count($tables)) {
            $progress = false;
            foreach ($tables as $name => $t) {
                if (isset($done[$name])) {
                    continue;
                }
                $deps = array_filter(array_map(fn ($c) => $c['references'] ?? null, $t['columns']), fn ($r) => $r && isset($tables[$r]) && $r !== $name);
                if (array_diff($deps, array_keys($done))) {
                    continue;
                }
                $order[] = $name;
                $done[$name] = $progress = true;
            }
            if (! $progress) {
                throw new RuntimeException('Circular table references: '.implode(', ', array_diff(array_keys($tables), array_keys($done))));
            }
        }

        return $order;
    }

    private function importTable(string $name): void
    {
        $t = $this->schema['tables'][$name];
        $src = $t['source'] ?? null;
        $this->ids[$name] = [];
        if ($src === null || ! $this->has($src)) {
            return;
        }
        $this->say("{$name} ← {$src}");
        $pk = $this->sourcePk[$src] ?? null;
        $mapped = array_filter($t['columns'], fn ($c) => isset($c['source']) && ! str_contains($c['source'], '.'));
        $batch = [];
        $query = $this->source($src);
        if ($pk) {
            $query->orderBy($pk);
        }
        foreach ($query->cursor() as $r) {
            $r = (array) $r;
            $this->stats['read'][$src] = ($this->stats['read'][$src] ?? 0) + 1;
            $key = $pk ? $r[$pk] : null;
            $row = ['city_id' => $this->cityId, 'legacy_id' => $pk && is_numeric($key) ? (int) $key : null, 'created_at' => $this->now, 'updated_at' => $this->now];
            $skip = false;
            foreach ($mapped as $c) {
                [$value] = $this->mapValue($name, $src, $key, $c, array_key_exists($c['source'], $r) ? $r[$c['source']] : null);
                if ($value === null && ! $c['nullable']) {
                    $value = match (true) {
                        str_starts_with($c['type'], 'str:') => '',
                        $c['type'] === 'bool' => false,
                        $c['name'] === 'record_state' => 'final',
                        $c['name'] === 'sort_order' => 0,
                        default => null,
                    };
                    if ($value === '') {
                        $this->exception($src, $key, $c['source'], 'required_text_empty', null, "{$name}.{$c['name']} stored as empty text");
                    }
                    if ($value === null) {
                        $this->exception($src, $key, $c['source'], 'required_missing', $r[$c['source']] ?? null, "row not transferred ({$name}.{$c['name']} is required)");
                        $skip = true;
                        break;
                    }
                }
                $row[$c['name']] = $value;
            }
            if ($skip) {
                $this->stats['skipped'][$src] = ($this->stats['skipped'][$src] ?? 0) + 1;

                continue;
            }
            $batch[] = $row;
            if (count($batch) === 500) {
                $this->insert($name, $batch);
                $batch = [];
            }
        }
        $this->insert($name, $batch);
        if ($pk) {
            $this->ids[$name] = DB::table($name)->where('city_id', $this->cityId)->whereNotNull('legacy_id')->pluck('id', 'legacy_id')->mapWithKeys(fn ($id, $legacy) => [(string) $legacy => $id])->all();
        }
    }

    private function insert(string $table, array $rows): void
    {
        if ($rows === []) {
            return;
        }
        // Rows of one batch must share the same keys for a multi-row insert.
        $keys = array_unique(array_merge(...array_map('array_keys', $rows)));
        $rows = array_map(fn ($r) => array_merge(array_fill_keys($keys, null), $r), $rows);
        DB::table($table)->insert($rows);
        $this->stats['written'][$table] = ($this->stats['written'][$table] ?? 0) + count($rows);
    }

    // ------------------------------------------------------------------ dedicated steps

    /** visit.AnsN (1..7), rehabvisit.AnsN (1..15), office_monitoring.AnsN (1..6) -> one row per answered question. */
    private function importVisitAnswers(): void
    {
        $rows = [];
        $add = function (int $visitId, string $form, array $r, int $max) use (&$rows) {
            for ($q = 1; $q <= $max; $q++) {
                $text = $r["Ans{$q}"] ?? null;
                $value = $r["Ans{$q}_Value"] ?? null;
                if (($text === null || trim((string) $text) === '') && ($value === null || (int) $value === 0)) {
                    continue;
                }
                $rows[] = ['city_id' => $this->cityId, 'visit_id' => $visitId, 'form' => $form, 'question_number' => $q, 'answer_text' => $text,
                    'answer_value' => $value === null ? null : (int) $value, 'created_at' => $this->now, 'updated_at' => $this->now];
                if (count($rows) === 500) {
                    $this->insert('visit_answers', $rows);
                    $rows = [];
                }
            }
        };
        foreach ($this->source('visit')->orderBy('ID')->cursor() as $r) {
            if ($id = $this->ids['visits'][(string) $r->ID] ?? null) {
                $add($id, 'visit', (array) $r, 7);
            }
        }
        foreach (['rehabvisit' => ['rehab', 15], 'office_monitoring' => ['office_monitoring', 6]] as $src => [$form, $max]) {
            if (! $this->has($src)) {
                continue;
            }
            $seen = [];
            foreach ($this->source($src)->orderBy('ID')->cursor() as $r) {
                $this->stats['read'][$src] = ($this->stats['read'][$src] ?? 0) + 1;
                $visitId = $this->ids['visits'][(string) $r->Visit_ID] ?? null;
                if ($visitId === null) {
                    $this->exception($src, $r->ID, 'Visit_ID', 'reference_missing', $r->Visit_ID, 'answers not transferred');

                    continue;
                }
                if (isset($seen[$visitId])) {
                    $this->exception($src, $r->ID, 'Visit_ID', 'duplicate_answer_set', $r->Visit_ID, 'second answer set kept for review');
                }
                $seen[$visitId] = true;
                $add($visitId, $form, (array) $r, $max);
                if ($src === 'office_monitoring' && filled($r->OtherComments ?? null)) {
                    DB::table('visits')->where('id', $visitId)->update(['office_comments' => $r->OtherComments]);
                }
            }
        }
        $this->insert('visit_answers', $rows);
    }

    /** The desktop keys a checklist by person: the project is the person's only one, or the closest earlier request. */
    private function deriveChecklistProjects(): void
    {
        $projects = DB::table('project_applicants as a')->join('projects as p', 'p.id', '=', 'a.project_id')
            ->where('a.city_id', $this->cityId)->get(['a.person_id', 'p.id', 'p.request_date'])->groupBy('person_id');
        foreach (DB::table('checklists')->where('city_id', $this->cityId)->get(['id', 'legacy_id', 'person_id', 'team_date', 'committee_date', 'home_visit_date']) as $c) {
            $choices = $projects[$c->person_id] ?? collect();
            if ($choices->isEmpty()) {
                $this->exception('checklist', $c->legacy_id, 'Person_ID', 'project_not_found', $c->person_id, 'checklist kept without project');

                continue;
            }
            $pick = $choices->first();
            if ($choices->count() > 1) {
                $ref = $c->committee_date ?? $c->team_date ?? $c->home_visit_date;
                $pick = $choices->filter(fn ($p) => $ref === null || $p->request_date === null || $p->request_date <= $ref)->sortByDesc('request_date')->first() ?? $choices->sortByDesc('request_date')->first();
                $this->exception('checklist', $c->legacy_id, 'Person_ID', 'project_chosen', $c->person_id, "project {$pick->id} chosen among {$choices->count()}; review");
            }
            DB::table('checklists')->where('id', $c->id)->update(['project_id' => $pick->id]);
        }
    }

    private function deriveMeVisitProjects(): void
    {
        $fromKobo = $this->has('kobo_monitoring_record')
            ? $this->source('kobo_monitoring_record')->whereNotNull('Legacy_Visit_ID')->whereNotNull('MicroProject_ID')->pluck('MicroProject_ID', 'Legacy_Visit_ID')->all() : [];
        $projects = DB::table('project_applicants as a')->join('projects as p', 'p.id', '=', 'a.project_id')
            ->where('a.city_id', $this->cityId)->get(['a.person_id', 'p.id', 'p.request_date'])->groupBy('person_id');
        foreach (DB::table('me_visits')->where('city_id', $this->cityId)->get(['id', 'legacy_id', 'person_id', 'visit_date']) as $v) {
            $kobo = $fromKobo[$v->legacy_id] ?? null;
            if ($kobo !== null && ($id = $this->ids['projects'][(string) $kobo] ?? null)) {
                DB::table('me_visits')->where('id', $v->id)->update(['project_id' => $id]);

                continue;
            }
            $choices = $projects[$v->person_id] ?? collect();
            if ($choices->isEmpty()) {
                $this->exception('mevisit', $v->legacy_id, 'Person_ID', 'project_not_found', $v->person_id, 'visit kept without project');

                continue;
            }
            $pick = $choices->first();
            if ($choices->count() > 1) {
                $pick = $choices->filter(fn ($p) => $v->visit_date === null || $p->request_date === null || $p->request_date <= $v->visit_date)->sortByDesc('request_date')->first() ?? $choices->sortByDesc('request_date')->first();
                $this->exception('mevisit', $v->legacy_id, 'Person_ID', 'project_chosen', $v->person_id, "project {$pick->id} chosen among {$choices->count()}; review");
            }
            DB::table('me_visits')->where('id', $v->id)->update(['project_id' => $pick->id]);
        }
    }

    /** kobo_form_mapping joined with the field and stage mappings on Kobo_Form_UID. */
    private function importKoboForms(): void
    {
        if (! $this->has('kobo_form_mapping')) {
            return;
        }
        $fields = [];
        foreach (['kobo_opening_field_mapping', 'kobo_monitoring_field_mapping'] as $src) {
            if ($this->has($src)) {
                foreach ($this->source($src)->get() as $r) {
                    $fields[$r->Kobo_Form_UID] = (array) $r;
                }
            }
        }
        $stages = $this->has('kobo_monitoring_stage_mapping') ? $this->source('kobo_monitoring_stage_mapping')->get()->keyBy('Kobo_Form_UID') : collect();
        $rows = [];
        foreach ($this->source('kobo_form_mapping')->orderBy('ID')->get() as $r) {
            $this->stats['read']['kobo_form_mapping'] = ($this->stats['read']['kobo_form_mapping'] ?? 0) + 1;
            $f = $fields[$r->Kobo_Form_UID] ?? [];
            $s = $stages[$r->Kobo_Form_UID] ?? null;
            $rows[] = ['city_id' => $this->cityId, 'legacy_id' => (int) $r->ID, 'form_uid' => $r->Kobo_Form_UID, 'form_name' => $r->Kobo_Form_Name,
                'governorate' => $r->Governorate, 'visit_type' => $r->Visit_Type, 'visit_category' => $r->Visit_Category, 'target_table' => $r->Target_Table,
                'is_active' => (bool) $r->Is_Active, 'project_number_field' => $f['Project_Number_Field'] ?? null, 'visitor_choice_field' => $f['Visitor_Choice_Field'] ?? null,
                'visitor_text_field' => $f['Visitor_Text_Field'] ?? null, 'vehicle_number_field' => $f['Vehicle_Number_Field'] ?? null,
                'stage_field' => $s->Stage_Field ?? null, 'fixed_stage' => $s->Fixed_Stage ?? null, 'created_at' => $this->now, 'updated_at' => $this->now];
        }
        $this->insert('kobo_forms', $rows);
    }

    private function remapKoboSubmissions(): void
    {
        $targets = ['mevisit' => 'me_visits', 'visit' => 'visits'];
        foreach (DB::table('kobo_submissions')->where('city_id', $this->cityId)->whereNotNull('local_table')->get(['id', 'legacy_id', 'local_table', 'local_record_id']) as $s) {
            $table = $targets[$s->local_table] ?? null;
            $id = $table && $s->local_record_id !== null ? ($this->ids[$table][(string) $s->local_record_id] ?? null) : null;
            if ($table === null || $id === null) {
                $this->exception('kobo_submission', $s->legacy_id, 'Local_Record_ID', 'reference_missing', "{$s->local_table}:{$s->local_record_id}", 'local record left empty');
            }
            DB::table('kobo_submissions')->where('id', $s->id)->update(['local_table' => $table ?? $s->local_table, 'local_record_id' => $id]);
        }
    }

    /** Latakia keeps four applicant fields on person; they fill the empty fields of the person's applicant rows. */
    private function applyLatakiaPersonExtras(): void
    {
        $fields = ['P_Goal' => 'goal', 'P_GoalHow' => 'goal_how', 'P_OtherNotes' => 'other_notes', 'P_WorkYears' => 'work_years'];
        if (! $this->has('person', 'P_Goal')) {
            return;
        }
        $query = $this->source('person')->select(['P_ID', ...array_keys($fields)])->where(function ($q) use ($fields) {
            foreach (array_keys($fields) as $f) {
                $q->orWhere(fn ($w) => $w->whereNotNull($f)->where($f, '<>', ''));
            }
        });
        foreach ($query->cursor() as $p) {
            $personId = $this->ids['people'][(string) $p->P_ID] ?? null;
            foreach (DB::table('project_applicants')->where('city_id', $this->cityId)->where('person_id', $personId)->get() as $a) {
                $update = [];
                foreach ($fields as $src => $col) {
                    $v = trim((string) $p->{$src});
                    if ($v === '') {
                        continue;
                    }
                    if ($a->{$col} === null || trim((string) $a->{$col}) === '') {
                        $update[$col] = $v;
                    } elseif (trim((string) $a->{$col}) !== $v) {
                        $this->exception('person', $p->P_ID, $src, 'value_conflict', $v, "applicant {$a->id} keeps its own {$col}");
                    }
                }
                if ($update) {
                    DB::table('project_applicants')->where('id', $a->id)->update($update);
                }
            }
        }
    }

    /**
     * image (FTP file names), file (content stored in the database) and profession_meeting_attachment. legacy_id keeps
     * the source id: image ID as is, file ID negated, meeting attachment ID negated plus 10,000,000, so the three never collide.
     */
    private function importAttachments(): void
    {
        $folder = DB::table('cities')->where('id', $this->cityId)->value('attachment_folder') ?? strtolower($this->cityCode);
        $rows = [];
        $flush = function (bool $force = false) use (&$rows) {
            if ($force || count($rows) >= 500) {
                $this->insert('attachments', $rows);
                $rows = [];
            }
        };
        $base = ['city_id' => $this->cityId, 'created_at' => $this->now, 'updated_at' => $this->now];
        if ($this->has('image')) {
            foreach ($this->source('image')->orderBy('Image_ID')->cursor() as $r) {
                $this->stats['read']['image'] = ($this->stats['read']['image'] ?? 0) + 1;
                $path = trim((string) $r->image_NewPath);
                $rows[] = $base + ['legacy_id' => (int) $r->Image_ID, 'project_id' => $this->ids['projects'][(string) $r->MicroProject_ID] ?? null,
                    'person_id' => $this->ids['people'][(string) $r->Person_ID] ?? null, 'profession_meeting_id' => null,
                    'attachment_type_id' => $this->lookup['attachment_types'][(string) $r->ImageType_ID] ?? null,
                    'original_name' => $path === '' ? null : mb_substr(basename($path), 0, 255), 'stored_path' => $path === '' ? null : mb_substr("ftp/{$folder}/{$path}", 0, 500),
                    'extension' => $path === '' ? null : mb_substr(strtolower(pathinfo($path, PATHINFO_EXTENSION)), 0, 10), 'legacy_source' => 'image',
                    'uploaded_by_account_id' => null, 'uploaded_at' => null];
                $flush();
            }
        }
        if ($this->has('file')) {
            foreach ($this->source('file')->orderBy('File_ID')->cursor() as $r) {
                $this->stats['read']['file'] = ($this->stats['read']['file'] ?? 0) + 1;
                $name = basename(str_replace('\\', '/', (string) $r->File_Name)) ?: "file-{$r->File_ID}";
                $stored = "attachments/{$this->cityCode}/file-{$r->File_ID}-".Str::limit(Str::slug(pathinfo($name, PATHINFO_FILENAME)), 60, '').'.'.(pathinfo($name, PATHINFO_EXTENSION) ?: 'bin');
                @mkdir(dirname(storage_path("app/private/{$stored}")), 0770, true);
                file_put_contents(storage_path("app/private/{$stored}"), (string) $r->File_Content);
                $rows[] = $base + ['legacy_id' => -(int) $r->File_ID, 'project_id' => $this->ids['projects'][(string) $r->MicroProject_ID] ?? null,
                    'person_id' => null, 'profession_meeting_id' => null, 'attachment_type_id' => null, 'original_name' => mb_substr($name, 0, 255),
                    'stored_path' => $stored, 'extension' => mb_substr(strtolower(pathinfo($name, PATHINFO_EXTENSION)), 0, 10), 'legacy_source' => 'file',
                    'uploaded_by_account_id' => null, 'uploaded_at' => null];
                $flush();
            }
        }
        if ($this->has('profession_meeting_attachment')) {
            foreach ($this->source('profession_meeting_attachment')->orderBy('ID')->cursor() as $r) {
                $this->stats['read']['profession_meeting_attachment'] = ($this->stats['read']['profession_meeting_attachment'] ?? 0) + 1;
                $rows[] = $base + ['legacy_id' => -(10_000_000 + (int) $r->ID), 'project_id' => null, 'person_id' => null,
                    'profession_meeting_id' => $this->ids['profession_meetings'][(string) $r->Meeting_ID] ?? null, 'attachment_type_id' => null,
                    'original_name' => mb_substr((string) $r->Original_Name, 0, 255), 'stored_path' => mb_substr("ftp/profession-meetings/{$r->Stored_Name}", 0, 500),
                    'extension' => mb_substr((string) $r->File_Extension, 0, 10), 'legacy_source' => 'meeting',
                    'uploaded_by_account_id' => $this->accounts[(int) $r->Uploaded_By_UserID] ?? null, 'uploaded_at' => Transforms::date($r->Uploaded_At, true)[0]];
                $flush();
            }
        }
        $flush(true);
    }

    private function importNotices(): void
    {
        if (! $this->has('user_notification')) {
            return;
        }
        $installments = $this->ids['loan_installments'] ?? [];
        $rows = [];
        foreach ($this->source('user_notification')->orderBy('ID')->cursor() as $r) {
            $this->stats['read']['user_notification'] = ($this->stats['read']['user_notification'] ?? 0) + 1;
            [$date, $problem] = Transforms::date($r->Date);
            if ($date === null) {
                $this->exception('user_notification', $r->ID, 'Date', $problem ?? 'date_missing', $r->Date, 'notice dated on the import day');
                $date = substr($this->now, 0, 10);
            }
            $rows[] = ['city_id' => $this->cityId, 'legacy_id' => (int) $r->ID, 'account_id' => $this->accounts[(int) $r->User_ID] ?? null,
                'sender_account_id' => $this->accounts[(int) $r->Sender_ID] ?? null, 'project_id' => $this->ids['projects'][(string) $r->MicroProject_ID] ?? null,
                'project_number' => $r->MicroProject_ID ? (int) $r->MicroProject_ID : null, 'loan_installment_id' => (int) $r->Pay_ID > 0 ? ($installments[(string) $r->Pay_ID] ?? null) : null,
                'legacy_pay_key' => $r->Pay_ID === null ? null : (int) $r->Pay_ID, // v1.2: desktop notice codes (-21 Kobo, -3 purchases, ...)
                'kind' => Transforms::noticeKind($r->Body, $r->Pay_ID), 'notice_date' => $date, 'original_date' => $date, 'title' => mb_substr((string) $r->P_Name, 0, 255),
                'body' => (string) $r->Body, 'note' => $r->Note, 'change_reason' => null, 'seen' => (bool) $r->Seen, 'retired_by_kobo' => false, 'created_at' => $this->now];
            if (count($rows) === 500) {
                $this->insert('web_notices', $rows);
                $rows = [];
            }
        }
        $this->insert('web_notices', $rows);
        if ($this->has('kobo_visit_schedule')) {
            $retired = $this->source('kobo_visit_schedule')->where('Retired', 1)->pluck('Notification_ID')->all();
            foreach (array_chunk($retired, 500) as $chunk) {
                DB::table('web_notices')->where('city_id', $this->cityId)->whereIn('legacy_id', $chunk)->update(['retired_by_kobo' => true]);
            }
        }
    }

    private function importAccountTasks(): void
    {
        if (! $this->has('task_user')) {
            return;
        }
        $seen = [];
        foreach ($this->source('task_user')->orderBy('TU_ID')->get() as $r) {
            $this->stats['read']['task_user'] = ($this->stats['read']['task_user'] ?? 0) + 1;
            $account = $this->accounts[(int) $r->User_ID] ?? null;
            $task = $this->lookup['task_definitions'][(string) $r->Task_ID] ?? null;
            if ($account === null || $task === null || isset($seen["{$account}-{$task}"])) {
                if ($account === null || $task === null) {
                    $this->exception('task_user', $r->TU_ID, $account === null ? 'User_ID' : 'Task_ID', 'reference_missing', $account === null ? $r->User_ID : $r->Task_ID, 'responsibility not transferred');
                }

                continue;
            }
            $seen["{$account}-{$task}"] = true;
            DB::table('account_city_tasks')->insert(['account_id' => $account, 'city_id' => $this->cityId, 'task_definition_id' => $task, 'assigned_at' => $this->now, 'revoked_at' => null]);
            $this->stats['written']['account_city_tasks'] = ($this->stats['written']['account_city_tasks'] ?? 0) + 1;
        }
    }
}
