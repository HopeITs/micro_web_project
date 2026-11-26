<?php

namespace App\Livewire;

use App\Models\WebAccount;
use App\Services\Ask\AskChips;
use App\Services\Ask\AskExamples;
use App\Services\Ask\AskService;
use App\Services\Ask\ParseResult;
use App\Support\Datasets\DatasetRegistry;
use App\Support\Datasets\QuerySpec;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The "ask" box (X1): staff type a plain question in Arabic or English; the local parser (nothing leaves the server) turns it into
 * filter chips, never SQL. Contract other screens depend on: on submit it dispatches the Livewire event `ask-applied` with
 * `{dataset: string, spec: array}` (`spec` is QuerySpec's shape, plus an optional `search` of project numbers); with mode="report"
 * it adds `report: {rows: ?string, columns: ?string, measure: string}` ("count", or "sum:amount" / "avg:amount") when the question
 * says "by city", "per state", "total", "average". The chips shown here can be removed one by one; each removal dispatches again.
 */
class AskBox extends Component
{
    #[Locked]
    public string $dataset = '';

    #[Locked]
    public string $mode = '';

    public string $question = '';

    /** @var array{filters?: list<array>, sort?: list<array>, search?: string} */
    #[Locked]
    public array $spec = [];

    /** @var list<string> */
    #[Locked]
    public array $unknown = [];

    /** @var array{rows: ?string, columns: ?string, measure: string}|null */
    #[Locked]
    public ?array $report = null;

    #[Locked]
    public bool $asked = false;

    public function mount(string $dataset, string $mode = ''): void
    {
        $this->dataset = DatasetRegistry::find($dataset)?->key() ?? '';
        $this->mode = $mode === 'report' ? 'report' : '';
    }

    public function ask(AskService $service): void
    {
        $account = $this->account();
        $this->question = mb_substr(trim($this->question), 0, AskService::MAX_LENGTH);
        if ($account === null || $this->dataset === '' || $this->question === '') {
            return;
        }
        $result = $service->ask($account, $this->dataset, $this->question);
        if ($result === null) {
            return; // the account may not read this dataset: nothing is parsed, applied or stored
        }
        $this->take($result);
        $this->applied();
    }

    public function useExample(int $index, AskService $service): void
    {
        $account = $this->account();
        $examples = $account === null ? [] : $this->examples($account, $service);
        if (isset($examples[$index])) {
            $this->question = $examples[$index];
            $this->ask($service);
        }
    }

    public function removeChip(string $field): void
    {
        $filters = array_values(array_filter($this->spec['filters'] ?? [], fn ($f) => $f['field'] !== $field));
        $this->spec = [...$this->spec, 'filters' => $filters];
        if ($this->report !== null) {
            $this->report = null;
        }
        $this->applied();
    }

    #[On('ask-cleared')]
    public function cleared(string $dataset = ''): void
    {
        if ($dataset === $this->dataset) {
            $this->spec = [];
            $this->unknown = [];
            $this->report = null;
            $this->asked = false;
        }
    }

    private function take(ParseResult $result): void
    {
        $this->spec = $result->spec();
        $this->unknown = $result->unknown;
        $this->report = $this->mode === 'report' ? $result->report : null;
        $this->asked = true;
    }

    /** Tells the lists (and the report page) what was understood. The spec is checked once more here, so only valid filters ever leave. */
    private function applied(): void
    {
        $dataset = DatasetRegistry::find($this->dataset);
        if ($dataset === null) {
            return;
        }
        $spec = QuerySpec::fromArray($this->spec);
        if ($spec->errors($dataset) !== []) {
            $this->spec = [];

            return;
        }
        $payload = ['dataset' => $this->dataset, 'spec' => ['filters' => $spec->filters, 'sort' => [], ...(isset($this->spec['search']) ? ['search' => (string) $this->spec['search']] : [])]];
        if ($this->mode === 'report') {
            $payload['report'] = $this->report;
        }
        $this->dispatch('ask-applied', ...$payload);
    }

    private function account(): ?WebAccount
    {
        $account = auth()->user();

        return $account instanceof WebAccount ? $account : null;
    }

    /** @return list<string> */
    private function examples(WebAccount $account, AskService $service): array
    {
        return AskExamples::for($account, $this->dataset, $service->cities($account, $this->dataset)->all());
    }

    public function render(AskService $service): View
    {
        $account = $this->account();
        $allowed = $account !== null && $this->dataset !== '' && $service->allowed($account, $this->dataset);
        $dataset = DatasetRegistry::find($this->dataset);

        return view('livewire.ask-box', [
            'allowed' => $allowed,
            'examples' => $allowed ? $this->examples($account, $service) : [],
            'chips' => $allowed && $dataset !== null ? AskChips::for($dataset, $this->spec['filters'] ?? []) : [],
            'search' => (string) ($this->spec['search'] ?? ''),
        ]);
    }
}
