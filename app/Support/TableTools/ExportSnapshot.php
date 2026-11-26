<?php

declare(strict_types=1);

namespace App\Support\TableTools;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Hands a Livewire list's filters to its export route when they hold free text (a name, a national number): they are kept on the server
 * for five minutes under a single-use token, so the text never appears in an address (the pattern of HasDatasetColumns). The export route
 * still checks every capability itself.
 */
final class ExportSnapshot
{
    /** The query parameter carrying the token. */
    public const PARAMETER = 'snapshot';

    public static function put(int $account, string $list, array $filters): string
    {
        $token = Str::random(40);
        Cache::put('table-export:'.$token, ['account' => $account, 'list' => $list, 'filters' => $filters], now()->addMinutes(5));

        return $token;
    }

    /**
     * The filters of the request's token, or null when the request carries none. A used, expired or foreign token is refused.
     *
     * @return array<string, mixed>|null
     */
    public static function pull(Request $request, string $list): ?array
    {
        if (! $request->query->has(self::PARAMETER)) {
            return null;
        }
        $token = $request->query(self::PARAMETER);
        abort_unless(is_string($token) && preg_match('/\A[A-Za-z0-9]{40}\z/', $token) === 1, 404);
        $held = Cache::pull('table-export:'.$token);
        abort_unless(is_array($held) && $held['list'] === $list && $held['account'] === (int) $request->user()->id, 404);

        return $held['filters'];
    }
}
