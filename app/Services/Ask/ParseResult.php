<?php

namespace App\Services\Ask;

/**
 * What the local parser understood of one question: the filters (every one checked by QuerySpec), an optional
 * free-text search for project numbers, the words it could not use, and (for the report mode) a pivot description.
 */
final class ParseResult
{
    /**
     * @param  list<array{field: string, op: string, values: list<mixed>}>  $filters
     * @param  list<string>  $unknown
     * @param  array{rows: ?string, columns: ?string, measure: string}|null  $report  measure is "count" or "sum:amount" / "avg:amount"
     */
    public function __construct(
        public readonly string $dataset,
        public readonly array $filters,
        public readonly string $search,
        public readonly array $unknown,
        public readonly ?array $report = null,
    ) {}

    /** The spec the lists listen for: QuerySpec's own shape plus the optional `search` (project numbers; never put in an address). */
    public function spec(): array
    {
        $spec = ['filters' => $this->filters, 'sort' => []];
        if ($this->search !== '') {
            $spec['search'] = $this->search;
        }

        return $spec;
    }

    public function isEmpty(): bool
    {
        return $this->filters === [] && $this->search === '' && $this->report === null;
    }

    public function toArray(): array
    {
        return ['dataset' => $this->dataset, 'spec' => $this->spec(), 'unknown' => $this->unknown, 'report' => $this->report];
    }
}
