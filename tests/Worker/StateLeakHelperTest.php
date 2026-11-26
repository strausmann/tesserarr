<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use PHPUnit\Framework\AssertionFailedError;
use Tests\Support\Worker\UserRequest;
use Tests\Worker\Fixtures\CachingGreeter;
use Tests\Worker\Fixtures\GuestGreeting;
use Tests\Worker\Fixtures\StaticGreeting;

// Self-test of assertNoStateLeakBetweenUsers(): it must pass for code that
// reads the user per request and fail for the two classic leaks, a static
// cache and a singleton with a cache, each at the check that catches it.

afterEach(function () {
    StaticGreeting::forget();
    GuestGreeting::forget();
});

/**
 * Fixture routes on the worker's application, registered after boot like the
 * routes of the application itself.
 */
function greetingRoutes(Application $app): void
{
    // A fresh worker starts with empty statics; the PHP process of the test does not.
    StaticGreeting::forget();
    GuestGreeting::forget();

    $app->singleton('greeter.singleton', fn () => new CachingGreeter);
    $app->scoped('greeter.scoped', fn () => new CachingGreeter);
    // Resolved while the worker boots, as a provider or the octane.warm list would.
    $app->make('greeter.singleton');

    $router = $app->make('router');
    $router->get('/_worker/direct', fn (Request $request) => 'Hello '.($request->user()->name ?? 'guest'));
    $router->get('/_worker/static', fn (Request $request) => StaticGreeting::for($request->user()->name ?? null));
    $router->get('/_worker/singleton', fn (Request $request) => app('greeter.singleton')->greet($request->user()->name ?? null));
    $router->get('/_worker/scoped', fn (Request $request) => app('greeter.scoped')->greet($request->user()->name ?? null));
    $router->get('/_worker/nothing', fn () => 'Hello');
    $router->get('/_worker/guest-first', fn (Request $request) => GuestGreeting::first($request->user()->name ?? null));
    $router->get('/_worker/guest-last', fn (Request $request) => GuestGreeting::last($request->user()->name ?? null));
}

/**
 * @return Closure(): UserRequest
 */
function greetingAs(string $name, string $path): Closure
{
    return fn (): UserRequest => UserRequest::as(workerUser($name), Request::create($path), $name);
}

it('passes for a page that reads the user per request', function (string $path) {
    assertNoStateLeakBetweenUsers(greetingAs('Alice', $path), greetingAs('Bob', $path), greetingRoutes(...));
})->with(['/_worker/direct', '/_worker/scoped']);

it('fails for a static cache, at the second user', function () {
    assertNoStateLeakBetweenUsers(greetingAs('Alice', '/_worker/static'), greetingAs('Bob', '/_worker/static'), greetingRoutes(...));
})->throws(AssertionFailedError::class, 'The request as Bob shows data of Alice');

it('fails for a singleton that caches the first user, at the second user', function () {
    assertNoStateLeakBetweenUsers(greetingAs('Alice', '/_worker/singleton'), greetingAs('Bob', '/_worker/singleton'), greetingRoutes(...));
})->throws(AssertionFailedError::class, 'The request as Bob shows data of Alice');

it('fails when the request as A shows nothing of A, because then it proves nothing', function () {
    assertNoStateLeakBetweenUsers(greetingAs('Alice', '/_worker/nothing'), greetingAs('Bob', '/_worker/nothing'), greetingRoutes(...));
})->throws(AssertionFailedError::class, 'The request as Alice does not show Alice');

it('fails when only a guest after both users sees the first user', function () {
    assertNoStateLeakBetweenUsers(greetingAs('Alice', '/_worker/guest-first'), greetingAs('Bob', '/_worker/guest-first'), greetingRoutes(...));
})->throws(AssertionFailedError::class, 'The request as a guest shows data of Alice');

it('fails when only a guest after both users sees the second user', function () {
    assertNoStateLeakBetweenUsers(greetingAs('Alice', '/_worker/guest-last'), greetingAs('Bob', '/_worker/guest-last'), greetingRoutes(...));
})->throws(AssertionFailedError::class, 'The request as a guest shows data of Bob');
