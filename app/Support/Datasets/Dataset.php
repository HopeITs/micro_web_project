<?php

namespace App\Support\Datasets;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * A searchable entity described once (smart-search plan 2026-11): its base query, which always starts from a
 * city-scoped model so CityScope applies, its fields and its free-text search. Filters, facet counts, columns,
 * export and the AI assistant all read the same registry.
 */
abstract class Dataset
{
    /** @var array<string, Field>|null */
    private ?array $fields = null;

    abstract public function key(): string;

    /** The query every list, count and facet starts from. Must start from a city-scoped model. */
    abstract public function query(): Builder;

    /** @return list<Field> */
    abstract protected function define(): array;

    /** Free-text search on the base query (see SearchTerms for the OR / AND rules). */
    abstract public function search(Builder $query, string $text): Builder;

    /** @return array<string, Field> */
    public function fields(): array
    {
        if ($this->fields === null) {
            $this->fields = [];
            foreach ($this->define() as $field) {
                $this->fields[$field->key] = $field;
            }
        }

        return $this->fields;
    }

    public function field(string $key): ?Field
    {
        return $this->fields()[$key] ?? null;
    }

    /** @return array<string, Field> */
    public function filterable(): array
    {
        return array_filter($this->fields(), fn (Field $f) => $f->filterable);
    }

    // ---------------------------------------------------------------- columns, order and export (smart-search plan, phase 2)
    //
    // Shared API for lists that show a dataset as a table (projects now; people and payments next):
    //   - every `exportable` Field is a column; defaultColumns() is the list shown when the user chose nothing.
    //   - columnErrors()/validColumns() refuse unknown keys; cleanColumns() silently drops them (URL, stored views).
    //   - selectColumns() selects rowSelect() plus each chosen field as Field::alias() (and its `with` values);
    //     Field::cell($row) renders it (screen and file), so a table and its export can never disagree.
    //   - order() applies a validated QuerySpec sort, or defaultOrder() when there is none, plus a unique tie-breaker.
    //   - exportCapability() is held per city; the export covers only the cities where it is held (cityColumn()).
    // Livewire side: App\Livewire\Concerns\HasDatasetColumns; markup: <x-hc.column-picker>, <x-hc.data-table>.

    /** @return array<string, Field> */
    public function columns(): array
    {
        return array_filter($this->fields(), fn (Field $f) => $f->exportable);
    }

    /** @return list<string> the columns shown when the user chose none, in order */
    public function defaultColumns(): array
    {
        return array_slice(array_keys($this->columns()), 0, 8);
    }

    /** @return list<string> every problem with a requested column list; empty when it is valid */
    public function columnErrors(mixed $keys): array
    {
        if (! is_array($keys) || ! array_is_list($keys) || $keys === []) {
            return ['columns: a non-empty list of column keys is needed'];
        }
        $errors = [];
        $columns = $this->columns();
        foreach ($keys as $i => $key) {
            if (! is_string($key) || ! isset($columns[$key])) {
                $errors[] = "columns.{$i}: unknown column [".(is_scalar($key) ? $key : '?').']';
            }
        }
        if (count(array_unique(array_filter($keys, 'is_string'))) !== count($keys)) {
            $errors[] = 'columns: a column is listed twice';
        }

        return $errors;
    }

    /**
     * @return list<string>
     *
     * @throws InvalidQuerySpec
     */
    public function validColumns(mixed $keys): array
    {
        $errors = $this->columnErrors($keys);
        if ($errors !== []) {
            throw new InvalidQuerySpec($errors);
        }

        return $keys;
    }

    /** @return list<string> known columns of $keys in their order (unknown and repeated keys dropped); the defaults when none is left */
    public function cleanColumns(mixed $keys): array
    {
        $columns = $this->columns();
        $clean = [];
        foreach (is_array($keys) ? $keys : [] as $key) {
            if (is_string($key) && isset($columns[$key]) && ! in_array($key, $clean, true)) {
                $clean[] = $key;
            }
        }

        return $clean !== [] ? $clean : $this->defaultColumns();
    }

    /** Selected on every row whatever the columns (at least the row id as `id`). */
    protected function rowSelect(): array
    {
        $model = $this->query()->getModel();

        return [$model->getQualifiedKeyName().' as id'];
    }

    /** Replaces the query's select with rowSelect() and the given (already checked) columns. */
    public function selectColumns(Builder $query, array $keys): Builder
    {
        $select = $this->rowSelect();
        $with = [];
        foreach ($keys as $key) {
            $field = $this->field($key);
            if ($field === null || ! $field->exportable) {
                continue;
            }
            $select[] = DB::raw("{$field->sql} as {$field->alias()}");
            foreach ($field->with as $alias => $sql) {
                $with[$alias] = DB::raw("{$sql} as {$alias}");
            }
        }

        return $query->select([...$select, ...array_values($with)]);
    }

    /** The order when the user sorted nothing. */
    protected function defaultOrder(Builder $query): void {}

    /** Applies the spec's sort (validated) or the default order, then the row id so pages and chunks are stable. */
    public function order(Builder $query, QuerySpec $spec): Builder
    {
        $spec->validate($this);
        if ($spec->sort === []) {
            $this->defaultOrder($query);
        }
        foreach ($spec->sort as $item) {
            $query->orderBy($this->field($item['field'])->expression(), $item['dir']);
        }

        return $query->orderBy($query->getModel()->getQualifiedKeyName());
    }

    /** The per-city capability an export of this dataset needs (on top of reading it). */
    public function exportCapability(): string
    {
        return 'reports.export';
    }

    /** The city column of the base query's model, to narrow an export to the cities where it is allowed. */
    public function cityColumn(): string
    {
        return $this->query()->getModel()->qualifyColumn('city_id');
    }
}
