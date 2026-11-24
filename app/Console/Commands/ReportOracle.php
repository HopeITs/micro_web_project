<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\WebAccount;
use App\Services\AccountAccess;
use App\Services\Finance\PaymentScheduleService;
use App\Services\Lifecycle\LifecycleService;
use App\Services\Reports\Search\SearchModes;
use App\Services\Reports\Search\SearchService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PDO;

/**
 * M4 gate report oracle: compares what the website's reports and exports give with what the desktop's own SQL gives on the city source
 * databases (`<prefix><city>`, e.g. m1src_alp), for every project the unified database holds. The web side runs the same services the
 * pages and exports use (SearchService, PaymentScheduleService, LifecycleService), in process, as a temporary account holding every
 * capability in every city, inside a transaction that is rolled back: nothing is written to either database.
 *
 *   php artisan report:oracle --database=micro_m3_sample --sources=m1src_ [--only=loans,details] [--out=storage/app/oracle.json]
 *
 * Sources are read on the same server with the app's credentials unless --source-host/--source-port/--source-user are given
 * (password from the ORACLE_SOURCE_PASSWORD environment variable). The report holds counts, project numbers and the differing values only.
 * Exit 0 when there are no unexplained differences, 1 otherwise.
 */
final class ReportOracle extends Command
{
    protected $signature = 'report:oracle {--database= : unified database holding the transferred data (default: the app database)}
        {--sources=m1src_ : prefix of the city source databases} {--source-host=} {--source-port=} {--source-user=}
        {--only= : comma-separated checks} {--out= : JSON report path}';

    protected $description = 'Compare the web reports and exports with the desktop SQL on the source databases (M4 gate, read only)';

    public const CHECKS = ['applications', 'statistics', 'loans', 'instalments', 'family', 'details', 'other modes', 'state history'];

    /** @var array<string, PDO> city code => source connection */
    private array $sources = [];

    /** @var array<string, array<int, true>> city code => desktop project numbers the web holds */
    private array $projects = [];

    /** @var array<string, string> English city name => code */
    private array $cityCodes = [];

    private array $report = ['checks' => [], 'known_differences' => []];

    private int $failures = 0;

    private WebAccount $account;

    public function handle(SearchService $search): int
    {
        app()->setLocale('en');
        if ($database = $this->option('database')) {
            config(['database.connections.'.config('database.default').'.database' => $database]);
            DB::purge();
        }
        $only = array_filter(array_map('trim', explode(',', (string) $this->option('only'))));
        $checks = $only === [] ? self::CHECKS : array_values(array_intersect(self::CHECKS, $only));
        $this->report['database'] = DB::getDatabaseName();
        $this->report['sources'] = $this->option('sources').'<city>';

        DB::beginTransaction();
        try {
            $this->account = $this->temporaryAccount();
            $this->connectSources();
            foreach ($checks as $check) {
                $this->line("… $check");
                $started = microtime(true);
                match ($check) {
                    'applications' => $this->applications($search),
                    'statistics' => $this->statistics($search),
                    'loans' => $this->loans($search),
                    'instalments' => $this->instalments(),
                    'family' => $this->family($search),
                    'details' => $this->details($search),
                    'other modes' => $this->otherModes($search),
                    'state history' => $this->stateHistory(),
                };
                $this->report['checks'][$check]['seconds'] = round(microtime(true) - $started, 1);
            }
        } finally {
            DB::rollBack();
        }

        return $this->finish();
    }

    // ------------------------------------------------------------------ set-up

    /** An account with every capability in every city, inside the transaction (rolled back at the end). */
    private function temporaryAccount(): WebAccount
    {
        $now = now('UTC')->format('Y-m-d H:i:s.u');
        $id = DB::table('web_accounts')->insertGetId(['username' => 'oracle.'.bin2hex(random_bytes(4)), 'email' => null, 'password_hash' => '!', 'password_changed_at' => $now,
            'created_at' => $now, 'updated_at' => $now]);
        $rows = [];
        foreach (DB::table('cities')->pluck('id') as $city) {
            foreach (array_unique([...AccountAccess::CAPABILITIES, ...AccountAccess::ADDED_CAPABILITIES]) as $capability) {
                $rows[] = ['account_id' => $id, 'city_id' => $city, 'capability' => $capability, 'decision_locator' => 'report:oracle', 'granted_at' => $now];
            }
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('account_city_capabilities')->insert($chunk);
        }

        return WebAccount::findOrFail($id);
    }

