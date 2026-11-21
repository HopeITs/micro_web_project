<?php

namespace App\Livewire;

use App\Services\Field\VisitorStatisticsService;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Visitor statistics (M4.12, owner decision 7: administrator only). */
class VisitorStatistics extends Component
{
    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    #[Url]
    public string $type = '';

    /** @var list<int> */
    #[Url]
    public array $cities = [];

    public function mount(): void
    {
        $defaults = VisitorStatisticsService::defaults();
        $this->from = $this->from ?: $defaults['from'];
        $this->to = $this->to ?: $defaults['to'];
    }

    public function toggleCity(int $city): void
    {
        $chosen = array_map('intval', $this->cities);
        $this->cities = in_array($city, $chosen, true) ? array_values(array_diff($chosen, [$city])) : [...$chosen, $city];
    }

    public function render(VisitorStatisticsService $statistics): View
    {
        $this->resetErrorBag();
        $input = ['from' => $this->from, 'to' => $this->to, 'type' => $this->type, 'cities' => $this->cities];
        try {
            $filters = VisitorStatisticsService::clean($input);
        } catch (ValidationException $e) {
            $this->addError('from', $e->errors()['from'][0]);
            $filters = VisitorStatisticsService::clean(['type' => $this->type, 'cities' => $this->cities]);
            $filters['invalid'] = true;
        }
        $report = $statistics->report(auth()->user(), $filters);
        if ($filters['invalid'] ?? false) {
            $report['rows'] = collect();
        }

        return view('livewire.visitor-statistics', $report + ['filters' => $filters, 'query' => array_filter(['from' => $this->from, 'to' => $this->to, 'type' => $this->type, 'cities' => $this->cities])]);
    }
}
