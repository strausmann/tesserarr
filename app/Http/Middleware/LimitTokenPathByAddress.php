<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Providers\AppServiceProvider;
use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The per-address limit of the token path, before the token is looked up:
 * a flood of made-up bearers is cut off without a database query.
 *
 * Its own middleware and not `throttle:`, on purpose: Laravel sorts
 * ThrottleRequests behind the authentication middleware (middleware
 * priority), so a `throttle:` entry would run after Sanctum's lookup.
 */
final class LimitTokenPathByAddress
{
    public function __construct(private readonly RateLimiter $limiter) {}

    public function handle(Request $request, Closure $next): Response
    {
        $key = self::key((string) $request->ip());

        if ($this->limiter->tooManyAttempts($key, AppServiceProvider::TOKEN_API_PER_ADDRESS)) {
            return response()->json(['message' => 'too_many_requests'], 429, [
                'Retry-After' => (string) $this->limiter->availableIn($key),
            ]);
        }

        $this->limiter->hit($key, 60);

        return $next($request);
    }

    public static function key(string $address): string
    {
        return 'token-api-address:'.$address;
    }
}
