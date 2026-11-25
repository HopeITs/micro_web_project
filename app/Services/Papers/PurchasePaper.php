<?php

declare(strict_types=1);

namespace App\Services\Papers;

use App\Models\WebAccount;
use App\Services\AccountAuditTrail;
use App\Services\Finance\Money;
use App\Services\Finance\PurchasingService;
use App\Services\ProjectNumberAllocator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * M4.20 wave 3: the purchasing papers of a project redrawn on the print engine: the purchase request (F09, طلب مشتريات), the price quote list
 * (F08, قائمة عرض أسعار), the equipment receipt pledge (F20, تعهد استلام المعدات المطلوبة) and the equipment and goods return (F22, مرتجع
 * معدات وبضائع). What the project does not store is typed on one entry page and kept per project (schema v1.6): one purchase request, one
 * price quote, one receipt date and one return. Until a request or a quote is saved, its table is filled from the project's materials; a
 * return lists only what was saved (the purchases are offered on the entry page to tick). Reading needs applications.view and
 * purchasing.view in the project's city, saving purchasing.manage. Audited `papers.printed` (`paper:F09` …) and `papers.data_saved`.
 */
final class PurchasePaper
{
    /** URL part => paper id. */
    public const PAPERS = ['purchase-request' => 'F09', 'price-quote' => 'F08', 'equipment-receipt' => 'F20', 'equipment-return' => 'F22'];

    /** The entry forms: the stored tables, the single fields, the row columns (value = text length or kind) and the rows the paper draws. */
    public const FORMS = [
        'request' => ['paper' => 'purchase-request', 'table' => 'purchase_requests', 'items' => 'purchase_request_items', 'key' => 'purchase_request_id', 'head' => [],
            'columns' => ['material' => 255, 'item_kind' => 100, 'quantity' => 'int', 'material_state' => 'state'], 'required' => 'material', 'rows' => 15],
        'quote' => ['paper' => 'price-quote', 'table' => 'price_quotes', 'items' => 'price_quote_items', 'key' => 'price_quote_id', 'head' => ['supplier_name' => 255, 'quoted_on' => 'date'],
            'columns' => ['item_name' => 255, 'quantity' => 'int', 'unit_price' => 'money'], 'required' => 'item_name', 'rows' => 10],
        'receipt' => ['paper' => 'equipment-receipt', 'table' => 'equipment_receipts', 'items' => null, 'key' => null, 'head' => ['received_on' => 'date'], 'columns' => [], 'required' => null, 'rows' => 0],
        'return' => ['paper' => 'equipment-return', 'table' => 'equipment_returns', 'items' => 'equipment_return_items', 'key' => 'equipment_return_id', 'head' => ['returned_on' => 'date'],
            'columns' => ['item_name' => 255, 'brand' => 255, 'serial_number' => 100, 'technical_state' => 255, 'notes' => 1000], 'required' => 'item_name', 'rows' => 20],
    ];

    public const STATES = ['new', 'used'];

    public const MAX_ROWS = 60;

    public function __construct(private PurchasingService $purchasing, private AccountAuditTrail $audit) {}

    /** @return array<string, mixed> */
    public function forPrint(WebAccount $account, int $projectId, string $kind): array
    {
        $id = self::PAPERS[$kind] ?? abort(404);
        $project = $this->purchasing->project($account, $projectId);
        $city = DB::table('cities')->where('id', $project->city_id)->first();
        $name = app()->isLocale('en') ? 'name_en' : 'name_ar';
        $lookup = fn (string $table, mixed $key) => $key ? (string) DB::table($table)->where('id', $key)->value($name) : '';
        $person = $this->beneficiary($projectId);
        $street = $lookup('streets', $project->street_id);

        $d = [
            'paper' => $id, 'kind' => $kind, 'projectId' => $projectId, 'city' => ['id' => (int) $city->id, 'code' => (string) $city->code],
            'number' => ProjectNumberAllocator::display((string) $city->code, (int) $project->project_number),
            'beneficiary' => $person ? trim(implode(' ', array_filter([$person->first_name, $person->father_name, $person->last_name]))) : '',
            'mobile' => (string) ($person->mobile ?? ''), 'trade' => $lookup('careers', $project->career_id), 'area' => $street !== '' ? $street : (string) ($project->place_address ?? ''),
            'type' => $lookup('project_types', $project->project_type_id),
        ];
        foreach (['projects_manager' => 'manager', 'finance_manager' => 'finance', 'purchasing_officer' => 'officer'] as $role => $key) {
            $d[$key] = (string) (PaperSettings::signatory((int) $city->id, $role)->full_name ?? '');
        }
        $d += match ($kind) {
            'purchase-request' => $this->request($projectId),
            'price-quote' => $this->quote($projectId),
            'equipment-receipt' => $this->receipt($projectId),
            default => $this->returned($projectId),
        };
        $this->audit->record($account->id, 'papers.printed', 'succeeded', (int) $city->id, subjectType: 'application_entity', subjectId: $projectId, decision: 'paper:'.$id);

        return $d;
    }

