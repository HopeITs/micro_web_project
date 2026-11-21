<?php

namespace App\Support\Datasets;

use Illuminate\Database\Eloquent\Builder;

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
}
