<?php

declare(strict_types=1);

namespace App\Support\TableTools;

/**
 * The search box of a plain GET page whose text may hold a name or a national number: it is posted (never part of an address, a history
 * entry or an access log) and kept in the signed-in account's session until cleared or signed out. The list page and its export read the
 * same value, so the file always matches the screen.
 */
final class PrivateSearch
{
    /** list key => the route of the page that shows it */
    public const LISTS = ['notifications' => 'notifications.index', 'meetings' => 'meetings.index'];

    public static function get(string $list): string
    {
        $value = session(self::key($list));

        return is_string($value) ? $value : '';
    }

    public static function put(string $list, string $term): void
    {
        $term = mb_substr(trim($term), 0, TableSearch::MAX_LENGTH);
        $term === '' ? session()->forget(self::key($list)) : session()->put(self::key($list), $term);
    }

    private static function key(string $list): string
    {
        return 'table_search.'.auth()->id().'.'.$list;
    }
}
