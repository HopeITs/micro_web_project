<?php

namespace App\Livewire;

use App\Services\Finance\PurchasingService;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The purchases grid of a project (M4.10): add rows, edit, delete a saved row after a confirmation, one save for the whole grid, totals per
 * currency. Every write goes through PurchasingService, which rechecks the capabilities and the version of what the page showed.
 */
class ProjectPurchases extends Component
{
    #[Locked]
    public int $projectId;

    public string $version = '';

    /** @var list<array{id: ?int, item_name: string, brand: string, category_id: string, quantity: string, price: string, currency: string, invoice_date: string, invoice_number: string, trader_name: string}> */
    public array $rows = [];

    public string $notice = '';

    public function mount(int $projectId): void
    {
        $this->projectId = $projectId;
        $this->load();
    }

    private function service(): PurchasingService
    {
        return app(PurchasingService::class);
    }

    private function load(): void
    {
        $panel = $this->service()->panel(auth()->user(), $this->projectId);
        $this->version = $panel['version'];
        $this->rows = $panel['items']->map(fn ($r) => [
            'id' => (int) $r->id, 'item_name' => (string) $r->item_name, 'brand' => (string) ($r->brand ?? ''), 'category_id' => (string) ($r->purchasing_category_id ?? ''),
            'quantity' => (string) ($r->quantity ?? 0), 'price' => (string) $r->price, 'currency' => (string) ($r->currency ?? 'USD'), 'invoice_date' => (string) $r->invoice_date,
            'invoice_number' => (string) ($r->invoice_number ?? ''), 'trader_name' => (string) ($r->trader_name ?? ''),
        ])->values()->all();
    }

    public function addRow(): void
    {
        $this->rows[] = ['id' => null, 'item_name' => '', 'brand' => '', 'category_id' => '', 'quantity' => '1', 'price' => '', 'currency' => 'USD', 'invoice_date' => '', 'invoice_number' => '', 'trader_name' => ''];
    }

    /** Removes a row that is not saved yet (saved rows are deleted with deleteItem). */
    public function removeRow(int $index): void
    {
        if (isset($this->rows[$index]) && $this->rows[$index]['id'] === null) {
            unset($this->rows[$index]);
            $this->rows = array_values($this->rows);
        }
    }

    /** @param array<string, array<int, string>> $errors */
    private function errors(ValidationException $e): void
    {
        foreach ($e->errors() as $field => $messages) {
            $this->addError($field, $messages[0]);
        }
    }

    public function save(): void
    {
        $this->notice = '';
        $this->resetErrorBag();
        $this->validate([
            'rows' => ['array', 'max:'.PurchasingService::MAX_ROWS], 'rows.*.item_name' => ['nullable', 'string', 'max:255'], 'rows.*.brand' => ['nullable', 'string', 'max:255'],
            'rows.*.category_id' => ['nullable', 'string', 'max:20'], 'rows.*.quantity' => ['nullable', 'string', 'max:12'], 'rows.*.price' => ['nullable', 'string', 'max:40'],
            'rows.*.currency' => ['nullable', 'string', 'max:3'], 'rows.*.invoice_date' => ['nullable', 'string', 'max:10'], 'rows.*.invoice_number' => ['nullable', 'string', 'max:100'],
            'rows.*.trader_name' => ['nullable', 'string', 'max:255'],
        ]);
        try {
            $this->service()->save(auth()->user(), $this->projectId, $this->rows, $this->version);
        } catch (ValidationException $e) {
            $this->errors($e);

            return;
        }
        $this->load();
        $this->notice = __('purchasing.saved');
    }

    /** Soft-deletes a saved row (the page asked for confirmation); the other rows keep what was typed and not yet saved. */
    public function deleteItem(int $index): void
    {
        $this->notice = '';
        $this->resetErrorBag();
        $id = $this->rows[$index]['id'] ?? null;
        if ($id === null) {
            $this->removeRow($index);

            return;
        }
        try {
            $this->version = $this->service()->delete(auth()->user(), $this->projectId, (int) $id, $this->version);
        } catch (ValidationException $e) {
            $this->errors($e);

            return;
        }
        unset($this->rows[$index]);
        $this->rows = array_values($this->rows);
        $this->notice = __('purchasing.deleted');
    }

    public function render(PurchasingService $service): View
    {
        $panel = $service->panel(auth()->user(), $this->projectId);

        return view('livewire.project-purchases', ['panel' => $panel, 'totals' => PurchasingService::totals($this->rows)]);
    }
}
