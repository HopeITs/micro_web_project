<?php

namespace App\Livewire;

use App\Services\Finance\FinanceService;
use App\Services\Finance\PaymentScheduleService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** The payment schedule page (M4.9, owner decision 4): count tiles that filter the list, the filters, totals per currency. */
class PaymentSchedule extends Component
{
    use WithPagination;

    #[Url(as: 'view')]
    public string $tab = 'due';

    /** @var list<int> */
    #[Url]
    public array $cities = [];

    #[Url]
    public string $year = '';

    #[Url]
    public string $month = '';

    #[Url]
    public string $group = '';

    #[Url]
    public string $currency = '';

    public function mount(): void
    {
        // The page visit is audited once (and refused without finance in any city); later round trips only filter.
        app(PaymentScheduleService::class)->opened(auth()->user());
    }

    public function updated(string $property): void
    {
        $this->resetPage();
    }

    public function show(string $view): void
    {
        $this->tab = in_array($view, PaymentScheduleService::VIEWS, true) ? $view : 'due';
        $this->resetPage();
    }

    public function toggleCity(int $city): void
    {
        $chosen = array_map('intval', $this->cities);
        $this->cities = in_array($city, $chosen, true) ? array_values(array_diff($chosen, [$city])) : [...$chosen, $city];
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(['tab', 'cities', 'year', 'month', 'group', 'currency']);
        $this->resetPage();
    }

    /** @return array<string, mixed> the filters, cleaned */
    public function filters(): array
    {
        $year = filter_var($this->year, FILTER_VALIDATE_INT, ['options' => ['min_range' => 2000, 'max_range' => 2100]]);
        $month = filter_var($this->month, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 12]]);
        $group = filter_var($this->group, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return [
            'view' => in_array($this->tab, PaymentScheduleService::VIEWS, true) ? $this->tab : 'due',
            'cities' => array_values(array_filter(array_map('intval', $this->cities))),
            'year' => $year === false ? null : $year, 'month' => $month === false ? null : $month, 'group' => $group === false ? null : $group,
            'currency' => in_array($this->currency, FinanceService::CURRENCIES, true) ? $this->currency : null,
        ];
    }

    public function render(PaymentScheduleService $schedule): View
    {
        $filters = $this->filters();

        return view('livewire.payment-schedule', $schedule->schedule(auth()->user(), $filters, audit: false) + ['filters' => array_filter($filters, fn ($v) => $v !== null && $v !== [])]);
    }
}
