<?php

namespace App\Livewire;

use App\Services\AccountAccess;
use App\Services\Kobo\KoboReadService;
use App\Services\Kobo\KoboResultsService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** The Kobo page (M4.14, read and review only): submissions, results, review queues and visitor names. */
class KoboIndex extends Component
{
    use WithPagination;

    public const TABS = ['submissions', 'results', 'reviews', 'visitors'];

    #[Url(except: 'submissions')]
    public string $tab = 'submissions';

    /** @var list<int> */
    #[Url(as: 'city', except: [])]
    public array $cities = [];

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $type = '';

    #[Url(except: '')]
    public string $form = '';

    #[Url(except: '')]
    public string $from = '';

    #[Url(except: '')]
    public string $to = '';

    /** Project number or name search; a project number is not personal data, names stay out of the URL. */
    public string $search = '';

    #[Url(except: 'counts')]
    public string $mode = 'counts';

    #[Url(except: false)]
    public bool $last = false;

    public ?int $open = null;

    public function updated(string $property): void
    {
        $this->resetPage();
        if ($property !== 'open') {
            $this->open = null;
        }
    }

    public function setTab(string $tab): void
    {
        $this->tab = in_array($tab, self::TABS, true) ? $tab : 'submissions';
        $this->open = null;
        $this->resetPage();
    }

    public function toggleCity(int $id): void
    {
        $this->cities = in_array($id, $this->cities, true) ? array_values(array_diff($this->cities, [$id])) : [...$this->cities, $id];
        $this->resetPage();
    }

    public function show(int $id): void
    {
        $this->open = $this->open === $id ? null : $id;
    }

    public function clearFilters(): void
    {
        $this->reset('cities', 'status', 'type', 'form', 'from', 'to', 'search', 'last');
        $this->resetPage();
    }

    private function filters(): array
    {
        $date = fn (string $d) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? $d : null;

        return ['cities' => $this->cities, 'status' => $this->status, 'type' => $this->type, 'form' => $this->form, 'from' => $date($this->from), 'to' => $date($this->to),
            'q' => $this->search, 'last' => $this->last];
    }

    public function render(KoboReadService $kobo, KoboResultsService $results, AccountAccess $access): View
    {
        $account = auth()->user();
        $data = ['cityOptions' => $kobo->cities($account), 'forms' => $kobo->forms($account), 'counts' => $kobo->counts($account, $this->cities),
            'canExport' => $kobo->cities($account)->contains(fn ($c) => $access->check($account, (int) $c->id, 'reports.export'))];
        match ($this->tab) {
            'results' => $data['results'] = $this->mode === 'answers' ? $results->answers($account, $this->filters()) : $results->counts($account, $this->filters()),
            'reviews' => $data += ['conflicts' => $kobo->conflicts($account, $this->cities), 'reviews' => $kobo->reviews($account, $this->cities)],
            'visitors' => $data['visitors'] = $kobo->visitors($account, $this->cities),
            default => $data += ['submissions' => $kobo->submissions($account, $this->filters()), 'detail' => $this->open ? $kobo->submission($account, $this->open) : null],
        };

        return view('livewire.kobo-index', $data);
    }
}
