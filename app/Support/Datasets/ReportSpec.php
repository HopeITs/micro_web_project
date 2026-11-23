<?php

namespace App\Support\Datasets;

use JsonException;

/**
 * One pivot report over a Dataset (smart-search plan 2026-11, phase 3), as plain data so it can be stored in a saved
 * view or come from a URL:
 *   {"rows": {"field": "state"}, "columns": {"field": "request_date", "grain": "year"},
 *    "measure": {"type": "sum", "field": "amount"}, "filters": [{"field": "city", "op": "in", "values": [1]}]}
 * validate() is as strict as QuerySpec::validate(): an unknown field or measure, a field that cannot be grouped or
 * measured, a grain on a non-date, or an invalid filter is refused, so a spec from a URL or a stored view can never
 * reach SQL unchecked. The grouping SQL comes only from the dataset's own field registry.
 */
final class ReportSpec
{
    public const MEASURES = ['count', 'distinct', 'sum', 'avg', 'min', 'max', 'share'];

    /** Measures that read a number or money field. */
    public const FIELD_MEASURES = ['sum', 'avg', 'min', 'max'];

    public readonly QuerySpec $filters;

    public function __construct(
        public readonly string $rows,
        public readonly ?string $rowGrain = null,
        public readonly ?string $columns = null,
        public readonly ?string $columnGrain = null,
        public readonly string $measure = 'count',
        public readonly ?string $measureField = null,
        ?QuerySpec $filters = null,
    ) {
        $this->filters = $filters ?? new QuerySpec;
    }

    /** @throws InvalidQuerySpec when the shape is wrong (the meaning is checked by validate()) */
    public static function fromArray(array $data): self
    {
        $dimension = function (string $name) use ($data): array {
            $value = $data[$name] ?? null;
            if ($value === null || $value === '') {
                return [null, null];
            }
            if (is_string($value)) {
                return [$value, null];
            }
            if (! is_array($value) || ! is_string($value['field'] ?? null) || ! (is_string($value['grain'] ?? null) || ($value['grain'] ?? null) === null)) {
                throw new InvalidQuerySpec(["{$name}: a field key (and optional grain) is needed"]);
            }

            return [$value['field'], ($value['grain'] ?? '') === '' ? null : $value['grain']];
        };
        [$rows, $rowGrain] = $dimension('rows');
        [$columns, $columnGrain] = $dimension('columns');
        $measure = $data['measure'] ?? 'count';
        if (is_string($measure)) {
            $measure = ['type' => $measure];
        }
        if (! is_array($measure) || ! is_string($measure['type'] ?? null) || ! (is_string($measure['field'] ?? null) || ($measure['field'] ?? null) === null)) {
            throw new InvalidQuerySpec(['measure: a type (and a field for sum, avg, min, max) is needed']);
        }
        $filters = $data['filters'] ?? [];
        if (! is_array($filters)) {
            throw new InvalidQuerySpec(['filters: a list of filters is needed']);
        }

        return new self($rows ?? '', $rowGrain, $columns, $columnGrain, $measure['type'], ($measure['field'] ?? '') === '' ? null : $measure['field'], QuerySpec::fromArray(['filters' => $filters]));
    }

    public static function fromJson(string $json): self
    {
        try {
            $data = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidQuerySpec(['spec: not valid JSON']);
        }

        return self::fromArray(is_array($data) ? $data : []);
    }

    public function toArray(): array
    {
        return [
            'rows' => array_filter(['field' => $this->rows, 'grain' => $this->rowGrain]),
            'columns' => $this->columns === null ? null : array_filter(['field' => $this->columns, 'grain' => $this->columnGrain]),
            'measure' => ['type' => $this->measure, 'field' => $this->measureField],
            'filters' => $this->filters->filters,
        ];
    }

    public function withFilters(QuerySpec $filters): self
    {
        return new self($this->rows, $this->rowGrain, $this->columns, $this->columnGrain, $this->measure, $this->measureField, $filters);
    }

    /** The grain a date dimension is grouped by ("year" when none was chosen); null for any other field. */
    public function grain(Dataset $dataset, string $dimension): ?string
    {
        $key = $dimension === 'rows' ? $this->rows : $this->columns;
        $grain = $dimension === 'rows' ? $this->rowGrain : $this->columnGrain;

        return $dataset->field((string) $key)?->type === 'date' ? ($grain ?? 'year') : null;
    }

    /** @return list<string> the fields the report reads (dataset-side names, never values), for the audit trail */
    public function fieldKeys(): array
    {
        $fields = [$this->rows];
        foreach ([$this->columns, $this->measureField] as $key) {
            if ($key !== null) {
                $fields[] = $key;
            }
        }
        foreach ($this->filters->filters as $filter) {
            $fields[] = $filter['field'];
        }

        return array_values(array_unique($fields));
    }

    /** @return list<string> every problem found; empty when the report is valid for the dataset */
    public function errors(Dataset $dataset): array
    {
        $errors = [];
        $groupable = $dataset->groupable();
        foreach (['rows' => [$this->rows, $this->rowGrain], 'columns' => [$this->columns, $this->columnGrain]] as $name => [$key, $grain]) {
            if ($key === null || $key === '') {
                if ($name === 'rows') {
                    $errors[] = 'rows: a field is needed';
                }

                continue;
            }
            $field = $groupable[$key] ?? null;
            if ($field === null) {
                $errors[] = "{$name}: field [{$key}] cannot be grouped";

                continue;
            }
            if ($grain !== null && ($field->type !== 'date' || ! in_array($grain, Dataset::GRAINS, true))) {
                $errors[] = "{$name}: grain [{$grain}] does not apply to {$field->type} field [{$key}]";
            }
        }
        if ($this->columns !== null && $this->columns === $this->rows) {
            $errors[] = 'columns: the same field as the rows';
        }

        if (! in_array($this->measure, self::MEASURES, true)) {
            $errors[] = "measure: unknown measure [{$this->measure}]";
        } elseif (in_array($this->measure, self::FIELD_MEASURES, true)) {
            $field = $this->measureField === null ? null : ($dataset->measurable()[$this->measureField] ?? null);
            if ($field === null) {
                $errors[] = 'measure: '.$this->measure.' needs a number or money field of this dataset'.($this->measureField !== null ? " (not [{$this->measureField}])" : '');
            }
        } elseif ($this->measureField !== null) {
            $errors[] = "measure: {$this->measure} takes no field";
        }

        foreach ($this->filters->errors($dataset) as $error) {
            $errors[] = $error;
        }

        return $errors;
    }

    /** @throws InvalidQuerySpec */
    public function validate(Dataset $dataset): self
    {
        $errors = $this->errors($dataset);
        if ($errors !== []) {
            throw new InvalidQuerySpec($errors);
        }

        return $this;
    }
}
