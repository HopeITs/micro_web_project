<?php

namespace App\Support\Datasets;

use App\Support\ArabicSearch;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;
use JsonException;

/**
 * One description of "which rows, in which order" (smart-search plan 2026-11):
 *   {"filters": [{"field": "state", "op": "in", "values": [3, 5]}], "sort": [{"field": "request_date", "dir": "desc"}]}
 * validate() checks it strictly against a Dataset (unknown fields, operators, values or types are refused), so a
 * spec from a URL or the AI assistant can never reach SQL unchecked. apply() only adds conditions to a query that
 * already started from the dataset's city-scoped base, so it can narrow the permitted cities but never widen them.
 */
final class QuerySpec
{
    public const OPS = ['in', 'not_in', 'contains', 'empty', 'not_empty', 'between', 'gt', 'lt'];

    public const DATE_PRESETS = ['this_month', 'last_90_days', 'this_year'];

    public const MAX_VALUES = 200;

    /**
     * @param  list<array{field: string, op: string, values: list<mixed>}>  $filters
     * @param  list<array{field: string, dir: string}>  $sort
     */
    public function __construct(public readonly array $filters = [], public readonly array $sort = []) {}

    /** Builds a spec from decoded JSON / request input; the shape is checked, the meaning is checked by validate(). */
    public static function fromArray(array $data): self
    {
        $filters = [];
        foreach ((array) ($data['filters'] ?? []) as $filter) {
            if (! is_array($filter)) {
                throw new InvalidQuerySpec(['filters: every filter must be an object']);
            }
            $values = $filter['values'] ?? [];
            $filters[] = [
                'field' => is_string($filter['field'] ?? null) ? $filter['field'] : '',
                'op' => is_string($filter['op'] ?? null) ? $filter['op'] : '',
                'values' => is_array($values) ? array_values($values) : [$values],
            ];
        }
        $sort = [];
        foreach ((array) ($data['sort'] ?? []) as $item) {
            if (! is_array($item)) {
                throw new InvalidQuerySpec(['sort: every item must be an object']);
            }
            $sort[] = ['field' => is_string($item['field'] ?? null) ? $item['field'] : '', 'dir' => is_string($item['dir'] ?? null) ? strtolower($item['dir']) : 'asc'];
        }

        return new self($filters, $sort);
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
        return ['filters' => $this->filters, 'sort' => $this->sort];
    }

    public function with(array $filter): self
    {
        return new self([...$this->filters, $filter], $this->sort);
    }

    public function without(string $field): self
    {
        return new self(array_values(array_filter($this->filters, fn ($f) => $f['field'] !== $field)), $this->sort);
    }

    /** @return list<string> every problem found; empty when the spec is valid for the dataset */
    public function errors(Dataset $dataset): array
    {
        $errors = [];
        foreach ($this->filters as $i => $filter) {
            foreach (self::filterErrors($dataset, $filter) as $error) {
                $errors[] = "filters.{$i}: {$error}";
            }
        }
        foreach ($this->sort as $i => $item) {
            $field = $dataset->field($item['field']);
            if ($field === null) {
                $errors[] = "sort.{$i}: unknown field [{$item['field']}]";
            } elseif (! $field->sortable) {
                $errors[] = "sort.{$i}: field [{$field->key}] is not sortable";
            }
            if (! in_array($item['dir'], ['asc', 'desc'], true)) {
                $errors[] = "sort.{$i}: direction must be asc or desc";
            }
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

    /** @return list<string> */
    public static function filterErrors(Dataset $dataset, array $filter): array
    {
        $field = $dataset->field((string) ($filter['field'] ?? ''));
        if ($field === null) {
            return ['unknown field ['.($filter['field'] ?? '').']'];
        }
        if (! $field->filterable) {
            return ["field [{$field->key}] is not filterable"];
        }
        $op = (string) ($filter['op'] ?? '');
        if (! in_array($op, self::OPS, true)) {
            return ["unknown operator [{$op}]"];
        }
        if (! in_array($op, $field->ops(), true)) {
            return ["operator [{$op}] does not apply to {$field->type} field [{$field->key}]"];
        }
        $values = $filter['values'] ?? [];
        if (! is_array($values) || ! array_is_list($values)) {
            return ['values must be a list'];
        }
        if (count($values) > self::MAX_VALUES) {
            return ['too many values'];
        }
        foreach ($values as $value) {
            if (! is_scalar($value) && $value !== null) {
                return ['values must be plain text or numbers'];
            }
        }

        return match ($op) {
            'empty', 'not_empty' => $values === [] ? [] : ["operator [{$op}] takes no values"],
            'in', 'not_in' => self::listErrors($field, $values),
            'contains' => $values !== [] && collect($values)->every(fn ($v) => is_string($v) && trim($v) !== '') ? [] : ['contains needs at least one word'],
            'gt', 'lt' => count($values) === 1 && self::scalarOk($field, $values[0]) ? [] : ["operator [{$op}] needs one {$field->type} value"],
            'between' => self::betweenErrors($field, $values),
        };
    }

    private static function listErrors(Field $field, array $values): array
    {
        if ($values === []) {
            return ['at least one value is needed'];
        }
        if ($field->hasOptions()) {
            $allowed = $field->options();
            foreach ($values as $value) {
                if (! array_key_exists((string) $value, $allowed)) {
                    return ["value [{$value}] is not an option of [{$field->key}]"];
                }
            }

            return [];
        }
        foreach ($values as $value) {
            if (! self::scalarOk($field, $value)) {
                return ["value [{$value}] is not a {$field->type}"];
            }
        }

        return [];
    }

    private static function betweenErrors(Field $field, array $values): array
    {
        if ($field->type === 'date' && count($values) === 1 && in_array($values[0], self::DATE_PRESETS, true)) {
            return [];
        }
        if (count($values) !== 2) {
            return ['between needs two values (from, to) or a date preset'];
        }
        $set = 0;
        foreach ($values as $value) {
            if ($value === null || $value === '') {
                continue;
            }
            if (! self::scalarOk($field, $value)) {
                return ["value [{$value}] is not a {$field->type}"];
            }
            $set++;
        }

        return $set > 0 ? [] : ['between needs at least one bound'];
    }

    private static function scalarOk(Field $field, mixed $value): bool
    {
        return match ($field->type) {
            'number', 'money' => is_int($value) || is_float($value) || (is_string($value) && is_numeric($value)),
            'date' => is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 && checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4)),
            'text' => is_string($value) || is_int($value),
            default => is_string($value) || is_int($value),
        };
    }

