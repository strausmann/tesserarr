<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The session path (/api/v1/app) answers only the app's own requests: they
 * carry `X-Requested-With: tesserarr`. A header a foreign page can only set
 * with a CORS preflight, which fails, so a cross-site form or image request
 * never reaches a session route even before the CSRF check (ADR-0007
 * point 4). Runs after the sign-in check: without a session the answer is
 * 401, with one but without the header 403 `missing_app_header`.
 */
final class RequireAppHeader
{
    public const HEADER = 'X-Requested-With';

    public const VALUE = 'tesserarr';

    public const MISSING = 'missing_app_header';

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->headers->get(self::HEADER) !== self::VALUE) {
            return response()->json(['message' => self::MISSING], 403);
        }

        return $next($request);
    }
}
