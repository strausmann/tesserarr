<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\RateLimiter;
use Tests\Support\EnvSnapshot;

// Octane empties every array cache store between requests, so a rate limiter
// on the array store counts nothing in worker mode and the brute-force limits
// of the sign-in never trigger (CONTRIBUTING.md, "Worker-safe code"). The rate
// limiter writes to the default cache store unless cache.limiter names one,
// so neither the shipped default nor the example environment may be "array".

beforeEach(function () {
    $this->env = new EnvSnapshot;
});

afterEach(function () {
    $this->env->restore();
});

/**
 * The driver of the store the rate limiter uses, from config/cache.php as it
 * reads the current environment.
 */
function limiterDriverFromConfig(): string
{
    /** @var array{default: string, limiter?: string|null, stores: array<string, array{driver: string}>} $cache */
    $cache = require base_path('config/cache.php');
    $store = $cache['limiter'] ?? $cache['default'];

    return $cache['stores'][$store]['driver'];
}

it('keeps the rate limiter off the array store when the environment sets no store', function () {
    $this->env->set(['CACHE_STORE' => null, 'CACHE_LIMITER' => null]);

    expect(limiterDriverFromConfig())->not->toBe('array');
});

it('keeps the rate limiter off the array store in .env.example', function () {
    preg_match('/^CACHE_STORE=(\S*)$/m', (string) file_get_contents(base_path('.env.example')), $match);

    expect($match[1] ?? null)->not->toBeNull()->not->toBe('array');
});

it('puts the rate limiter on the default cache store', function () {
    // The suite runs with CACHE_STORE=array (phpunit.xml): the booted limiter
    // follows it, which is why the two checks above guard the default.
    $this->env->set(['TESSERARR_ROLE' => 'central', 'CACHE_STORE' => 'array']);

    $this->refreshApplication();

    $store = (fn () => $this->cache)->call(app(RateLimiter::class))->getStore();

    expect($store)->toBeInstanceOf(ArrayStore::class);
});
