<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Models\WebAccount;
use App\Services\AccountAccess;
use App\Services\AccountAuditTrail;
use App\Services\Lifecycle\LifecycleService;
use App\Services\Staff\ProjectNotices;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Purchased items of one project (M4.10, desktop Purchasing_Form / Classes\Purchasing) on the unified schema: `purchases`,
 * `purchasing_categories`, `projects`.
 *
 * Which projects: the desktop's project picker called `select_project_IDs("", "Yes")`, which lists only the projects whose current state is
 * Accepted, Financed, Finished, Canceled or Closed (desktop state ids 1, 4, 5, 7, 8) - the funded states, the same set as FinanceService::FUNDED.
 * Purchases are recorded for those projects only; any other project is refused with a clear message and shows nothing to edit.
 *
 * Reading needs `applications.view` and `purchasing.view` in the project's city (another city: 404), writing `purchasing.manage`
 * (administrator, data entry and legal: owner decision of 21 Nov 2026). Rows are saved together; a saved row is deleted after a confirmation
 * (soft delete, audited with details). Every write runs under the project row lock and carries the version of what the page showed.
 */
final class PurchasingService
{
    public const CURRENCIES = FinanceService::CURRENCIES;

    public const MAX_ROWS = 300;

    public function __construct(private AccountAccess $access, private AccountAuditTrail $audit, private LifecycleService $lifecycle, private ProjectNotices $projectNotices) {}

    // ---------------------------------------------------------------- access

    /** The project, readable with applications.view + purchasing.view in its city (another city: 404). */
    public function project(WebAccount $account, int $projectId): object
    {
        $project = $this->lifecycle->project($account, $projectId);
        if (! $this->access->check($account, (int) $project->city_id, 'purchasing.view')) {
            $this->audit->record($account->id, 'access.denied', 'denied', (int) $project->city_id);
            abort(403);
        }

        return $project;
    }

    public function canManage(WebAccount $account, int $city): bool
    {
        return $this->access->check($account, $city, 'purchasing.manage');
    }

    private function writable(WebAccount $account, int $projectId): object
    {
        $project = $this->project($account, $projectId);
        if (! $this->canManage($account, (int) $project->city_id)) {
            $this->audit->record($account->id, 'access.denied', 'denied', (int) $project->city_id);
            abort(403);
        }

        return $project;
    }

    /** The desktop's "Yes" filter: the project's current state is one of the funded states. */
    public function eligible(int $projectId): bool
    {
        $current = $this->lifecycle->currentState($projectId);

        return $current !== null && in_array($current->state_en, FinanceService::FUNDED, true);
    }

    // ---------------------------------------------------------------- reading

    public function opened(WebAccount $account, int $projectId): object
    {
        $project = $this->project($account, $projectId);
        $this->audit->record($account->id, 'purchasing.view', 'succeeded', (int) $project->city_id, subjectType: 'application_entity', subjectId: $projectId);

        return $project;
    }

    /** The project's purchases in entry order. */
    public function items(int $projectId): Collection
    {
        return DB::table('purchases')->where('project_id', $projectId)->whereNull('deleted_at')->orderBy('id')->get()->map(function ($row) {
            $row->price = Money::canonical($row->price) ?? '';
            $row->invoice_date = Fields::day($row->invoice_date);

            return $row;
        });
    }

    public function version(Collection $items): string
    {
        return hash('sha256', json_encode($items->map(fn ($r) => [$r->id, $r->item_name, $r->brand, $r->purchasing_category_id, $r->quantity, $r->price, $r->currency, $r->invoice_date, $r->invoice_number, $r->trader_name])->all(), JSON_THROW_ON_ERROR));
    }

    /** Active categories, plus the ones the project's own rows already use. */
    public function categories(Collection $items): Collection
    {
        return DB::table('purchasing_categories')->where(fn ($q) => $q->where('is_active', true)->orWhereIn('id', $items->pluck('purchasing_category_id')->filter()->all() ?: [0]))
            ->orderBy('sort_order')->orderBy('id')->get(['id', 'name_ar', 'name_en']);
    }

