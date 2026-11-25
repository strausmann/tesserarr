<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Runtime\SatelliteHasNoDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Support\EnvSnapshot;

beforeEach(function () {
    // Settings a satellite must ignore: the role decides, not the environment.
    $this->env = (new EnvSnapshot)->set([
        'TESSERARR_ROLE' => 'satellite',
        'CACHE_STORE' => 'database',
        'SESSION_DRIVER' => 'database',
        'QUEUE_CONNECTION' => 'database',
    ]);
    $this->refreshApplication();
});

afterEach(function () {
    $this->env->restore();
});

it('forces array cache, array session and the sync queue', function () {
    expect(config('cache.default'))->toBe('array')
        ->and(config('session.driver'))->toBe('array')
        ->and(config('queue.default'))->toBe('sync');
});

it('throws SatelliteHasNoDatabase on any database connection', function (?string $name) {
    DB::connection($name)->select('select 1');
})->with([null, 'pgsql', 'sqlite'])->throws(SatelliteHasNoDatabase::class);

it('registers /up as its only web route', function () {
    $uris = collect(Route::getRoutes()->getRoutes())->map(fn ($route) => $route->uri())->values()->all();

    expect($uris)->toBe(['up']);
});

it('answers 200 on /up without a database', function () {
    $this->get('/up')->assertOk();
});

it('answers 404 on the central login page', function () {
    $this->get('/login')->assertNotFound();
});
