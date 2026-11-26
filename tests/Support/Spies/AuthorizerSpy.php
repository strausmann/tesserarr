<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Tests\Support\Spies;

use App\Authorization\AuthorizationDecision;
use App\Authorization\Authorizer;
use App\Authorization\Permission;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Routing\Route;
use Laravel\Sanctum\Contracts\HasAbilities;

/**
 * Counts the calls of the Authorizer per route and passes each one on to the
 * real Authorizer unchanged (a decorator, swapped into the container).
 *
 *     $spy = AuthorizerSpy::install();
 *     ... requests ...
 *     $spy->callsFor($route);
 */
final class AuthorizerSpy extends Authorizer
{
    /** @var list<array{route: string|null, permission: string}> */
    private array $calls = [];

    public function __construct(private readonly Authorizer $inner) {}

    public static function install(): self
    {
        $spy = new self(app(Authorizer::class));
        app()->instance(Authorizer::class, $spy);

        return $spy;
    }

    public function allows(Authenticatable $owner, ?HasAbilities $credential, Permission $permission): AuthorizationDecision
    {
        $route = request()->route();
        $this->calls[] = [
            'route' => $route instanceof Route ? self::key($route) : null,
            'permission' => $permission->value,
        ];

        return $this->inner->allows($owner, $credential, $permission);
    }

    public function callsFor(Route $route): int
    {
        $key = self::key($route);

        return count(array_filter($this->calls, fn (array $call) => $call['route'] === $key));
    }

    /** @return list<string> the permissions asked while handling $route */
    public function permissionsFor(Route $route): array
    {
        $key = self::key($route);

        return array_values(array_map(
            fn (array $call) => $call['permission'],
            array_filter($this->calls, fn (array $call) => $call['route'] === $key),
        ));
    }

    private static function key(Route $route): string
    {
        return implode('|', $route->methods()).' '.$route->uri();
    }
}