    /** @return array{0: string, 1: string} first and last day of a relative date preset */
    public static function presetRange(string $preset, ?CarbonImmutable $today = null): array
    {
        $today = ($today ?? CarbonImmutable::today())->startOfDay();

        return match ($preset) {
            'this_month' => [$today->startOfMonth()->toDateString(), $today->endOfMonth()->toDateString()],
            'last_90_days' => [$today->subDays(89)->toDateString(), $today->toDateString()],
            'this_year' => [$today->startOfYear()->toDateString(), $today->endOfYear()->toDateString()],
            default => throw new InvalidArgumentException("Unknown date preset [{$preset}]."),
        };
    }

    /**
     * Adds the filters and the sort to a query built from $dataset->query(). Validates first, so calling
     * apply() with an unchecked spec is safe.
     *
     * @param  list<string>  $except  fields whose filters are skipped (facet counts of that field)
     */
    public function apply(Builder $query, Dataset $dataset, array $except = [], bool $withSort = true): Builder
    {
        $this->validate($dataset);
        foreach ($this->filters as $filter) {
            if (in_array($filter['field'], $except, true)) {
                continue;
            }
            self::applyFilter($query, $dataset->field($filter['field']), $filter['op'], $filter['values']);
        }
        if ($withSort) {
            foreach ($this->sort as $item) {
                $query->orderBy($dataset->field($item['field'])->expression(), $item['dir']);
            }
        }

        return $query;
    }

    private static function applyFilter(Builder $query, Field $field, string $op, array $values): void
    {
        $column = $field->expression();
        $isText = $field->type === 'text';
        $values = array_map(fn ($v) => in_array($field->type, ['number', 'money'], true) && $v !== null && $v !== '' ? $v + 0 : $v, $values);

        match ($op) {
            'in' => $query->whereIn($column, $values),
            'not_in' => $query->where(fn (Builder $q) => $q->whereNotIn($column, $values)->orWhereNull($column)),
            'empty' => $query->where(fn (Builder $q) => $isText ? $q->whereNull($column)->orWhere($column, '') : $q->whereNull($column)),
            'not_empty' => $query->where(fn (Builder $q) => $isText ? $q->whereNotNull($column)->where($column, '<>', '') : $q->whereNotNull($column)),
            'contains' => $query->where(function (Builder $any) use ($field, $values) {
                foreach ($values as $value) {
                    $any->orWhere(function (Builder $all) use ($field, $value) {
                        foreach (SearchTerms::words((string) $value) as $word) {
                            $all->where(function (Builder $q) use ($field, $word) {
                                foreach ($field->columnsForContains() as $c) {
                                    ArabicSearch::orWhereLike($q, $c, $word);
                                }
                            });
                        }
                    });
                }
            }),
            'gt' => self::compare($query, $field, '>', $values[0]),
            'lt' => self::compare($query, $field, '<', $values[0]),
            'between' => self::between($query, $field, $values),
        };
    }

    private static function compare(Builder $query, Field $field, string $operator, mixed $value): void
    {
        $field->type === 'date' ? $query->whereDate($field->sql, $operator, $value) : $query->where($field->expression(), $operator, $value);
    }

    private static function between(Builder $query, Field $field, array $values): void
    {
        if (count($values) === 1) {
            $values = self::presetRange((string) $values[0]);
        }
        [$from, $to] = $values;
        if ($from !== null && $from !== '') {
            $field->type === 'date' ? $query->whereDate($field->sql, '>=', $from) : $query->where($field->expression(), '>=', $from);
        }
        if ($to !== null && $to !== '') {
            $field->type === 'date' ? $query->whereDate($field->sql, '<=', $to) : $query->where($field->expression(), '<=', $to);
        }
    }
}
