<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Http\TrustedHosts;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;

/*
| Only the host of APP_URL, the loopback names and TESSERARR_TRUSTED_HOSTS
| are answered (task 8a): a forged Host header reaches no page and no link.
| Laravel skips TrustHosts in the testing environment, so the test switches
| the environment for its requests.
*/

beforeEach(function () {
    config(['app.url' => 'https://tesserarr.example.org', 'tesserarr.trusted_hosts' => ['second.example.org']]);
    $this->app['env'] = 'production';
});

afterEach(function () {
    $this->app['env'] = 'testing';
    // The trusted hosts are static on Symfony's Request.
    Request::setTrustedHosts([]);
});

it('answers the host of APP_URL, the loopback names and the configured hosts', function (string $host) {
    // A full URL: the test request takes its Host header from it.
    $this->get("http://{$host}/up")->assertOk();
})->with(['tesserarr.example.org', 'TESSERARR.example.org', 'localhost', '127.0.0.1', 'second.example.org']);

it('refuses a forged Host header', function (string $host) {
    $this->get("http://{$host}/login")->assertStatus(400);

    // Refused by the trusted hosts, not by something later.
    $this->withoutExceptionHandling();
    expect(fn () => $this->get("http://{$host}/login"))
        ->toThrow(SuspiciousOperationException::class, 'Untrusted Host');
})->with(['attacker.example.net', 'tesserarr.example.org.attacker.example.net', 'sub.tesserarr.example.org']);

it('lists the patterns anchored and escaped', function () {
    expect(TrustedHosts::patterns())->toBe([
        '^tesserarr\.example\.org$',
        '^localhost$',
        '^127\.0\.0\.1$',
        '^\[\:\:1\]$',
        '^second\.example\.org$',
    ]);
});