    /**
     * Quantity x price per currency, exact. Rows are `['quantity' => , 'price' => , 'currency' => ]` (stored rows or the page's grid).
     *
     * @param  iterable<array|object>  $rows
     * @return array<string, string> currency => total
     */
    public static function totals(iterable $rows): array
    {
        $totals = [];
        foreach ($rows as $row) {
            $row = (array) $row;
            $quantity = trim((string) ($row['quantity'] ?? ''));
            $price = Money::normalize((string) ($row['price'] ?? ''));
            $currency = (string) ($row['currency'] ?? '');
            if (preg_match('/\A\d{1,9}\z/', $quantity) !== 1 || ! Money::isDecimal($price, 2) || ! in_array($currency, self::CURRENCIES, true)) {
                continue;
            }
            $totals[$currency] = bcadd($totals[$currency] ?? '0', bcmul($quantity, $price, 2), 2);
        }

        return array_map(fn ($v) => Money::canonical($v) ?? '0', $totals);
    }

    /** Read model of the page. */
    public function panel(WebAccount $account, int $projectId): array
    {
        $project = $this->project($account, $projectId);
        $city = (int) $project->city_id;
        $items = $this->items($projectId);
        $loan = DB::table('loans')->where('project_id', $projectId)->whereNull('deleted_at')->orderBy('id')->first(['amount', 'currency', 'taken_on']);

        return [
            'project' => $project, 'items' => $items, 'version' => $this->version($items), 'canManage' => $this->canManage($account, $city), 'eligible' => $this->eligible($projectId),
            'categories' => $this->categories($items), 'beneficiary' => $this->projectNotices->beneficiary($project), 'loan' => $loan,
            'career' => $this->career($project), 'totals' => self::totals($items),
        ];
    }

    /** The project's profession (sub-category) and career, for the read-only header. */
    private function career(object $project): ?object
    {
        return DB::table('projects as p')->leftJoin('project_subcategories as s', 's.id', '=', 'p.project_subcategory_id')->leftJoin('careers as c', 'c.id', '=', 'p.career_id')
            ->where('p.id', $project->id)->first(['s.name_ar as profession_ar', 's.name_en as profession_en', 'c.name_ar as career_ar', 'c.name_en as career_en']);
    }

    // ---------------------------------------------------------------- writes

    private function fail(string $field, string $key): never
    {
        throw ValidationException::withMessages([$field => __('purchasing.errors.'.$key)]);
    }

    /** Locks the project, re-reads it and refuses a stale page or an ineligible project. Returns [project, items]. */
    private function begin(int $projectId, string $version): array
    {
        $project = DB::table('projects')->where('id', $projectId)->lockForUpdate()->first();
        $items = $this->items($projectId);
        hash_equals($this->version($items), $version) || $this->fail('version', 'stale');
        $this->eligible($projectId) || $this->fail('rows', 'ineligible');

        return [$project, $items];
    }

    /** "name · quantity x price currency" of a stored or submitted row, for the audit details. */
    private static function summary(array|object $row): string
    {
        $row = (array) $row;

        return trim(($row['item_name'] ?? '').' · '.($row['quantity'] ?? '').' × '.($row['price'] ?? '').' '.($row['currency'] ?? ''));
    }

    /**
     * One save for the whole grid. $rows: id?, item_name, brand, category_id?, quantity, price, currency, invoice_date?, invoice_number, trader_name.
     * Rows with an id update that purchase of this project; rows without one are added; a completely empty new row is skipped.
     */
    public function save(WebAccount $account, int $projectId, array $rows, string $version): void
    {
        $this->writable($account, $projectId);
        count($rows) <= self::MAX_ROWS || $this->fail('rows', 'too_many');
        DB::transaction(function () use ($account, $projectId, $rows, $version) {
            [$project, $items] = $this->begin($projectId, $version);
            $stored = $items->keyBy('id');
            $now = now();
            $details = [];
            $payloads = [];
            foreach (array_values($rows) as $index => $row) {
                $id = Fields::blank($row['id'] ?? null) ? null : (int) $row['id'];
                $item = $id === null ? null : $stored->get($id);
                $id !== null && $item === null && $this->fail('rows', 'row_missing');
                if ($id === null && $this->empty($row)) {
                    continue;
                }
                $payload = $this->payload($row, 'rows.'.$index.'.');
                if ($item !== null && AccountAuditTrail::changes($item, $payload) === null) {
                    continue;
                }
                $payloads[] = [$item, $payload];
            }
            foreach ($payloads as [$item, $payload]) {
                if ($item === null) {
                    DB::table('purchases')->insert($payload + ['city_id' => $project->city_id, 'project_id' => $projectId, 'created_at' => $now, 'updated_at' => $now]);
                    $details[] = ['field' => 'item_added', 'before' => null, 'after' => self::summary($payload)];
                } else {
                    DB::table('purchases')->where('id', $item->id)->update($payload + ['updated_at' => $now]);
                    foreach (AccountAuditTrail::changes($item, $payload) ?? [] as $change) {
                        $details[] = ['field' => 'item'.$item->id.'_'.$change['field']] + array_diff_key($change, ['field' => 1]);
                    }
                }
            }
            if ($details !== []) {
                $this->audit->record($account->id, 'purchasing.saved', 'succeeded', (int) $project->city_id, subjectType: 'application_entity', subjectId: $projectId, details: $details);
            }
        });
    }

