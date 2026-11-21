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
     * @param  array<string, string>  $with  extra values a cell needs, alias => SQL on the base query (selected only when the column is shown)
     * @param  (Closure(object, Field): array<string, mixed>)|null  $cell  overrides parts of the default cell (see cell())
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
        public readonly array $with = [],
        private readonly ?Closure $cell = null,
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

    // ---------------------------------------------------------------- columns (smart-search plan, phase 2)

    /** The alias the column's value is selected as (see Dataset::selectColumns()). */
    public function alias(): string
    {
        return 'col_'.$this->key;
    }

    /** Right-aligned cell (numbers, amounts, dates). */
    public function numeric(): bool
    {
        return in_array($this->type, ['number', 'money', 'date'], true);
    }

    /** An xlsx export writes the raw value as a number, unless a custom cell renders it (e.g. a project number "DAM-1001"). */
    public function exportsNumber(): bool
    {
        return in_array($this->type, ['number', 'money'], true) && $this->cell === null;
    }

    /**
     * How one row shows this column, from the value selected as alias() (plus the $with aliases):
     *   text    what the screen shows ('' when there is no value)
     *   export  what a file gets (raw number for number/money, otherwise the text)
     *   badge   draw as a status chip with tone / icon (x-hc.badge); href  a link around the text; ltr  left-to-right text
     *   class   an extra CSS class on the text (e.g. hc-pnum)
     *
     * @return array{text: string, export: string|null, badge: bool, tone: ?string, icon: ?string, href: ?string, ltr: bool, class: ?string}
     */
    public function cell(object $row): array
    {
        $value = $row->{$this->alias()} ?? null;
        $empty = $value === null || $value === '';
        $text = match (true) {
            $empty => '',
            $this->type === 'date' => $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : substr((string) $value, 0, 10),
            $this->type === 'money' => number_format((float) $value, fmod((float) $value, 1.0) === 0.0 ? 0 : 2),
            $this->type === 'bool' => $this->options()[(bool) $value ? '1' : '0'] ?? '',
            $this->hasOptions() => $this->options()[(string) $value] ?? (string) $value,
            default => (string) $value,
        };
        $export = $empty ? null : (in_array($this->type, ['number', 'money'], true) ? (string) ($value + 0) : $text);
        $cell = ['text' => $text, 'export' => $export, 'badge' => false, 'tone' => null, 'icon' => null, 'href' => null, 'ltr' => $this->numeric(), 'class' => null];
        if ($this->cell !== null) {
            $custom = ($this->cell)($row, $this);
            $cell = [...$cell, ...$custom];
            if (! array_key_exists('export', $custom)) {
                $cell['export'] = $cell['text'] === '' ? null : $cell['text'];
            }
        }

        return $cell;
    }
}