    /** @return array{number: string, canManage: bool, forms: array<string, array{head: array<string, string>, rows: list<array<string, string>>, saved: bool}>} */
    public function page(WebAccount $account, int $projectId): array
    {
        $project = $this->purchasing->project($account, $projectId);
        $code = (string) DB::table('cities')->where('id', $project->city_id)->value('code');
        $forms = [];
        foreach (self::FORMS as $form => $spec) {
            $record = $this->record($spec['table'], $projectId);
            $head = [];
            foreach (array_keys($spec['head']) as $f) {
                $head[$f] = $record && $record->{$f} !== null ? ($spec['head'][$f] === 'date' ? substr((string) $record->{$f}, 0, 10) : (string) $record->{$f}) : '';
            }
            $rows = [];
            if ($spec['items'] !== null) {
                $rows = $record ? $this->itemRows($spec, (int) $record->id) : $this->suggestions($form, $projectId);
                $blank = array_fill_keys(array_keys($spec['columns']), '');
                $total = min(self::MAX_ROWS, max($spec['rows'], count($rows) + 3));
                while (count($rows) < $total) {
                    $rows[] = $blank;
                }
            }
            $forms[$form] = ['head' => $head, 'rows' => $rows, 'saved' => $record !== null];
        }

        return ['number' => ProjectNumberAllocator::display($code, (int) $project->project_number), 'canManage' => $this->purchasing->canManage($account, (int) $project->city_id), 'forms' => $forms];
    }

    /** @param array<string, mixed> $input */
    public function save(WebAccount $account, int $projectId, string $form, array $input): void
    {
        $spec = self::FORMS[$form] ?? abort(404);
        $project = $this->purchasing->project($account, $projectId);
        $city = (int) $project->city_id;
        if (! $this->purchasing->canManage($account, $city)) {
            $this->audit->record($account->id, 'access.denied', 'denied', $city);
            abort(403);
        }
        $head = [];
        foreach ($spec['head'] as $f => $kind) {
            $head[$f] = $this->value($input[$f] ?? null, $kind, $f);
        }
        $rows = $spec['items'] === null ? [] : $this->submittedRows($spec, $input['rows'] ?? []);
        $now = now('UTC')->format('Y-m-d H:i:s');

        DB::transaction(function () use ($account, $projectId, $form, $spec, $head, $rows, $city, $now) {
            // The project row lock keeps two saves of the same paper from creating two records.
            DB::table('projects')->where('id', $projectId)->lockForUpdate()->value('id');
            $record = $this->record($spec['table'], $projectId);
            $before = [];
            foreach (array_keys($head) as $f) {
                $before[$f] = $record->{$f} ?? null;
            }
            $after = $head;
            if ($spec['items'] !== null) {
                $before['items'] = $record ? $this->summary($this->itemRows($spec, (int) $record->id)) : null;
                $after['items'] = $this->summary($rows);
            }
            if ($record === null) {
                $recordId = DB::table($spec['table'])->insertGetId($head + ['city_id' => $city, 'project_id' => $projectId, 'created_at' => $now, 'updated_at' => $now]);
            } else {
                $recordId = (int) $record->id;
                DB::table($spec['table'])->where('id', $recordId)->update($head + ['updated_at' => $now]);
            }
            if ($spec['items'] !== null) {
                DB::table($spec['items'])->where($spec['key'], $recordId)->whereNull('deleted_at')->update(['deleted_at' => $now, 'updated_at' => $now]);
                foreach ($rows as $row) {
                    DB::table($spec['items'])->insert($row + ['city_id' => $city, $spec['key'] => $recordId, 'created_at' => $now, 'updated_at' => $now]);
                }
            }
            $this->audit->record($account->id, 'papers.data_saved', 'succeeded', $city, subjectType: 'application_entity', subjectId: $projectId, decision: 'paper-data:'.$form,
                details: AccountAuditTrail::changes($before, $after));
        });
    }