    private function connectSources(): void
    {
        $default = config('database.connections.'.config('database.default'));
        $host = $this->option('source-host') ?: $default['host'];
        $port = $this->option('source-port') ?: $default['port'];
        $user = $this->option('source-user') ?: $default['username'];
        $password = $this->option('source-user') ? (string) getenv('ORACLE_SOURCE_PASSWORD') : (string) $default['password'];
        foreach (DB::table('cities')->orderBy('id')->get(['id', 'code', 'name_en']) as $city) {
            $this->cityCodes[(string) $city->name_en] = $city->code;
            $this->cityCodes[(string) DB::table('cities')->where('id', $city->id)->value('name_ar')] = $city->code;
            $name = $this->option('sources').strtolower($city->code);
            $pdo = new PDO("mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4", $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $pdo->exec("SET SESSION sql_mode=''");
            $this->sources[$city->code] = $pdo;
            $this->projects[$city->code] = array_fill_keys(DB::table('projects')->where('city_id', $city->id)->whereNull('deleted_at')->pluck('project_number')->map(fn ($n) => (int) $n)->all(), true);
        }
    }

    // ------------------------------------------------------------------ helpers

    private function note(string $check, string $item, string $detail): void
    {
        $this->report['checks'][$check]['mismatches'][$item][] = $detail;
        $this->failures++;
    }

    private function count(string $check, string $key, int|float|string $value): void
    {
        $this->report['checks'][$check]['counts'][$key] = $value;
    }

    private function known(string $kind, string $example): void
    {
        $this->report['known_differences'][$kind][] = $example;
    }

    private function in(string $city): string
    {
        return implode(',', array_keys($this->projects[$city]) ?: [0]);
    }

    /** @return iterable<array<string, mixed>> */
    private function query(string $city, string $sql, int $mode = PDO::FETCH_ASSOC): iterable
    {
        return $mode === PDO::FETCH_COLUMN ? $this->sources[$city]->query($sql, $mode, 0) : $this->sources[$city]->query($sql, $mode);
    }

    /**
     * Every row of a search type's export as (English) heading => cell, plus `_city` (code) and `_number` (desktop project number, when the
     * type has one). With $locale 'ar' the cells are the Arabic export (list names as the desktop stores them); the keys stay English.
     *
     * @return list<array<string, mixed>>
     */
    private function export(SearchService $search, string $key, array $filters = [], string $locale = 'en'): array
    {
        app()->setLocale($locale);
        try {
            [, $rows] = $search->export($this->account, $key, $filters);
            $out = [];
            foreach ($rows as $cells) {
                $out[] = array_map(fn ($c) => $c === null ? '' : (is_scalar($c) ? (string) $c : json_encode($c)), $cells);
            }
        } finally {
            app()->setLocale('en');
        }
        $headings = SearchModes::make($key)->headings();
        foreach ($out as &$cells) {
            $row = array_combine($headings, $cells);
            $row['_city'] = $this->cityCodes[$row['City']] ?? $row['City'];
            $row['_number'] = isset($row['Project']) ? $this->number((string) $row['Project']) : null;
            $cells = $row;
        }
        unset($cells);

        return $out;
    }

    /** "ALP-12" (or "12") → 12. */
    private function number(string $display): ?int
    {
        return preg_match('/(\d+)\s*$/', $display, $m) ? (int) $m[1] : null;
    }

    private static function day(mixed $value): string
    {
        $text = $value === null ? '' : substr(trim((string) $value), 0, 10);

        return $text === '' || str_starts_with($text, '0000') ? '' : $text;
    }

    /** A cell as compared: trimmed, single spaces, numbers as numbers, dates as days; the CSV formula guard is not involved (rows come from the service). */
    private static function norm(mixed $value, bool $date = false): string
    {
        if ($value === null) {
            return '';
        }
        $text = $date ? self::day($value) : trim((string) $value);
        if (is_numeric($text)) {
            return (string) (float) $text;
        }
        // Arabic vowel marks (shadda included), tatweel and hamza forms of alef are ignored: the shared lists of M3 keep one spelling for all
        // cities (لحام / لحّام, تصليح أحذية / تصليح احذية).
        $text = (string) preg_replace('/[\x{064B}-\x{0652}\x{0670}\x{0640}]/u', '', $text);
        $text = str_replace(['أ', 'إ', 'آ'], 'ا', $text);

        return (string) preg_replace('/\s+/u', ' ', $text);
    }

    /** @var array<string, array<string, true>>|null rule => "CITY|number" projects the rule explains */
    private ?array $explained = null;

    /**
     * Projects whose differing lines have a confirmed structural cause (checked on the source data, M4 gate 24 Nov 2026):
     *  - multi: a person of the project holds another project; the desktop keys M&E visits by person and shows them under every project,
     *    the web keeps each visit on the one project the transfer linked it to;
     *  - skipped: family rows without a family book, skipped by the M3 transfer and recorded in docs/planning/m3/reconcile-<city>.json;
     *  - no_family: an applicant without a family row; the desktop detail searches reach people through family rows only, the web lists them;
     *  - partners: a project with a partner; the desktop's project-level searches join every applicant and repeat each row per applicant;
     *  - two_openings / two_numbers: more than one opening visit, or two M&E visits with the same number; the desktop shows the first
     *    entered, the web the earliest date.
     */
    private function explained(): array
    {
        if ($this->explained !== null) {
            return $this->explained;
        }
        $sql = [
            'multi' => 'select distinct pmp.MicroProject_ID from person_microproject pmp where pmp.Person_ID in (select Person_ID from person_microproject group by Person_ID having count(*) > 1)',
            'skipped' => 'select distinct pmp.MicroProject_ID from person_family pf join person_microproject pmp on pmp.Person_ID = pf.P_Provider_ID where pf.Family_ID is null or pf.Family_ID not in (select F_ID from family)',
            'no_family' => 'select distinct pmp.MicroProject_ID from person_microproject pmp where not exists (select 1 from person_family pf where pf.Person_ID = pmp.Person_ID)',
            'partners' => 'select MicroProject_ID from person_microproject group by MicroProject_ID having count(*) > 1',
            'two_openings' => "select MicroProject_ID from visit where Kind like '%op' group by MicroProject_ID having count(*) > 1",
            'two_numbers' => 'select distinct pmp.MicroProject_ID from mevisit mv join person_microproject pmp on pmp.Person_ID = mv.Person_ID group by pmp.MicroProject_ID, mv.Person_ID, mv.Number having count(*) > 1',
        ];
        $this->explained = [];
        foreach ($this->sources as $city => $pdo) {
            foreach ($sql as $rule => $query) {
                foreach ($this->query($city, $query, PDO::FETCH_COLUMN) as $number) {
                    $this->explained[$rule]["$city|$number"] = true;
                }
            }
        }
        $this->explained['partner_visits'] = $this->explained['partners'] ?? [];

        return $this->explained;
    }

    private const RULES = [
        'multi' => 'A person with several projects: the desktop shows their M&E visits under each project, the web under the project the visit is linked to',
        'skipped' => 'Family rows without a family book, skipped by the M3 transfer (recorded in reconcile-<city>.json)',
        'no_family' => 'Applicant without a family row: the desktop detail search cannot reach their records, the web lists them',
        'partners' => 'A project with partners: the desktop repeats the row once per applicant (beneficiary and partners); the web lists it once',
        'partner_visits' => 'A project with partners: the desktop visit dates read the M&E visits of the beneficiary only, the web those of the project (partners included)',
        'two_openings' => 'Two opening visits: the desktop shows the first entered, the web the earliest date',
        'two_numbers' => 'Two M&E visits with the same number: the desktop shows the first entered, the web the earliest date',
        'untyped' => 'Project files of the desktop file table (PDF, no image type): the desktop attachment search reads the image table only',
        'rounded' => 'Amount with three decimals stored with two (money columns have two decimals); rounding accepted by the owner, 24 Nov 2026',
    ];

    /**
     * Two multisets of lines (each starting "CITY|number|"): counts, then a few lines only on each side. A differing line is a known
     * difference when its project falls under one of $rules (see explained()) on the side the rule allows, or, with 'repeat', when the
     * desktop only has more copies of a line the web also has. Cells at the $private indexes (names, national numbers, birth dates, free
     * text about people) are masked in the examples, so the report holds no personal data.
     */
    private function compareLines(string $check, string $mode, array $web, array $desktop, array $private = [], array $rules = []): void
    {
        $explained = $rules === [] ? [] : $this->explained();
        $explain = function (string $line, string $side, bool $alsoOther) use ($rules, $explained): ?string {
            $project = implode('|', array_slice(explode('|', $line), 0, 2));
            foreach ($rules as $rule => $sides) {
                if (! in_array($side, $sides, true)) {
                    continue;
                }
                if ($rule === 'untyped' ? str_ends_with($line, '|') : isset($explained[$rule][$project])) {
                    return $rule;
                }
            }

            return null;
        };
        $mask = function (string $line) use ($private): string {
            $cells = explode('|', $line);
            foreach ($private as $i) {
                if (isset($cells[$i]) && $cells[$i] !== '') {
                    $cells[$i] = '•';
                }
            }

            return implode('|', $cells);
        };
        sort($web);
        sort($desktop);
        $this->count($check, $mode, 'web '.count($web).' / desktop '.count($desktop));
        if ($web === $desktop) {
            return;
        }
        $webCounts = array_count_values($web);
        $deskCounts = array_count_values($desktop);
        $onlyWeb = [];
        $onlyDesktop = [];
        foreach ([[$webCounts, $deskCounts, 'web', &$onlyWeb], [$deskCounts, $webCounts, 'desktop', &$onlyDesktop]] as [$mine, $other, $side, &$only]) {
            foreach ($mine as $line => $n) {
                $extra = $n - ($other[$line] ?? 0);
                if ($extra <= 0) {
                    continue;
                }
                if ($rule = $explain((string) $line, $side, isset($other[$line]))) {
                    for ($i = 0; $i < $extra; $i++) {
                        $this->known(self::RULES[$rule]." ($mode)", $mask((string) $line));
                    }

                    continue;
                }
                $only[] = $line.(isset($other[$line]) ? " (×$extra more)" : '');
            }
        }
        unset($only);
        // A web line and a desktop line that are equal once every number is rounded to two decimals: an amount stored with two decimals.
        $rounded = fn (string $line) => implode('|', array_map(fn ($c) => is_numeric($c) && str_contains($c, '.') ? (string) round((float) $c, 2) : $c, explode('|', $line)));
        $deskByRounded = [];
        foreach ($onlyDesktop as $i => $line) {
            $deskByRounded[$rounded($line)][] = $i;
        }
        foreach ($onlyWeb as $i => $line) {
            $key = $rounded($line);
            if (! empty($deskByRounded[$key])) {
                $j = array_shift($deskByRounded[$key]);
                $desk = explode('|', $onlyDesktop[$j]);
                $changed = array_values(array_filter(array_map(fn ($c, $w) => $c !== $w ? "$c → $w" : null, $desk, explode('|', $line))));
                $this->known(self::RULES['rounded']." ($mode)", $desk[0].'|'.$desk[1].' '.implode(', ', $changed));
                unset($onlyWeb[$i], $onlyDesktop[$j]);
            }
        }
        $onlyWeb = array_values($onlyWeb);
        $onlyDesktop = array_values($onlyDesktop);
        if ($onlyWeb === [] && $onlyDesktop === []) {
            return;
        }
        $this->note($check, "$mode differing lines", 'only web '.count($onlyWeb).', only desktop '.count($onlyDesktop));
        foreach (array_slice($onlyWeb, 0, 3) as $x) {
            $this->report['checks'][$check]['examples'][$mode]['only web'][] = $mask($x);
        }
        foreach (array_slice($onlyDesktop, 0, 3) as $x) {
            $this->report['checks'][$check]['examples'][$mode]['only desktop'][] = $mask($x);
        }
    }

    private static function joined(array $cells): string
    {
        return implode('|', $cells);
    }

    // ------------------------------------------------------------------ 1. applications

    /** @var array<string, array<int, array>>|null city => number => desktop row (name, request date, status) */
    private ?array $desktopApps = null;

    private function desktopApps(): array
    {
        if ($this->desktopApps !== null) {
            return $this->desktopApps;
        }
        $this->desktopApps = [];
        foreach ($this->sources as $city => $pdo) {
            $sql = 'select MP.MP_ID pn, MP.MP_Name name, MP.MP_DateOfRequest req, state.Name_en status from microproject MP
                left join microproject_state mps on mps.MicroProject_ID = MP.MP_ID and mps.Current_State = 1 left join state on state.ID = mps.State_ID
                where MP.MP_ID in ('.$this->in($city).')';
            foreach ($this->query($city, $sql) as $d) {
                $this->desktopApps[$city][(int) $d['pn']] ??= $d;
            }
        }

        return $this->desktopApps;
    }

    private function applications(SearchService $search): void
    {
        $desktop = $this->desktopApps();
        $seen = [];
        foreach ($this->export($search, 'applications') as $w) {
            $key = $w['_city'].'|'.$w['_number'];
            if (isset($seen[$key])) {
                continue; // one row per applicant: the project fields are the same on each
            }
            $seen[$key] = true;
            $d = $desktop[$w['_city']][$w['_number']] ?? null;
            if ($d === null) {
                $this->note('applications', 'missing in desktop', $key);

                continue;
            }
            foreach ([['description', self::norm($d['name']), self::norm($w['Description'])], ['request date', self::day($d['req']), self::day($w['Request date'])],
                ['status', (string) $d['status'], (string) $w['Status']]] as [$field, $expected, $actual]) {
                if ($expected !== $actual) {
                    $this->note('applications', $field, "$key desktop=$expected web=$actual");
                }
            }
        }
        foreach ($desktop as $city => $rows) {
            foreach (array_keys($rows) as $number) {
                if (! isset($seen["$city|$number"])) {
                    $this->note('applications', 'missing in web', "$city|$number");
                }
            }
        }
        $this->count('applications', 'projects compared', count($seen));
    }

    // ------------------------------------------------------------------ 2. statistics (status by city and request year, from the application export's own projects)

    private function statistics(SearchService $search): void
    {
        $desktopCells = [];
        foreach ($this->desktopApps() as $city => $rows) {
            foreach ($rows as $r) {
                $status = (string) ($r['status'] ?? '');
                $desktopCells["status|$status|$city"] = ($desktopCells["status|$status|$city"] ?? 0) + 1;
                $year = substr(self::day($r['req']), 0, 4);
                $desktopCells["year|$year|$city"] = ($desktopCells["year|$year|$city"] ?? 0) + 1;
            }
        }
        $webCells = [];
        $seen = [];
        foreach ($this->export($search, 'applications') as $w) {
            if (isset($seen[$w['_city'].'|'.$w['_number']])) {
                continue;
            }
            $seen[$w['_city'].'|'.$w['_number']] = true;
            $webCells["status|{$w['Status']}|{$w['_city']}"] = ($webCells["status|{$w['Status']}|{$w['_city']}"] ?? 0) + 1;
            $year = substr(self::day($w['Request date']), 0, 4);
            $webCells["year|$year|{$w['_city']}"] = ($webCells["year|$year|{$w['_city']}"] ?? 0) + 1;
        }
        foreach ($desktopCells + $webCells as $key => $unused) {
            if (($desktopCells[$key] ?? 0) !== ($webCells[$key] ?? 0)) {
                $this->note('statistics', 'cell', "$key web=".($webCells[$key] ?? 0).' desktop='.($desktopCells[$key] ?? 0));
            }
        }
        $this->count('statistics', 'cells compared', count($desktopCells + $webCells));
    }

    // ------------------------------------------------------------------ 3. loans (Search_Form.Loan_bind, the 15 figures)

    private const LOAN_SQL = <<<'SQL'
        select L.MicroProject_ID as pn, state.ID as state_id,
         Round(CASE WHEN Currency = 'SP' THEN Loan_Amount WHEN Currency = 'USD' THEN Loan_Amount * ExchangeRate END,2) AS sp,
         Round(CASE WHEN Currency = 'SP' THEN Loan_Amount / ExchangeRate WHEN Currency = 'USD' THEN Loan_Amount END,2) AS usd,
         Round((L.Loan_ReturnedAmount * 100)/L.Loan_Amount, 2) as rate, L.Loan_ReturnedAmount as ret, L.Loan_PaymentsAmount as inst,
         (select sum(`Pay_Amount`) from `payment` where `Loan_ID` = L.Loan_ID and `Pay_IsPaid` like N'Paid') as paid,
         IF((L.Loan_ReturnedAmount - (select sum(`Pay_Amount`) from `payment` where `Pay_IsPaid` like N'Paid' and `Loan_ID` = L.Loan_ID))<0,0,(L.Loan_ReturnedAmount - (select sum(`Pay_Amount`) from `payment` where `Pay_IsPaid` like N'Paid' and `Loan_ID` = L.Loan_ID))) as remaining,
         Round( (L.Loan_ReturnedAmount / L.Loan_PaymentsAmount),2) as total_count,
         (select count(Pay_Amount) from `payment` where `Loan_ID` = L.Loan_ID and `Pay_IsPaid` like N'Paid') as paid_count,
         ((IF(((L.Loan_Amount * L.Loan_Rate / 100) - (select sum(`Pay_Amount`) from `payment` where `Pay_IsPaid` like N'Paid' and `Loan_ID` = L.Loan_ID))<0,0,((L.Loan_Amount * L.Loan_Rate / 100) - (select sum(`Pay_Amount`) from `payment` where `Pay_IsPaid` like N'Paid' and `Loan_ID` = L.Loan_ID))))/ L.Loan_PaymentsAmount) as remaining_count,
         (SELECT `Pay_DueDate` FROM `payment` WHERE `Loan_ID` = L.Loan_ID ORDER BY `payment`.`Pay_DueDate` ASC LIMIT 1) as first_due,
         (SELECT `Pay_RecievedOnDate` FROM `payment` WHERE `Loan_ID` = L.Loan_ID and `Pay_IsPaid` like 'Paid' ORDER BY `payment`.`Pay_RecievedOnDate` DESC LIMIT 1) as last_paid,
         (SELECT `Pay_DueDate` FROM `payment` WHERE `Loan_ID` = L.Loan_ID and `Pay_IsPaid` like 'Not Paid' AND state.Name_ar like 'ممول' ORDER BY `payment`.`Pay_DueDate` DESC LIMIT 1) as due_date,
         IF(IF((L.Loan_ReturnedAmount - (select sum(`Pay_Amount`) from `payment` where `Pay_IsPaid` like N'Paid' and `Loan_ID` = L.Loan_ID)) < 0,0,(L.Loan_ReturnedAmount - (select sum(`Pay_Amount`) from `payment` where `Pay_IsPaid` like N'Paid' and `Loan_ID` = L.Loan_ID))) = 0,0,
           (SELECT TIMESTAMPDIFF(MONTH, payment.Pay_DueDate , DATE_ADD( Now(),INTERVAL 1 MONTH)) from payment where payment.Loan_ID = L.Loan_ID and payment.Pay_IsPaid = 'Not Paid' and state.ID != 7 ORDER by Pay_DueDate desc LIMIT 1 )) as months_late,
         (select count(Pay_Amount) from `payment` where `Loan_ID` = L.Loan_ID and `Pay_IsPaid` like N'Not Paid') as unpaid_count
         from `loan` L LEFT OUTER join `microproject` MP on L.MicroProject_ID = MP.MP_ID
         LEFT OUTER JOIN `microproject_state` mps on mps.MicroProject_ID = MP.MP_ID LEFT OUTER JOIN `state` on state.ID = mps.State_ID
         where Current_State = 1 AND L.MicroProject_ID in (%s) Group By MP_ID
        SQL;

    private const LOAN_COLUMNS = ['Loan (SYP)' => 'sp', 'Loan (USD)' => 'usd', 'Return rate %' => 'rate', 'Amount to return' => 'ret', 'Instalment' => 'inst', 'Paid amount' => 'paid',
        'Remaining amount' => 'remaining', 'Total instalments' => 'total_count', 'Paid instalments' => 'paid_count', 'Remaining instalments' => 'remaining_count',
        'First instalment date' => 'first_due', 'Last paid date' => 'last_paid', 'Due instalment date' => 'due_date', 'Months late' => 'months_late', 'Unpaid instalments' => 'unpaid_count'];

    private function loans(SearchService $search): void
    {
        $web = [];
        foreach ($this->export($search, 'loans') as $w) {
            $web[$w['_city'].'|'.$w['_number']] = $w;
        }
        $desktopKeys = [];
        foreach (array_keys($this->sources) as $city) {
            foreach ($this->query($city, sprintf(self::LOAN_SQL, $this->in($city))) as $d) {
                $key = $city.'|'.$d['pn'];
                $desktopKeys[$key] = true;
                $w = $web[$key] ?? null;
                if ($w === null) {
                    $this->note('loans', 'missing in web', $key);

                    continue;
                }
                foreach (self::LOAN_COLUMNS as $column => $field) {
                    $date = str_ends_with($column, 'date');
                    $e = $d[$field] === null ? '' : ($date ? self::day($d[$field]) : (string) $d[$field]);
                    $a = $date ? self::day($w[$column] ?? '') : str_replace(',', '', (string) ($w[$column] ?? ''));
                    if ($e === $a || (is_numeric($e) && is_numeric($a) && abs((float) $e - (float) $a) < 0.0001)) {
                        continue;
                    }
                    if ($column === 'Total instalments' && is_numeric($e) && is_numeric($a) && abs((float) $e - (float) $a) <= 0.0101) {
                        $this->known('Total instalments: one-cent rounding (the desktop rounds a float column)', "$key desktop=$e web=$a");
                    } elseif ($column === 'Remaining instalments' && is_numeric($e) && is_numeric($a) && abs((float) $e - (float) $a) <= 0.0051) {
                        $this->known('Remaining instalments rounded to 2 decimals (the desktop value is unrounded)', "$key desktop=$e web=$a");
                    } elseif ($e === '' && $column === 'Paid amount' && is_numeric($a) && (float) $a === 0.0) {
                        $this->known('Paid amount blank in the desktop, 0 in the web', $key);
                    } elseif ($e === '' && $d['paid'] === null && in_array($column, ['Remaining amount', 'Remaining instalments'], true)) {
                        // The desktop subtracts a NULL sum when nothing is paid, which blanks the figure; the web shows the full amount (spec M4.17).
                        $this->known('Remaining amount and instalments blank in the desktop when nothing is paid; the web shows them in full', "$key $column web=$a");
                    } elseif ($column === 'Months late' && $e === '' && $a === '0' && (float) $d['ret'] === 0.0 && (int) $d['unpaid_count'] === 0) {
                        $this->known('Months late: a loan with nothing to return and no instalments is blank in the desktop, 0 in the web', $key);
                    } elseif ($column === 'Months late' && $a === '' && (int) $d['state_id'] === 8) {
                        $this->known('Months late blank in the web for Closed projects (the desktop blanks it for Canceled only; spec M4.17)', "$key desktop=$e");
                    } elseif ($column === 'Return rate %' && is_numeric($e) && is_numeric($a) && abs((float) $e - (float) $a) <= 0.0101) {
                        $this->known('Return rate: last-digit rounding (the desktop rounds a float, the web rounds the exact decimal half up)', "$key desktop=$e web=$a");
                    } else {
                        $this->note('loans', $column, "$key desktop=$e web=$a");
                    }
                }
            }
        }
        foreach (array_keys($web) as $key) {
            if (! isset($desktopKeys[$key])) {
                $this->note('loans', 'missing in desktop', $key);
            }
        }
        $this->count('loans', 'loans compared', count($desktopKeys));
    }

    // ------------------------------------------------------------------ 4. instalments (the payment schedule export)

    private function instalments(): void
    {
        $web = [];
        $webTotals = [];
        $codes = DB::table('cities')->pluck('code', 'name_en')->all();
        $schedule = app(PaymentScheduleService::class);
        $all = (function () use ($schedule) {
            foreach (['unpaid', 'paid'] as $view) {
                yield from $schedule->exportRows($this->account, ['view' => $view]);
            }
        })();
        foreach ($all as $row) {
            [$city, $project, , , $currency, $amount, , $status] = $row;
            $code = $codes[$city] ?? $city;
            $paid = $status === __('finance.installments.paid') ? 'Paid' : 'Not paid';
            $key = $code.'|'.$this->number((string) $project).'|'.$currency.'|'.$paid;
            $web[$key] = ['count' => ($web[$key]['count'] ?? 0) + 1, 'sum' => bcadd($web[$key]['sum'] ?? '0', (string) $amount, 2)];
            $total = $code.'|'.$currency.'|'.$paid;
            $webTotals[$total] = ['count' => ($webTotals[$total]['count'] ?? 0) + 1, 'sum' => bcadd($webTotals[$total]['sum'] ?? '0', (string) $amount, 2)];
        }
        $desk = [];
        $deskTotals = [];
        foreach (array_keys($this->sources) as $city) {
            $sql = 'select L.MicroProject_ID pn, L.Currency cur, P.Pay_IsPaid paid, count(*) n, sum(P.Pay_Amount) s from payment P join loan L on L.Loan_ID = P.Loan_ID
                where L.MicroProject_ID in ('.$this->in($city).') group by L.MicroProject_ID, L.Currency, P.Pay_IsPaid';
            foreach ($this->query($city, $sql) as $d) {
                $label = $d['paid'] === 'Paid' ? 'Paid' : 'Not paid';
                $currency = $d['cur'] === 'SP' ? 'SYP' : $d['cur'];
                $sum = number_format((float) $d['s'], 2, '.', '');
                $key = "$city|{$d['pn']}|$currency|$label";
                $desk[$key] = ['count' => (int) $d['n'] + ($desk[$key]['count'] ?? 0), 'sum' => bcadd($desk[$key]['sum'] ?? '0', $sum, 2)];
                $totalKey = "$city|$currency|$label";
                $deskTotals[$totalKey] = ['count' => (int) $d['n'] + ($deskTotals[$totalKey]['count'] ?? 0), 'sum' => bcadd($deskTotals[$totalKey]['sum'] ?? '0', $sum, 2)];
            }
        }
        foreach ([['per project, currency and state', $desk, $web], ['total by city, currency and state', $deskTotals, $webTotals]] as [$item, $d, $w]) {
            foreach ($d + $w as $key => $unused) {
                $a = $d[$key] ?? null;
                $b = $w[$key] ?? null;
                if ($a === null || $b === null || $a['count'] !== $b['count'] || bccomp($a['sum'], $b['sum'], 2) !== 0) {
                    $this->note('instalments', $item, "$key desktop=".json_encode($a).' web='.json_encode($b));
                }
            }
        }
        foreach ($webTotals as $key => $value) {
            $this->count('instalments', $key, $value['count'].' instalments, '.$value['sum']);
        }
    }

    // ------------------------------------------------------------------ 5. family (FamilyMember_bind)

    private function family(SearchService $search): void
    {
        $web = [];
        foreach ($this->export($search, 'family') as $w) {
            $web[] = self::joined([$w['_city'], (string) ($this->number((string) $w['Project']) ?? ''), self::norm($w['Family book']), self::norm($w['Person']), self::norm($w['Father']),
                self::norm($w['Mother']), self::norm($w['National number']), self::norm($w['Sex']), self::norm($w['Date of birth'], true), self::norm($w['Works']), self::norm($w['Work'])]);
        }
        $sex = ['ذكر' => 'Male', 'أنثى' => 'Female'];
        $desktop = [];
        foreach (array_keys($this->sources) as $city) {
            $sql = "select f.F_Number book, CONCAT(P_FirstName, ' ', P_LastName) person, P_FatherName father, P_MotherName mother, p.P_NationalNumber nat, p.P_Sex sex, DATE(p.P_DOB) dob,
                case pf.HasWork when 1 then 'Yes' when 0 then 'No' else '' end works, pf.Work_Name work, PMP.MicroProject_ID project
                from person_family pf join person p on p.P_ID = pf.Person_ID join family f on f.F_ID = pf.Family_ID
                left join person_microproject PMP on PMP.Person_ID = COALESCE(pf.P_Provider_ID, pf.Person_ID)
                where PMP.MicroProject_ID is null or PMP.MicroProject_ID in (".$this->in($city).')';
            foreach ($this->query($city, $sql) as $d) {
                $desktop[] = self::joined([$city, (string) $d['project'], self::norm($d['book']), self::norm($d['person']), self::norm($d['father']), self::norm($d['mother']),
                    self::norm($d['nat']), $sex[trim((string) $d['sex'])] ?? '', self::norm($d['dob'], true), self::norm($d['works']), self::norm($d['work'])]);
            }
        }
        $this->compareLines('family', 'family members', $web, $desktop, [2, 3, 4, 5, 6, 8, 10]);
    }

    // ------------------------------------------------------------------ 6. detail modes (bind_person_tables / bind_project_tables)

    private function details(SearchService $search): void
    {
        $sub = fn (string $t, string $fk, string $name = 'Name') => "(SELECT $name FROM `$t` WHERE `$t`.ID = $fk LIMIT 1)";
        $modes = [
            'aid-sources' => ['person', 'person_aidsource', $sub('aidsource', 'mytable.AidSource_ID').' c1, mytable.DateYear c2, mytable.Amount c3, mytable.ProjectType c4, mytable.Notes c5', '', []],
            'assets' => ['person', 'person_asset', $sub('assettype', 'mytable.Asset_Type_ID').' c1, mytable.`Year` c2, '.$sub('asset', 'mytable.Asset_ID').' c3, mytable.Description c4, '.$sub('assetbenefits', 'mytable.Asset_Benefits_ID').' c5, mytable.Note c6', '', []],
            'courses' => ['person', 'person_course', $sub('subcategory', 'mytable.SubCategory_ID').' c1, mytable.Name c2, mytable.Place c3, mytable.Donor c4, mytable.`Date` c5', '', [4]],
            'goals' => ['person', 'person_goals', $sub('goal', 'mytable.Goal_ID').' c1', '', []],
            'relief-aids' => ['person', 'person_help', $sub('aidsource', 'mytable.AidSource_ID').' c1, '.$sub('aid', 'mytable.Aid_ID').' c2, '.$sub('aidperiod', 'mytable.AidPeriod_ID').' c3, mytable.Notes c4', '', []],
            'illness' => ['person', 'person_illness', $sub('illness', 'mytable.Illness_ID')." c1, mytable.Note c2, (CASE mytable.Has_MedicalAid WHEN 1 THEN 'نعم' WHEN 0 THEN 'لا' ELSE '' END) c3, ".$sub('aidsource', 'mytable.AidSource_ID').' c4, '.$sub('aidperiod', 'mytable.AidPeriod_ID').' c5, '.$sub('medicalneeds', 'mytable.MedicalNeeds_ID').' c6', '', []],
            'losses' => ['person', 'person_loss', $sub('loss', 'mytable.Loss_ID').' c1, mytable.`Year` c2, mytable.Note c3', '', []],
            'home-power' => ['person', 'person_powersource', $sub('powersource', 'mytable.PowerSource_ID').' c1, mytable.Note c2, mytable.Cost c3, mytable.Responsability c4', '', []],
            'current-works' => ['person', 'person_workexp', '(SELECT W_Name FROM `work` WHERE `work`.W_ID = mytable.Work_ID LIMIT 1) c1, '.$sub('worktype', 'mytable.W_WorkType_ID').' c2, mytable.W_Description c3, mytable.W_BeginDate c4, mytable.W_PlaceState c5, mytable.W_Place c6, mytable.W_Contact c7, mytable.W_Salary c8', " and lower(mytable.W_Status) like 'yes'", [3]],
            'work-experiences' => ['person', 'person_workexp', '(SELECT W_Name FROM `work` WHERE `work`.W_ID = mytable.Work_ID LIMIT 1) c1, '.$sub('worktype', 'mytable.W_WorkType_ID').' c2, mytable.W_Description c3, mytable.W_BeginDate c4, mytable.W_EndDate c5, mytable.W_PlaceState c6, mytable.W_Place c7, mytable.W_Contact c8, mytable.W_CauseOfLoseDescription c9', " and lower(mytable.W_Status) like 'no'", [3, 4]],
            'materials' => ['project', 'material', 'mytable.Name c1, mytable.Comments c2, mytable.Amount c3, mytable.Price c4', '', []],
            'project-power' => ['project', 'microproject_powersource', $sub('powersource', 'mytable.PowerSource_ID').' c1, mytable.Note c2, mytable.Cost c3, mytable.Responsability c4', '', []],
        ];
        foreach ($modes as $mode => [$kind, $table, $select, $extra, $dates]) {
            $web = [];
            foreach ($this->export($search, $mode, [], 'ar') as $w) {
                $values = array_values(array_diff_key($w, ['_city' => 1, '_number' => 1]));
                $line = [$w['_city'], (string) $w['_number'], self::norm($values[2]), self::norm($values[3])];
                foreach (array_slice($values, 4, preg_match_all('/ c\d+\b/', $select)) as $i => $cell) {
                    $line[] = self::norm($cell, in_array($i, $dates, true));
                }
                $web[] = self::joined($line);
            }
            $desktop = [];
            foreach (array_keys($this->sources) as $city) {
                $in = $this->in($city);
                $sql = $kind === 'person'
                    ? "select PMP.MicroProject_ID pn, CONCAT(P_FirstName, ' ', P_LastName) who, (SELECT Name FROM relation WHERE relation.ID = person_family.Relation_ID LIMIT 1) rel, $select
                        FROM $table mytable left JOIN person P on mytable.Person_ID = P.P_ID LEFT JOIN person_family on person_family.Person_ID = mytable.Person_ID
                        LEFT JOIN person_microproject PMP on person_family.P_Provider_ID = PMP.Person_ID left JOIN microproject MP on MP.MP_ID = PMP.MicroProject_ID
                        LEFT JOIN microproject_state mps on mps.MicroProject_ID = MP.MP_ID LEFT JOIN state on state.ID = mps.State_ID
                        where Current_State = 1 $extra and PMP.MicroProject_ID in ($in)"
                    : "select MP.MP_ID pn, CONCAT(P_FirstName, ' ', P_LastName) who, P.P_FatherName rel, $select
                        FROM $table mytable left JOIN microproject MP on mytable.MicroProject_ID = MP.MP_ID LEFT JOIN person_microproject PMP on mytable.MicroProject_ID = PMP.MicroProject_ID
                        left JOIN person P on PMP.Person_ID = P.P_ID LEFT JOIN microproject_state mps on mps.MicroProject_ID = MP.MP_ID LEFT JOIN state on state.ID = mps.State_ID
                        where Current_State = 1 $extra and MP.MP_ID in ($in)";
                foreach ($this->query($city, $sql) as $d) {
                    $line = [$city, (string) $d['pn'], self::norm($d['who']), self::norm($d['rel'])];
                    for ($i = 1; array_key_exists('c'.$i, $d); $i++) {
                        $line[] = self::norm($d['c'.$i], in_array($i - 1, $dates, true));
                    }
                    $desktop[] = self::joined($line);
                }
            }
            $this->compareLines('details', $mode, $web, $desktop, $kind === 'project' ? [2, 3] : [2], ['skipped' => ['desktop', 'web'], 'no_family' => ['web']] + ($kind === 'project' ? ['partners' => ['desktop']] : []));
        }
    }

    // ------------------------------------------------------------------ 7. other search modes

    private function otherModes(SearchService $search): void
    {
        $joins = ' LEFT JOIN category C ON MP.Category_ID = C.ID LEFT JOIN subcategory sub ON MP.SubCategory_ID = sub.ID LEFT JOIN career ON MP.Career_ID = career.ID
            LEFT JOIN microproject_state mps ON mps.MicroProject_ID = MP.MP_ID LEFT JOIN state ON state.ID = mps.State_ID';
        $full = "CONCAT(P_FirstName, ' ', P_LastName, ' ابن/ة ', P_FatherName)";
        $name = "CONCAT(P_FirstName, ' ', P_LastName)";
        // mode => [[web heading, is date], …], desktop SQL selecting the project number then those columns in order.
        $modes = [
            'attachments' => [[['Career', false], ['Category', false]],
                fn ($in) => "select MP.MP_ID, career.Name, imagetype.Category FROM image LEFT JOIN imagetype ON image.ImageType_ID = imagetype.ID LEFT JOIN microproject MP ON image.MicroProject_ID = MP.MP_ID $joins where Current_State = 1 AND MP.MP_ID in ($in)"],
            'visit-dates' => [[['Career', false], ['Category', false], ['Sub category', false], ['Opening visit', true], ['M1', true], ['M2', true], ['M3', true], ['M4', true], ['M5', true], ['Closing visit', true]],
                function ($in) use ($joins) {
                    $me = fn (int $n) => "(select mevisit.Date from mevisit where mevisit.Person_ID = (select PMP2.Person_ID from person_microproject PMP2 where PMP2.MicroProject_ID = MP.MP_ID order by (PMP2.PersonType = 'مستفيد') desc, PMP2.PMP_ID limit 1) and mevisit.Number like '$n' order by mevisit.ID limit 1)";

                    return "select MP.MP_ID, career.Name, C.Name, sub.Name, (select Date from visit where visit.MicroProject_ID = MP.MP_ID and Kind like '%op' order by visit.ID limit 1), {$me(1)}, {$me(2)}, {$me(3)}, {$me(4)}, {$me(5)},
                        (select Date from visit where visit.MicroProject_ID = MP.MP_ID and Kind like '%cl' order by visit.ID limit 1) from microproject MP $joins where Current_State = 1 AND MP.MP_ID in ($in) group by MP.MP_ID";
                }],
            'me-visits' => [[['Beneficiary', false], ['Career', false], ['Visit date', true], ['Visit number', false], ['Net profit', false], ['Result', false], ['Visitor 1 mark', false], ['Visitor 2 mark', false], ['Category', false], ['Sub category', false]],
                fn ($in) => "select MP.MP_ID, $full, career.Name, mevisit.Date, Number, Profit, Result,
                    IFNULL((select mevisit_user.Mark FROM mevisit_user WHERE mevisit_user.Visit_ID = mevisit.ID ORDER by mevisit_user.ID ASC LIMIT 1),''), IFNULL((select mevisit_user.Mark FROM mevisit_user WHERE mevisit_user.Visit_ID = mevisit.ID ORDER by mevisit_user.ID DESC LIMIT 1),''),
                    C.Name, sub.Name FROM mevisit LEFT JOIN person P ON P.P_ID = mevisit.Person_ID LEFT JOIN person_microproject PMP ON PMP.Person_ID = P.P_ID LEFT JOIN microproject MP ON MP.MP_ID = PMP.MicroProject_ID
                    $joins where Current_State = 1 AND MP.MP_ID in ($in)"],
            'special-cases' => [[['Beneficiary', false], ['Father', false], ['Career', false], ['Review date', true], ['Reason', false], ['Description', false], ['Action', false]],
                fn ($in) => "select MP_ID, $name, P.P_FatherName, career.Name, sc.Date, sc.Reason, sc.Description, sc.Action FROM specialcase sc LEFT JOIN microproject MP on MP.MP_ID = sc.MicroProject_ID
                    LEFT JOIN person_microproject PMP on MP.MP_ID = PMP.MicroProject_ID LEFT JOIN person P on PMP.Person_ID = P.P_ID $joins where Current_State = 1 and MP_ID in ($in)"],
            'educations' => [[['Person', false], ['Relation', false], ['Education level', false], ['Degree', false], ['Specialization', false]],
                fn ($in) => "select PMP.MicroProject_ID, $name, (SELECT Name FROM relation WHERE relation.ID = pf.Relation_ID LIMIT 1),
                    IFNULL((SELECT Name FROM educationlevel WHERE educationlevel.ID = COALESCE(pf.EducationLevel_ID, pe.Level_ID) LIMIT 1),''),
                    IFNULL((SELECT et.Name FROM educationtype et LEFT JOIN education e ON et.ID = e.Type_ID WHERE e.ID = COALESCE(pf.Education_ID, pe.Education_ID) LIMIT 1),''),
                    IFNULL((SELECT Name FROM education WHERE education.ID = COALESCE(pf.Education_ID, pe.Education_ID) LIMIT 1),'')
                    FROM person_family pf LEFT JOIN person P ON P.P_ID = pf.Person_ID LEFT JOIN person_microproject PMP ON PMP.Person_ID = pf.P_Provider_ID
                    LEFT JOIN person_education pe ON pe.Person_ID = pf.P_Provider_ID AND pf.Person_ID = pf.P_Provider_ID LEFT JOIN microproject MP ON MP.MP_ID = PMP.MicroProject_ID $joins where Current_State = 1 and PMP.MicroProject_ID in ($in)"],
        ];
        foreach ($modes as $mode => [$columns, $sqlFor]) {
            $web = [];
            foreach ($this->export($search, $mode, [], 'ar') as $w) {
                $line = [$w['_city'], (string) $w['_number']];
                foreach ($columns as [$heading, $date]) {
                    $line[] = self::norm($w[$heading] ?? '', $date);
                }
                $web[] = self::joined($line);
            }
            $desktop = [];
            foreach (array_keys($this->sources) as $city) {
                foreach ($this->query($city, $sqlFor($this->in($city)), PDO::FETCH_NUM) as $d) {
                    $line = [$city, (string) $d[0]];
                    foreach ($columns as $i => [, $date]) {
                        $line[] = self::norm($d[$i + 1] ?? '', $date);
                    }
                    $desktop[] = self::joined($line);
                }
            }
            $private = array_keys(array_filter(array_column($columns, 0), fn ($h) => in_array($h, ['Beneficiary', 'Father', 'Person', 'Reason', 'Description', 'Action'], true)));
            $rules = match ($mode) {
                'me-visits' => ['multi' => ['desktop', 'web']], 'visit-dates' => ['multi' => ['desktop', 'web'], 'two_openings' => ['desktop', 'web'], 'two_numbers' => ['desktop', 'web'], 'partner_visits' => ['desktop', 'web']], 'special-cases' => ['partners' => ['desktop']], 'attachments' => ['untyped' => ['web']], 'educations' => ['skipped' => ['desktop', 'web']], default => []
            };
            $this->compareLines('other modes', $mode, $web, $desktop, array_map(fn ($i) => $i + 2, $private), $rules);
        }
    }

    // ------------------------------------------------------------------ 8. state history

    private function stateHistory(): void
    {
        $lifecycle = app(LifecycleService::class);
        $rows = 0;
        foreach (DB::table('cities')->orderBy('id')->get(['id', 'code']) as $city) {
            $desktop = [];
            foreach ($this->query($city->code, 'select mps.MicroProject_ID pn, state.Name_en n, DATE(mps.Date) d, mps.Current_State c from microproject_state mps left join state on state.ID = mps.State_ID where mps.MicroProject_ID in ('.$this->in($city->code).')') as $d) {
                $desktop[(int) $d['pn']][] = $d['n'].'|'.self::day($d['d']).'|'.((int) $d['c'] === 1 ? '1' : '0');
            }
            foreach (DB::table('projects')->where('city_id', $city->id)->whereNull('deleted_at')->get(['id', 'project_number']) as $project) {
                $web = $lifecycle->history((int) $project->id)->map(fn ($h) => $h->state_en.'|'.self::day($h->changed_on).'|'.((int) $h->is_current))->all();
                $d = $desktop[(int) $project->project_number] ?? [];
                sort($web);
                sort($d);
                $rows += count($web);
                if ($web !== $d) {
                    $this->note('state history', 'project states', "{$city->code}|{$project->project_number} web=".implode(',', $web).' desktop='.implode(',', $d));
                }
            }
        }
        $this->count('state history', 'state rows compared', $rows);
    }

    // ------------------------------------------------------------------ report

    private function finish(): int
    {
        $this->report['known_differences'] = array_map(fn ($items) => ['count' => count($items), 'examples' => array_slice($items, 0, 3)], $this->report['known_differences']);
        $this->report['failures'] = $this->failures;
        foreach ($this->report['checks'] as $check => &$data) {
            $mismatches = $data['mismatches'] ?? [];
            $this->line(str_pad($check, 14).($mismatches === [] ? 'OK' : 'DIFFERENCES').'  '.json_encode($data['counts'] ?? [], JSON_UNESCAPED_UNICODE));
            foreach ($mismatches as $kind => $items) {
                $this->line('   '.$kind.': '.count($items).' e.g. '.implode(' ; ', array_slice($items, 0, 3)));
                $data['mismatches'][$kind] = ['count' => count($items), 'items' => array_slice($items, 0, 50)];
            }
            foreach ($data['examples'] ?? [] as $mode => $sides) {
                foreach ($sides as $side => $lines) {
                    $this->line("   $mode $side: ".implode(' ; ', $lines));
                }
            }
        }
        unset($data);
        foreach ($this->report['known_differences'] as $kind => $info) {
            $this->line("known difference: $kind ({$info['count']})");
        }
        $this->line($this->failures === 0 ? 'RESULT: reconciled, 0 unexplained differences' : "RESULT: {$this->failures} unexplained differences");
        if ($out = $this->option('out')) {
            // The file keeps counts, project keys and numbers only: the example lines (which can hold free text about people, such as notes
            // or employers) stay on the terminal; for loans and roundings the examples are project numbers and amounts.
            $saved = $this->report;
            foreach ($saved['known_differences'] as $kind => &$info) {
                if (! str_starts_with($kind, 'Remaining') && ! str_starts_with($kind, 'Total') && ! str_starts_with($kind, 'Paid') && ! str_starts_with($kind, 'Months')
                    && ! str_starts_with($kind, 'Return') && ! str_starts_with($kind, 'Amount with three')) {
                    $info['examples'] = array_values(array_unique(array_map(fn ($e) => implode('|', array_slice(explode('|', $e), 0, 2)), $info['examples'])));
                }
            }
            unset($info);
            foreach ($saved['checks'] as &$data) {
                unset($data['examples']);
            }
            unset($data);
            file_put_contents($out, json_encode($saved, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        return $this->failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