    /** Soft-deletes one saved purchase (after the page's confirmation). Returns the new page version. */
    public function delete(WebAccount $account, int $projectId, int $itemId, string $version): string
    {
        $this->writable($account, $projectId);

        return DB::transaction(function () use ($account, $projectId, $itemId, $version) {
            [$project, $items] = $this->begin($projectId, $version);
            $item = $items->firstWhere('id', $itemId);
            $item === null && $this->fail('rows', 'row_missing');
            $now = now();
            DB::table('purchases')->where('id', $itemId)->update(['deleted_at' => $now, 'updated_at' => $now]);
            $this->audit->record($account->id, 'purchasing.deleted', 'succeeded', (int) $project->city_id, subjectType: 'application_entity', subjectId: $projectId,
                details: [['field' => 'item_deleted', 'before' => self::summary($item), 'after' => null]]);

            return $this->version($this->items($projectId));
        });
    }

    /** Whether a new grid row holds nothing the user typed (the defaults - quantity 1, currency - do not count). */
    private function empty(array $row): bool
    {
        if (! Fields::blank($row['quantity'] ?? null) && trim((string) $row['quantity']) !== '1') {
            return false;
        }
        foreach (['item_name', 'brand', 'price', 'invoice_date', 'invoice_number', 'trader_name'] as $key) {
            if (! Fields::blank($row[$key] ?? null)) {
                return false;
            }
        }

        return true;
    }

    /** Validated columns of one row. `$path` prefixes the error keys (rows.N.). */
    private function payload(array $row, string $path): array
    {
        $text = fn (string $key) => is_string($row[$key] ?? null) ? trim($row[$key]) : (is_numeric($row[$key] ?? null) ? (string) $row[$key] : '');
        $name = $text('item_name');
        ($name !== '' && mb_strlen($name) <= 255) || $this->fail($path.'item_name', 'item_name');
        mb_strlen($text('brand')) <= 255 || $this->fail($path.'brand', 'text');
        mb_strlen($text('trader_name')) <= 255 || $this->fail($path.'trader_name', 'text');
        mb_strlen($text('invoice_number')) <= 100 || $this->fail($path.'invoice_number', 'text');
        $quantity = $text('quantity') === '' ? '0' : Money::normalize($text('quantity'));
        (preg_match('/\A\d{1,9}\z/', $quantity) === 1) || $this->fail($path.'quantity', 'quantity');
        $price = $text('price') === '' ? '0' : Money::normalize($text('price'));
        (Money::isDecimal($price, 2) && strlen(explode('.', $price)[0]) <= 13) || $this->fail($path.'price', 'price');
        $currency = $text('currency');
        in_array($currency, self::CURRENCIES, true) || $this->fail($path.'currency', 'currency');
        $date = $text('invoice_date');
        ($date === '' || Fields::isDate($date)) || $this->fail($path.'invoice_date', 'date');
        $categoryId = $text('category_id') === '' ? null : (int) $text('category_id');
        if ($categoryId !== null && ! DB::table('purchasing_categories')->where('id', $categoryId)->exists()) {
            $this->fail($path.'category_id', 'category');
        }

        return [
            'item_name' => $name, 'brand' => $text('brand') === '' ? null : $text('brand'), 'purchasing_category_id' => $categoryId, 'quantity' => (int) $quantity,
            'price' => Money::canonical($price) ?? '0', 'currency' => $currency, 'invoice_date' => $date === '' ? null : $date,
            'invoice_number' => $text('invoice_number') === '' ? null : $text('invoice_number'), 'trader_name' => $text('trader_name') === '' ? null : $text('trader_name'),
        ];
    }
}