    // ---------------------------------------------------------------- entry

    private function value(mixed $input, int|string $kind, string $field): string|int|null
    {
        $v = is_scalar($input) ? trim((string) $input) : '';
        if ($v === '') {
            return null;
        }
        $number = is_int($kind) || $kind === 'date' || $kind === 'state' ? $v : Money::normalize($v);
        $bad = match (true) {
            is_int($kind) => mb_strlen($v) > $kind,
            $kind === 'date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) !== 1 || ! checkdate((int) substr($v, 5, 2), (int) substr($v, 8, 2), (int) substr($v, 0, 4)),
            $kind === 'money' => ! Money::isDecimal($number, 2) || strlen(explode('.', $number)[0]) > 13,
            $kind === 'int' => preg_match('/^\d{1,6}$/', $number) !== 1 || (int) $number < 1,
            default => ! in_array($v, self::STATES, true),
        };
        if ($bad) {
            throw ValidationException::withMessages([$field => __('purchase_data.bad_value')]);
        }

        return match ($kind) {
            'money' => $number,
            'int' => (int) $number,
            default => $v,
        };
    }

    /**
     * The rows to store: empty rows are skipped, and so is a suggested row of the return that was not ticked.
     *
     * @return list<array<string, string|int|null>>
     */
    private function submittedRows(array $spec, mixed $input): array
    {
        $rows = [];
        foreach (is_array($input) ? $input : [] as $index => $row) {
            if (! is_array($row) || (! empty($row['suggested']) && empty($row['include']))) {
                continue;
            }
            if (array_filter(array_keys($spec['columns']), fn ($c) => is_scalar($row[$c] ?? null) && trim((string) $row[$c]) !== '') === []) {
                continue;
            }
            $clean = [];
            foreach ($spec['columns'] as $column => $kind) {
                $clean[$column] = $this->value($row[$column] ?? null, $kind, 'rows.'.(int) $index.'.'.$column);
            }
            if ($clean[$spec['required']] === null) {
                throw ValidationException::withMessages(['rows.'.(int) $index.'.'.$spec['required'] => __('purchase_data.required')]);
            }
            $rows[] = $clean;
        }
        if (count($rows) > self::MAX_ROWS) {
            throw ValidationException::withMessages(['rows' => __('purchase_data.too_many', ['max' => self::MAX_ROWS])]);
        }

        return $rows;
    }

    /** @param list<array<string, mixed>> $rows */
    private function summary(array $rows): ?string
    {
        $lines = array_map(fn ($r) => implode(' | ', array_filter(array_map(fn ($v) => (string) $v, array_diff_key($r, ['suggested' => 1])), fn ($v) => $v !== '')), $rows);

        return $lines === [] ? null : count($lines).': '.implode(' / ', $lines);
    }

    private function record(string $table, int $projectId): ?object
    {
        return DB::table($table)->where('project_id', $projectId)->whereNull('deleted_at')->orderByDesc('id')->first();
    }

    /** The stored rows in entry order, as the form shows them. @return list<array<string, string>> */
    private function itemRows(array $spec, int $recordId): array
    {
        return DB::table($spec['items'])->where($spec['key'], $recordId)->whereNull('deleted_at')->orderBy('id')->get()->map(function ($item) use ($spec) {
            $row = [];
            foreach ($spec['columns'] as $column => $kind) {
                $row[$column] = $kind === 'money' ? (Money::canonical($item->{$column}) ?? '') : (string) ($item->{$column} ?? '');
            }

            return $row;
        })->all();
    }

    /** What the entry page offers before anything is saved. @return list<array<string, string>> */
    private function suggestions(string $form, int $projectId): array
    {
        return match ($form) {
            'request' => $this->materials($projectId)->map(fn ($m) => ['material' => (string) $m->name, 'item_kind' => '', 'quantity' => Money::canonical($m->quantity) ?? '', 'material_state' => ''])->all(),
            'quote' => $this->materials($projectId)->map(fn ($m) => ['item_name' => (string) $m->name, 'quantity' => Money::canonical($m->quantity) ?? '', 'unit_price' => ''])->all(),
            'return' => $this->purchases($projectId)->map(fn ($p) => ['item_name' => (string) $p->item_name, 'brand' => (string) ($p->brand ?? ''), 'serial_number' => '', 'technical_state' => '', 'notes' => '', 'suggested' => '1'])->all(),
            default => [],
        };
    }

