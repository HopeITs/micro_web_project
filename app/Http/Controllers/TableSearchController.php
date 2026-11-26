<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\TableTools\PrivateSearch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Receives the search box of a plain list page (PrivateSearch): keeps the text in the session and goes back to the list with its other
 * filters and its sort, on the first page. The page itself checks every permission; this stores only the account's own search text.
 */
final class TableSearchController
{
    public function __invoke(Request $request, string $locale, string $list): RedirectResponse
    {
        abort_unless(isset(PrivateSearch::LISTS[$list]), 404);
        $data = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'clear' => ['nullable', 'in:1'], 'back' => ['nullable', 'string', 'max:2000']]);
        PrivateSearch::put($list, isset($data['clear']) ? '' : (string) ($data['q'] ?? ''));
        parse_str((string) ($data['back'] ?? ''), $query);
        unset($query['q'], $query['page'], $query['locale']);

        return redirect()->route(PrivateSearch::LISTS[$list], ['locale' => $locale] + $query);
    }
}
