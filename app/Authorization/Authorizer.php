<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Authorization;

use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Routing\Route;
use Laravel\Sanctum\Contracts\HasAbilities;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * The single permission check of Tesserarr (ADR-0016). Every gate of the
 * catalogue calls it (AppServiceProvider), and nothing else decides a
 * permission.
 *
 * Allowed only when
 *   1. the owner holds the permission now: a permission subject that is not
 *      blocked and whose roles carry it, read from the database on every call;
 *   2. and, with a token, the token belongs to that owner and carries the
 *      permission as an ability.
 *
 * Without a credential the request counts as a session only on a route of
 * the `web` group (pages and /api/v1/app) whose `web` user is the owner. On a
 * `token-api` route, outside any route (console, queue) or for another owner,
 * a missing credential is `token_missing`: with `sanctum.guard = []` Sanctum
 * never turns a session into a transient token, so null is all a token route
 * sees without a token (plan, Nachprüfung N5).
 *
 * A container service (bound in AppServiceProvider) so a test can decorate
 * it (tests/Support/Spies/AuthorizerSpy.php). It keeps no state: the request
 * and the guard are read from the container on every call.
 */
class Authorizer
{
    public function __construct(
        private readonly Container $container,
        private readonly AuthFactory $auth,
    ) {}

    public function allows(Authenticatable $owner, ?HasAbilities $credential, Permission $permission): AuthorizationDecision
    {
        if (! $owner instanceof PermissionSubject) {
            return AuthorizationDecision::deny(AuthorizationDecision::OWNER_LACKS_PERMISSION);
        }

        if ($owner->isBlocked()) {
            return AuthorizationDecision::deny($owner instanceof User ? AuthorizationDecision::LOCKED : AuthorizationDecision::REVOKED);
        }

        if (! in_array($permission, $owner->grantedPermissions(), true)) {
            return AuthorizationDecision::deny(AuthorizationDecision::OWNER_LACKS_PERMISSION);
        }

        if ($credential === null) {
            return $this->isSessionOf($owner)
                ? AuthorizationDecision::allow()
                : AuthorizationDecision::deny(AuthorizationDecision::TOKEN_MISSING);
        }

        if (! $this->belongsTo($credential, $owner)) {
            return AuthorizationDecision::deny(AuthorizationDecision::TOKEN_MISSING);
        }

        return $credential->can($permission->value)
            ? AuthorizationDecision::allow()
            : AuthorizationDecision::deny(AuthorizationDecision::TOKEN_LACKS_PERMISSION);
    }

    /** Signed in through `web` as $owner, on a route of the `web` group. */
    private function isSessionOf(Authenticatable $owner): bool
    {
        $route = $this->container->make('request')->route();

        if (! $route instanceof Route) {
            return false;
        }

        $middleware = $route->gatherMiddleware();
        if (in_array('token-api', $middleware, true) || ! in_array('web', $middleware, true)) {
            return false;
        }

        $sessionUser = $this->auth->guard('web')->user();

        return $sessionUser !== null
            && $sessionUser::class === $owner::class
            && $sessionUser->getAuthIdentifier() === $owner->getAuthIdentifier();
    }

    /** A token issued to $owner (the token of another account never counts). */
    private function belongsTo(HasAbilities $credential, Authenticatable $owner): bool
    {
        if (! $credential instanceof PersonalAccessToken) {
            return false;
        }

        $type = $owner instanceof Model ? $owner->getMorphClass() : $owner::class;

        return $credential->getAttribute('tokenable_type') === $type
            && (string) $credential->getAttribute('tokenable_id') === (string) $owner->getAuthIdentifier();
    }
}
