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

    /** Where marker and route file are looked up; null is the application root. */
    private static ?string $root = null;

    /**
     * Points the lookup at another directory. For tests, so that they never
     * create the marker in a checkout; null restores the application root.
     */
    public static function useRoot(?string $root): void
    {
        self::$root = $root;
    }

    public static function enabled(): bool
    {
        return is_file(self::path(self::MARKER)) && is_file(self::path(self::ROUTES));
    }

    public static function registerRoutes(): void
    {
        if (self::enabled()) {
            Route::middleware('web')->group(self::path(self::ROUTES));
        }
    }

    private static function path(string $relative): string
    {
        return self::$root === null ? base_path($relative) : self::$root.'/'.$relative;
    }
}
