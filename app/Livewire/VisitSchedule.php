<?php

namespace App\Livewire;

use App\Livewire\Concerns\ExportsPrivately;
use App\Livewire\Concerns\HasTableSort;
use App\Services\Field\VisitScheduleService;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** The visit schedule page (M4.12): filters, header sorting, the shared visit notices, hide / restore, note and reschedule. */
class VisitSchedule extends Component
{
    use ExportsPrivately;
    use HasTableSort;
    use WithPagination;

    /** @var list<int> */
    #[Url]
    public array $cities = [];

    #[Url]
    public string $year = '';

    /** @var list<int> */
    #[Url]
    public array $months = [];

    #[Url]
    public string $kind = '';

    #[Url]
    public string $visit = '';

    #[Url]
    public string $street = '';

    #[Url]
    public string $type = '';

    #[Url]
    public bool $hidden = false;

    public string $q = '';

    public ?int $editing = null;

    public string $mode = '';

    public string $noteText = '';

    public string $newDate = '';

    public string $reason = '';

    public bool $shiftLater = false;

    public string $notice = '';

    public function updated(string $property): void
    {
        if (! in_array($property, ['noteText', 'newDate', 'reason', 'shiftLater'], true)) {
            $this->resetPage();
        }
    }

    public function toggleCity(int $city): void
    {
        $chosen = array_map('intval', $this->cities);
        $this->cities = in_array($city, $chosen, true) ? array_values(array_diff($chosen, [$city])) : [...$chosen, $city];
        $this->resetPage();
    }

    public function toggleMonth(int $month): void
    {
        $chosen = array_map('intval', $this->months);
        $this->months = in_array($month, $chosen, true) ? array_values(array_diff($chosen, [$month])) : [...$chosen, $month];
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(['cities', 'year', 'months', 'kind', 'visit', 'street', 'type', 'hidden', 'q']);
        $this->resetPage();
    }

    protected function sortableKeys(): array
    {
        return VisitScheduleService::SORT_KEYS;
    }

    protected function exportTarget(): array
    {
        return ['visit-schedule', 'visits.schedule.export'];
    }

    protected function exportFilters(): array
    {
        return array_filter($this->filters(), fn ($v) => $v !== null && $v !== [] && $v !== false);
    }

    /** @return array<string, mixed> */
    public function filters(): array
    {
        $int = fn (string $v, int $min, int $max) => ($i = filter_var($v, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]])) === false ? null : $i;

        return $this->sortFilters() + [
            'cities' => array_values(array_filter(array_map('intval', $this->cities))), 'year' => $int($this->year, 2000, 2100),
            'months' => array_values(array_filter(array_map('intval', $this->months), fn ($m) => $m >= 1 && $m <= 12)),
            'kind' => in_array($this->kind, ['V', 'O'], true) ? $this->kind : null, 'visit' => in_array($this->visit, VisitScheduleService::VISITS, true) ? $this->visit : null,
            'street' => $int($this->street, 1, PHP_INT_MAX), 'type' => $int($this->type, 1, PHP_INT_MAX), 'hidden' => $this->hidden, 'q' => mb_substr(trim($this->q), 0, 100) ?: null,
        ];
    }

    public function edit(int $id, string $mode, string $note = ''): void
    {
        $this->resetErrorBag();
        $this->editing = $id;
        $this->mode = in_array($mode, ['note', 'reschedule'], true) ? $mode : '';
        $this->noteText = $note;
        $this->newDate = '';
        $this->reason = '';
        $this->shiftLater = false;
    }

    public function cancel(): void
    {
        $this->editing = null;
        $this->mode = '';
    }

    private function run(callable $action, string $done, array $replace = []): void
    {
        $this->notice = '';
        $this->resetErrorBag();
        try {
            $result = $action(app(VisitScheduleService::class));
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->addError($field, $messages[0]);
            }

            return;
        }
        $this->editing = null;
        $this->mode = '';
        $this->notice = __('visits.schedule.'.$done, $replace + ['count' => is_int($result) ? $result : 1]);
    }

    public function setHidden(int $id, bool $hidden): void
    {
        $this->run(fn (VisitScheduleService $s) => $s->setSeen(auth()->user(), $id, $hidden), 'changed');
    }

    public function saveNote(): void
    {
        $this->validate(['noteText' => ['nullable', 'string', 'max:65535']]);
        $id = (int) $this->editing;
        $this->run(fn (VisitScheduleService $s) => $s->setNote(auth()->user(), $id, $this->noteText), 'noted');
    }

    public function saveReschedule(): void
    {
        $this->validate(['newDate' => ['nullable', 'string', 'max:10'], 'reason' => ['nullable', 'string', 'max:65535']]);
        $id = (int) $this->editing;
        $this->run(fn (VisitScheduleService $s) => $s->reschedule(auth()->user(), $id, $this->newDate, $this->reason, $this->shiftLater), 'rescheduled');
    }

    public function render(VisitScheduleService $schedule): View
    {
        $filters = $this->filters();

        // The export link carries the filters but never the search text (a name must not land in a URL or an access log); while a
        // search is typed the export goes through a server-side token instead (ExportsPrivately), so the file matches the screen.
        $link = array_filter(array_diff_key($filters, ['q' => true]), fn ($v) => $v !== null && $v !== [] && $v !== false);

        return view('livewire.visit-schedule', $schedule->schedule(auth()->user(), $filters) + ['filters' => $link, 'searching' => $filters['q'] !== null]);
    }
}
