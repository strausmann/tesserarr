<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use Tests\Support\EnvSnapshot;

beforeEach(function () {
    $this->env = new EnvSnapshot;
});

afterEach(function () {
    $this->env->restore();
});

it('refuses to boot with an unknown role', function () {
    $this->env->set(['TESSERARR_ROLE' => 'hub']);

    $this->refreshApplication();
})->throws(InvalidArgumentException::class, 'TESSERARR_ROLE');

it('leaves cache, session and queue to the configuration in the central role', function () {
    $this->env->set([
        'TESSERARR_ROLE' => 'central',
        'CACHE_STORE' => 'database',
        'SESSION_DRIVER' => 'database',
        'QUEUE_CONNECTION' => 'database',
    ]);

    $this->refreshApplication();

    expect(config('cache.default'))->toBe('database')
        ->and(config('session.driver'))->toBe('database')
        ->and(config('queue.default'))->toBe('database');
});
