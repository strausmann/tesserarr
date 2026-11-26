<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/*
| Helpers for the tests that count routes against the Authorizer
| (tests/Feature/Security/AuthorizerCallersTest.php) and for tokens in tests.
| Loaded by tests/Pest.php.
*/

namespace Tests\Support;

use App\Authorization\Roles;
use App\Http\SelfService;
use App\Models\User;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;
use ReflectionClass;
use ReflectionMethod;
use Spatie\Permission\Models\Role;

/**
 * Routes anybody may call without signing in. A fixed list: a new route that
 * is neither here, nor self-service, nor behind the Authorizer fails the
 * caller test.
 */
const PUBLIC_ROUTES = [
    'up',
    'login',
    'passkeys/login*',
    'auth/*',
    'invite/*',
    'two-factor-challenge',
    // The running version, without a session (task 7a, RoleBoot::routes).
    'api/v1/app/version',
    // Where a password manager finds the passkey pages (well-known URI).
    '.well-known/passkey-endpoints',
];

/**
 * Self-service routes of the framework packages, whose controllers cannot
 * carry the #[SelfService] attribute: they need a signed-in user, no
 * permission (pre-flight K9: /logout, /user/*, /confirm-password).
 */
const VENDOR_SELF_SERVICE_ROUTES = [
    'logout',
    'user/*',
    // Fortify: confirming the own passkey before a sensitive action.
    'passkeys/confirm*',
    // The redirect to the own profile (Route::redirect, no controller of ours).
    'settings',
    // Channel authorisation: the route needs a session, and the one channel
    // (routes/channels.php) is the account's own. A channel that needs a
    // permission asks the Authorizer.
    'broadcasting/auth',
];

/** An API token of $owner (an operator by default) that carries $abilities. */
function tokenWith(array $abilities, ?User $owner = null): PersonalAccessToken
{
    $owner ??= User::factory()->operator()->create();

    return $owner->createToken('test', $abilities)->accessToken;
}

/** @return list<Route> every route except the public ones and the self-service ones */
function protectedRoutes(): array
{
    return array_values(array_filter(
        RouteFacade::getRoutes()->getRoutes(),
        fn (Route $route) => ! isPublicRoute($route) && ! isSelfServiceRoute($route),
    ));
}

function isPublicRoute(Route $route): bool
{
    return matchesAny($route, PUBLIC_ROUTES);
}

function isSelfServiceRoute(Route $route): bool
{
    if (matchesAny($route, VENDOR_SELF_SERVICE_ROUTES)) {
        return true;
    }

    $controller = $route->getAction('controller');
    if (! is_string($controller)) {
        return false;
    }

    [$class, $method] = str_contains($controller, '@') ? explode('@', $controller, 2) : [$controller, '__invoke'];
    if (! class_exists($class)) {
        return false;
    }

    if ((new ReflectionClass($class))->getAttributes(SelfService::class) !== []) {
        return true;
    }

    return method_exists($class, $method) && (new ReflectionMethod($class, $method))->getAttributes(SelfService::class) !== [];
}

/** @param list<string> $patterns */
function matchesAny(Route $route, array $patterns): bool
{
    foreach ($patterns as $pattern) {
        if (fnmatch($pattern, $route->uri())) {
            return true;
        }
    }

    return false;
}

/**
 * Calls $route as the user with the least privilege there is: signed in,
 * not locked, without any role. Route parameters get the id of an existing
 * row where the binding needs one, otherwise a placeholder.
 */
function callWithLeastPrivilege(Route $route): TestResponse
{
    $nobody = User::factory()->create(['username' => 'nobody-'.bin2hex(random_bytes(6))]);
    $method = collect($route->methods())->first(fn (string $m) => $m !== 'HEAD') ?? 'GET';

    $uri = preg_replace_callback('/\{(\w+)\??\}/', fn (array $m) => match ($m[1]) {
        'role' => (string) Role::findByName(Roles::USER, 'web')->id,
        default => (string) $nobody->id,
    }, $route->uri());

    return test()->actingAs($nobody, 'web')->json($method, '/'.ltrim((string) $uri, '/'));
}
