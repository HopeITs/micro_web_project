<?php

declare(strict_types=1);

namespace App\Support\TableTools;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Arr;

/**
 * Header sorting of the server-paginated lists. The request only ever names a key; the SQL behind a key is written in the list's own code
 * (the whitelist), so nothing typed by a user reaches the query. An unknown key leaves the list in its default order. Empty values come
 * last in both directions; the list adds its default order afterwards as the tie-breaker.
 */
final class TableSort
{
    /**
     * @param  list<string>  $keys  sortable keys
     * @return array{0: ?string, 1: 'asc'|'desc'} the accepted key (or null) and direction
     */
    public static function clean(mixed $key, mixed $dir, array $keys): array
    {
        if (! is_string($key) || ! in_array($key, $keys, true)) {
            return [null, 'asc'];
        }

        return [$key, $dir === 'desc' ? 'desc' : 'asc'];
    }

    /**
     * What a click on a header does: default order → ascending → descending → default order.
     *
     * @return array{0: ?string, 1: 'asc'|'desc'}
     */
    public static function next(?string $current, ?string $dir, string $key): array
    {
        if ($current !== $key) {
            return [$key, 'asc'];
        }

        return $dir === 'desc' ? [null, 'asc'] : [$key, 'desc'];
    }

    /**
     * Orders the query by the asked column when it is whitelisted.
     *
     * @param  array<string, string|Builder|list<string|Builder>>  $columns  key => SQL expression(s) or sub-query(ies), all from code
     */
    public static function apply(Builder $query, array $columns, mixed $key, mixed $dir): bool
    {
        [$key, $dir] = self::clean($key, $dir, array_map('strval', array_keys($columns)));
        if ($key === null) {
            return false;
        }
        foreach (Arr::wrap($columns[$key]) as $term) {
            [$sql, $bindings] = $term instanceof Builder ? ['('.$term->toSql().')', $term->getBindings()] : [$term, []];
            $query->orderByRaw($sql.' is null', $bindings)->orderByRaw($sql.' '.$dir, $bindings);
        }

        return true;
    }
}
