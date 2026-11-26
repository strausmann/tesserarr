<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\OpenApi;

use Illuminate\Routing\Route;

/**
 * Selects the routes of the published OpenAPI document: the token API under
 * /api/v1, without the app API under /api/v1/app, which belongs to the web
 * interface (session, ADR-0007) and is never published.
 *
 * Paths are compared by segment, so /api/v10 or /api/v1app never count as
 * /api/v1. Scramble's default selection (`api_path`) would take every route
 * under /api, the app API included.
 */
final class PublicApiRouteResolver
{
    public function __invoke(Route $route): bool
    {
        $segments = explode('/', trim($route->uri(), '/'));

        if (array_slice($segments, 0, 2) !== ['api', 'v1']) {
            return false;
        }

        return ($segments[2] ?? null) !== 'app';
    }
}