    // ---------------------------------------------------------------- the papers

    private function beneficiary(int $projectId): ?object
    {
        $link = DB::table('project_applicants')->where('project_id', $projectId)->whereNull('deleted_at')->orderByRaw('CASE WHEN role = ? THEN 0 ELSE 1 END', [\App\Services\Applications\ApplicationService::BENEFICIARY])->orderBy('id')->first();

        return $link ? DB::table('people')->where('id', $link->person_id)->first() : null;
    }

    private function materials(int $projectId): Collection
    {
        return DB::table('project_materials')->where('project_id', $projectId)->whereNull('deleted_at')->whereNotNull('name')->where('name', '!=', '')->orderBy('id')->limit(self::MAX_ROWS)->get();
    }

    private function purchases(int $projectId): Collection
    {
        return DB::table('purchases')->where('project_id', $projectId)->whereNull('deleted_at')->orderBy('id')->limit(self::MAX_ROWS)->get();
    }

    private static function day(mixed $value): string
    {
        return $value ? str_replace('-', '/', substr((string) $value, 0, 10)) : '';
    }

    /** Stored rows when the paper was saved, otherwise the suggestions. @return array{0: ?object, 1: list<array<string, string>>} */
    private function rows(string $form, int $projectId): array
    {
        $spec = self::FORMS[$form];
        $record = $this->record($spec['table'], $projectId);

        return [$record, $record ? $this->itemRows($spec, (int) $record->id) : ($form === 'return' ? [] : $this->suggestions($form, $projectId))];
    }

    /** @return array<string, mixed> */
    private function request(int $projectId): array
    {
        [, $rows] = $this->rows('request', $projectId);

        return ['items' => array_map(fn ($r, $i) => ['no' => $i + 1, 'material' => $r['material'], 'kind' => $r['item_kind'], 'quantity' => $r['quantity'],
            'state' => $r['material_state'] !== '' ? __('paper_purchase.state_'.$r['material_state']) : ''], $rows, array_keys($rows))];
    }

    /** @return array<string, mixed> */
    private function quote(int $projectId): array
    {
        [$record, $rows] = $this->rows('quote', $projectId);
        $totals = [];
        $items = array_map(function ($r) use (&$totals) {
            $total = '';
            if ($r['unit_price'] !== '' && $r['quantity'] !== '') {
                $totals[] = $exact = bcmul($r['unit_price'], $r['quantity'], 4);
                $total = self::figure($exact);
            }

            return ['name' => $r['item_name'], 'kind' => '', 'quantity' => $r['quantity'], 'price' => $r['unit_price'] !== '' ? self::figure($r['unit_price']) : '', 'total' => $total, 'notes' => ''];
        }, $rows);

        // A grand total is printed only when every line has its total, so a partial sum is never read as the final one.
        return ['supplier' => (string) ($record->supplier_name ?? ''), 'quoted_on' => self::day($record->quoted_on ?? null), 'items' => $items,
            'grand_total' => $totals !== [] && count($totals) === count($rows) ? self::figure(Money::sum($totals)) : ''];
    }

    /** A number with thousands separators and the decimals it has (never rounded). */
    private static function figure(string $value): string
    {
        [$whole, $fraction] = array_pad(explode('.', Money::canonical($value) ?? $value, 2), 2, '');

        return preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $whole).($fraction !== '' ? '.'.$fraction : '');
    }

    /** @return array<string, mixed> */
    private function receipt(int $projectId): array
    {
        return [
            'items' => $this->purchases($projectId)->map(fn ($p) => ['name' => (string) $p->item_name, 'brand' => (string) ($p->brand ?? ''),
                'quantity' => Money::canonical($p->quantity) ?? '', 'trader' => (string) ($p->trader_name ?? '')])->all(),
            'received_on' => self::day($this->record('equipment_receipts', $projectId)->received_on ?? null),
        ];
    }

    /** @return array<string, mixed> */
    private function returned(int $projectId): array
    {
        [$record, $rows] = $this->rows('return', $projectId);

        return ['returned_on' => self::day($record->returned_on ?? null), 'count' => $rows === [] ? '' : (string) count($rows),
            'items' => array_map(fn ($r, $i) => ['no' => $i + 1, 'name' => $r['item_name'], 'brand' => $r['brand'], 'serial' => $r['serial_number'], 'state' => $r['technical_state'], 'notes' => $r['notes']], $rows, array_keys($rows))];
    }
}
