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

use App\Authorization\Permission;
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
    // The link of an invitation (task 10): the token is the proof.
    'invite',
    'invite/*',
    'two-factor-challenge',
    // The running version, without a session (task 7a, RoleBoot::routes).
    'api/v1/app/version',
    // Where a password manager finds the passkey pages (well-known URI).
    '.well-known/passkey-endpoints',
    // The link of the address confirmation mail; the token is the proof (task 8a, K9).
    'email/confirm',
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

/**
 * Routes outside the three auth groups (web, session-api, token-api), each
 * with its reason. Everything else belongs to exactly one group
 * (tests/Feature/Security/RouteInventoryTest.php).
 */
const UNGROUPED_ROUTES = [
    // The health check of the framework, for both roles; no session, no token.
    'up',
    // The running version, without a session on purpose (task 7a,
    // RoleBoot::routes): the checks of an unattended tab must not keep its
    // sign-in alive. It sends no cookie and reads none.
    'api/v1/app/version',
];

/**
 * Routes that change something but write no audit entry, keyed by URI
 * pattern, each with its reason. Every other mutating route carries
 * `->defaults('audited', true)` and writes an entry (Spec 12.10).
 */
const UNAUDITED_MUTATIONS = [
    // Signing in and out (Spec 12.10: "except the sign-in"); task 11 logs sign-ins.
    'login' => 'sign-in',
    'logout' => 'sign-out',
    'two-factor-challenge' => 'sign-in',
    'passkeys/login' => 'sign-in',
    // Proving who one is before a sensitive action changes nothing.
    'passkeys/confirm' => 'confirmation of the own identity',
    'user/confirm-password' => 'confirmation of the own identity',
    // Answers whether a channel may be joined; no change.
    'broadcasting/auth' => 'channel authorisation',
    // Route::redirect answers every method with a redirect.
    'settings' => 'redirect',
    // Shows who invites and until when; nothing is used up. The redemption
    // itself is logged by the sign-in method that does it (task 10, K18).
    'invite' => 'reads an invitation',
    // Preferences of the own interface, not an administrative action.
    'settings/language' => 'own preference',
    'api/v1/app/me/ui/collapsed' => 'own preference',
    // The second factors of the starter kit (Fortify). Task 12 rebuilds them
    // and logs every change; until then they are listed here
    // (todo in tests/Feature/Security/RouteInventoryTest.php).
    'user/passkeys' => 'second factor (task 12)',
    'user/passkeys/*' => 'second factor (task 12)',
    'user/two-factor-authentication' => 'second factor (task 12)',
    'user/confirmed-two-factor-authentication' => 'second factor (task 12)',
    'user/two-factor-recovery-codes' => 'second factor (task 12)',
];

/** @return list<string> the auth groups (web, session-api, token-api) of $route */
function authGroupsOf(Route $route): array
{
    return array_values(array_intersect(['web', 'session-api', 'token-api'], $route->gatherMiddleware()));
}

/** Changes something: POST, PUT, PATCH or DELETE. */
function isMutating(Route $route): bool
{
    return array_intersect(['POST', 'PUT', 'PATCH', 'DELETE'], $route->methods()) !== [];
}

/** Why $route writes no audit entry, or null when it must write one. */
function unauditedReason(Route $route): ?string
{
    foreach (UNAUDITED_MUTATIONS as $pattern => $reason) {
        if (fnmatch($pattern, $route->uri())) {
            return $reason;
        }
    }

    return null;
}

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
        'token' => (string) $nobody->createToken('route parameter', ['catalog.browse'])->accessToken->getKey(),
        default => (string) $nobody->id,
    }, $route->uri());

    $uri = '/'.ltrim((string) $uri, '/');

    // The token path signs in with a bearer token only: a token of the same
    // account that carries every permission, so the owner's missing
    // permission is what refuses.
    if (in_array('token-api', $route->gatherMiddleware(), true)) {
        $plain = $nobody->createToken('least privilege', Permission::values(Permission::cases()))->plainTextToken;

        return test()->withToken($plain)->json($method, $uri);
    }

    $request = test()->actingAs($nobody, 'web');
    // The session path needs the app's header (RequireAppHeader), or it
    // refuses before the permission is asked.
    if (in_array('session-api', $route->gatherMiddleware(), true)) {
        $request = $request->withHeader('X-Requested-With', 'tesserarr');
    }

    return $request->json($method, $uri);
}
