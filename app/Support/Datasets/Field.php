<?php

namespace App\Support\Datasets;

use Closure;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * One field of a dataset (smart-search plan 2026-11, "سجل حقول"): what it is called, its type, the SQL that
 * reads it from the dataset's base query and what the screens may do with it. Sensitive fields (names,
 * national numbers) are never grouped, counted or sent to the AI, and their values never go in a URL.
 */
final class Field
{
    public const TYPES = ['text', 'enum', 'lookup', 'number', 'money', 'date', 'bool'];

    /** Operators each type accepts. */
    public const OPS = [
        'text' => ['contains', 'in', 'not_in', 'empty', 'not_empty'],
        'enum' => ['in', 'not_in', 'empty', 'not_empty'],
        'lookup' => ['in', 'not_in', 'empty', 'not_empty'],
        'bool' => ['in', 'not_in', 'empty', 'not_empty'],
        'number' => ['in', 'not_in', 'between', 'gt', 'lt', 'empty', 'not_empty'],
        'money' => ['between', 'gt', 'lt', 'empty', 'not_empty'],
        'date' => ['between', 'gt', 'lt', 'empty', 'not_empty'],
    ];

    /** @var array<string, string>|null */
    private ?array $resolvedOptions = null;

    /**
     * @param  string  $label  lang key of the visible name (e.g. hc.state)
     * @param  string  $sql  column (alias.column) or SQL expression on the dataset's base query
     * @param  list<string>  $searchColumns  columns a "contains" filter looks in (defaults to $sql)
     * @param  (Closure(): array<string|int, string>)|null  $options  value => visible label, for enum/lookup/bool
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $type,
        public readonly string $sql,
        public readonly bool $filterable = true,
        public readonly bool $sortable = true,
        public readonly bool $groupable = false,
        public readonly bool $aggregatable = false,
        public readonly bool $exportable = true,
        public readonly bool $sensitive = false,
        private readonly ?Closure $options = null,
        public readonly array $searchColumns = [],
    ) {
        if (! in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException("Unknown field type [{$type}] for [{$key}].");
        }
        if (in_array($type, ['enum', 'lookup'], true) && $options === null) {
            throw new InvalidArgumentException("Field [{$key}] of type {$type} needs an options provider.");
        }
    }

    public function label(): string
    {
        return __($this->label);
    }

    /** @return list<string> */
    public function ops(): array
    {
        return self::OPS[$this->type];
    }

    public function hasOptions(): bool
    {
        return in_array($this->type, ['enum', 'lookup', 'bool'], true);
    }

    /** @return array<string, string> value (as string) => visible label */
    public function options(): array
    {
        if ($this->resolvedOptions === null) {
            $raw = $this->options !== null ? ($this->options)() : ($this->type === 'bool' ? ['1' => __('hc.yes'), '0' => __('hc.no')] : []);
            $this->resolvedOptions = [];
            foreach ($raw as $value => $label) {
                $this->resolvedOptions[(string) $value] = (string) $label;
            }
        }

        return $this->resolvedOptions;
    }

    /** The SQL to compare or group on: a plain qualified column stays a column, anything else is raw SQL. */
    public function expression(): string|Expression
    {
        return preg_match('/^[a-z_][a-z0-9_]*(\.[a-z_][a-z0-9_]*)?$/i', $this->sql) === 1 ? $this->sql : DB::raw($this->sql);
    }

    /** @return list<string> */
    public function columnsForContains(): array
    {
        return $this->searchColumns !== [] ? $this->searchColumns : [$this->sql];
    }
}
