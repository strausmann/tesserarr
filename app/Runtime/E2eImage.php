<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Runtime;

use Illuminate\Support\Facades\Route;

/**
 * The image of the browser tests (stage `e2e` of docker/Dockerfile).
 *
 * Only that stage adds the sign-in route (routes/testing.php) and its
 * controller (app/Testing/), and creates the marker file. The vendor stage
 * deletes both files before the published image is assembled, and
 * .dockerignore keeps a marker out of the build context, so no environment
 * variable can switch the route on in a published image.
 */
final class E2eImage
{
    public const MARKER = '.e2e-image';

    public const ROUTES = 'routes/testing.php';

    public static function enabled(): bool
    {
        return is_file(base_path(self::MARKER)) && is_file(base_path(self::ROUTES));
    }

    public static function registerRoutes(): void
    {
        if (self::enabled()) {
            Route::middleware('web')->group(base_path(self::ROUTES));
        }
    }
}
