<?php

namespace App\Http\Middleware;

use App\Models\WebAccount;
use App\Services\AccountAccess;
use App\Support\CityContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Every city model read in this request is limited to the cities where the account may view projects. */
final class RestrictToPermittedCities
{
    public function __construct(private AccountAccess $access, private CityContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $account = $request->user();
        $this->context->restrictTo($account instanceof WebAccount ? $this->access->cities($account)->pluck('id') : []);

        return $next($request);
    }
}
