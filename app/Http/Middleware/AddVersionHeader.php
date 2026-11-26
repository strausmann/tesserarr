<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Names the running version (`TESSERARR_VERSION`, config `app.version`) in the
 * header `X-Tesserarr-Version` of every answer of the application: pages,
 * Inertia visits, the session API under /api/v1/app and the token API under
 * /api/v1, errors included. An open tab compares it with the version it was
 * built for and offers a reload (resources/js/lib/updates.svelte.ts).
 *
 * Global, so a route that is not in a group (a 404 below /api/) still names
 * it. Files are left out: a browser or a proxy keeps a copy of a file and
 * would report the version of that copy. The files of public/ are served by
 * Caddy and never reach PHP; a file PHP sends itself is skipped here.
 */
final class AddVersionHeader
{
    public const HEADER = 'X-Tesserarr-Version';

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $response instanceof BinaryFileResponse) {
            $response->headers->set(self::HEADER, self::running());
        }

        return $response;
    }

    /** The running version, read per request (worker-safe code). */
    public static function running(): string
    {
        return (string) config('app.version', '0.0.0-dev');
    }
}
