<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Runtime;

use App\Http\Controllers\Ui\VersionController;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Fortify;

/**
 * Applies the instance role before any other provider boots.
 *
 * A satellite overrides cache, session and queue itself instead of trusting
 * the environment: a forgotten CACHE_STORE=database must not make it look
 * for a database it does not have.
 */
final class RoleBoot
{
    public static function apply(Application $app, InstanceRole $role): void
    {
        $app->instance(InstanceRole::class, $role);

        // Static flag: reset it for the central role as well, so one booted
        // satellite does not hide the login routes of the next application
        // in the same process (tests).
        Fortify::$registersRoutes = $role === InstanceRole::Central;

        if ($role === InstanceRole::Satellite) {
            self::satellite($app);
        }
    }

    /**
     * Routes and broadcast channels per role. The health route /up is
     * registered by the framework for both roles; a satellite has no other
     * web route.
     */
    public static function routes(InstanceRole $role): void
    {
        if ($role !== InstanceRole::Central) {
            return;
        }

        $routes = dirname(__DIR__, 2).'/routes';

        Route::middleware('web')->group($routes.'/web.php');
        // The sign-in of the browser tests, in the e2e image only.
        E2eImage::registerRoutes();
        // The running version, asked by an open tab every five minutes
        // (lib/updates.svelte.ts). Outside the `web` group on purpose: no
        // session is read or written, so the checks of an unattended tab
        // never keep its sign-in alive past SESSION_LIFETIME, and no session
        // row is created for them. The version is no secret: every answer
        // names it in X-Tesserarr-Version.
        Route::get('api/v1/app/version', VersionController::class)->name('app-api.version');
        // The app API (session) before the token API, so /api/v1/app/* never
        // falls into a token route.
        Route::middleware('session-api')->prefix('api/v1/app')->name('app-api.')->group($routes.'/api_app.php');
        Route::middleware('token-api')->prefix('api/v1')->name('api.')->group($routes.'/api.php');

        Broadcast::routes();
        require $routes.'/channels.php';
    }

    private static function satellite(Application $app): void
    {
        $config = $app->make('config');

        // @phpstan-ignore disallowed.method (the role is fixed for the process: this runs once while the providers register, before the first request)
        $config->set([
            'cache.default' => 'array',
            'session.driver' => 'array',
            'queue.default' => 'sync',
            // The local disk would otherwise register a /storage/{path} route.
            'filesystems.disks.local.serve' => false,
        ]);

        $db = $app->make(DatabaseManager::class);

        foreach (array_keys($config->get('database.connections', [])) as $name) {
            $db->extend((string) $name, static fn () => throw new SatelliteHasNoDatabase((string) $name));
        }
    }
}
