<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Http\Middleware\RequireAppHeader;
use App\Models\User;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Router;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/*
| ADR-0007: the interface signs in with a session, other systems with a token,
| and each path has exactly one check. The token path (/api/v1) never reads
| the session cookie; the session path (/api/v1/app) never accepts a bearer
| token. Both directions are tested, and the middleware groups themselves.
*/

beforeEach(function () {
    $this->alice = User::factory()->withRole('user')->create(['username' => 'alice']);
});

it('rejects the token path for a user signed in through the web guard, without a bearer', function () {
    $login = $this->post('/login', ['login' => 'alice', 'password' => 'password'])->assertRedirect();
    $cookie = $login->getCookie(config('session.cookie'));
    expect($cookie)->not->toBeNull();

    // The test client keeps no cookies between requests (MakesHttpRequests):
    // the session cookie of the answer is sent on, and the user the guard
    // holds in memory is dropped, so only the cookie can sign the request in.
    auth()->forgetGuards();

    $this->withCookie(config('session.cookie'), $cookie->getValue())
        ->getJson('/api/v1/me')
        ->assertUnauthorized();
});

it('accepts the same session cookie on the session path', function () {
    // The positive control of the test above: the cookie is a working sign-in.
    $login = $this->post('/login', ['login' => 'alice', 'password' => 'password'])->assertRedirect();
    $cookie = $login->getCookie(config('session.cookie'));
    auth()->forgetGuards();

    $this->withCookie(config('session.cookie'), $cookie->getValue())
        ->withHeader('X-Requested-With', 'tesserarr')
        ->getJson('/api/v1/app/me')
        ->assertOk()
        ->assertJsonPath('username', 'alice');
});

it('rejects the session path with a valid bearer, the app header and no session', function () {
    $plain = $this->alice->createToken('script', ['catalog.browse'])->plainTextToken;

    $this->withToken($plain)->getJson('/api/v1/me')->assertOk();

    auth()->forgetGuards();

    $this->withToken($plain)
        ->withHeader('X-Requested-With', 'tesserarr')
        ->getJson('/api/v1/app/me')
        ->assertUnauthorized();
});

it('answers 403 missing_app_header on the session path with a session but without the header', function () {
    $this->actingAs($this->alice, 'web')
        ->getJson('/api/v1/app/me')
        ->assertForbidden()
        ->assertJsonPath('message', RequireAppHeader::MISSING);
});

it('answers 403 missing_app_header for another value of X-Requested-With', function () {
    // Inertia and many libraries send "XMLHttpRequest"; only the app's own
    // value counts, so a foreign page cannot reuse a common header.
    $this->actingAs($this->alice, 'web')
        ->withHeader('X-Requested-With', 'XMLHttpRequest')
        ->getJson('/api/v1/app/me')
        ->assertForbidden()
        ->assertJsonPath('message', RequireAppHeader::MISSING);
});

it('answers 401, not 403, on the session path without a session', function () {
    // The order of the group: the sign-in is checked before the header.
    $this->getJson('/api/v1/app/me')->assertUnauthorized();
});

it('returns no Set-Cookie from the token path and has no session middleware in token-api', function () {
    $plain = $this->alice->createToken('script', ['catalog.browse'])->plainTextToken;

    $response = $this->withToken($plain)->getJson('/api/v1/me')->assertOk();
    expect($response->headers->getCookies())->toBe([])
        ->and($response->headers->has('Set-Cookie'))->toBeFalse();

    $groups = app(Router::class)->getMiddlewareGroups();
    $stateful = [
        EncryptCookies::class,
        AddQueuedCookiesToResponse::class,
        StartSession::class,
        AuthenticateSession::class,
        ShareErrorsFromSession::class,
        ValidateCsrfToken::class,
        VerifyCsrfToken::class,
        'web',
        'auth:web',
    ];

    expect($groups)->toHaveKey('token-api')
        ->and(array_values(array_intersect($groups['token-api'], $stateful)))->toBe([])
        ->and($groups['token-api'])->toContain('auth:sanctum');
});

it('builds the session group in the order of ADR-0007', function () {
    $group = app(Router::class)->getMiddlewareGroups()['session-api'];
    $position = fn (string $middleware) => array_search($middleware, $group, true);

    foreach ([EncryptCookies::class, StartSession::class, 'auth:web', RequireAppHeader::class, ValidateCsrfToken::class] as $middleware) {
        expect($position($middleware))->not->toBeFalse($middleware);
    }

    expect($position(EncryptCookies::class))->toBeLessThan($position(StartSession::class))
        ->and($position(StartSession::class))->toBeLessThan($position('auth:web'))
        ->and($position('auth:web'))->toBeLessThan($position(RequireAppHeader::class))
        ->and($position(RequireAppHeader::class))->toBeLessThan($position(ValidateCsrfToken::class))
        ->and($group)->not->toContain('auth:sanctum');
});

it('signs a locked account out on the session path', function () {
    $this->actingAs($this->alice, 'web');
    $this->alice->forceFill(['locked_at' => now()])->save();

    $this->withHeader('X-Requested-With', 'tesserarr')
        ->getJson('/api/v1/app/me')
        ->assertUnauthorized();
});

it('keeps a route of the token path out of the session', function () {
    // A probe that would answer with the session user if the group read one.
    Route::middleware('token-api')->prefix('api/v1')->get('_probe-session', fn () => response()->json([
        'web' => auth('web')->id(),
    ]));
    Route::getRoutes()->refreshNameLookups();

    $this->actingAs($this->alice, 'web')->getJson('/api/v1/_probe-session')->assertUnauthorized();
});
