<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Models\User;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Tests\Support\Spies\AuthorizerSpy;

use function Tests\Support\callWithLeastPrivilege;
use function Tests\Support\isPublicRoute;
use function Tests\Support\isSelfServiceRoute;
use function Tests\Support\protectedRoutes;

/*
| Every route is public, self-service (#[SelfService], signed in only) or
| goes through the Authorizer (ADR-0016). A new route without a check fails
| here with its URI.
*/

it('routes every protected route through the Authorizer', function () {
    $spy = AuthorizerSpy::install();
    $routes = protectedRoutes();

    expect($routes)->not->toBeEmpty();

    foreach ($routes as $route) {
        $response = callWithLeastPrivilege($route);
        expect($response->status())->toBe(403, implode('|', $route->methods()).' '.$route->uri().' answered '.$response->status())
            ->and($response->json('message'))->toBe('owner_lacks_permission', $route->uri())
            ->and($spy->callsFor($route))->toBeGreaterThanOrEqual(1, $route->uri());
    }
});

it('declares the permission of every protected route with the can middleware', function () {
    $missing = array_values(array_map(
        fn (Route $route) => $route->uri(),
        array_filter(protectedRoutes(), fn (Route $route) => ! collect($route->gatherMiddleware())
            ->contains(fn ($m) => is_string($m) && str_starts_with($m, 'can:'))),
    ));

    expect($missing)->toBe([]);
});

it('requires a signed-in user on every self-service route', function () {
    $open = array_values(array_map(
        fn (Route $route) => $route->uri(),
        array_filter(
            RouteFacade::getRoutes()->getRoutes(),
            fn (Route $route) => isSelfServiceRoute($route) && ! isPublicRoute($route)
                && ! collect($route->gatherMiddleware())->contains(fn ($m) => is_string($m) && (str_starts_with($m, 'auth') || $m === 'guest'))
                && ! in_array($route->uri(), ['/', 'broadcasting/auth'], true),
        ),
    ));

    expect($open)->toBe([]);
});

it('fails for a protected route without a check', function () {
    RouteFacade::middleware('web')->get('/_unchecked', fn () => 'open');
    RouteFacade::getRoutes()->refreshNameLookups();

    $route = collect(protectedRoutes())->first(fn (Route $r) => $r->uri() === '_unchecked');

    expect($route)->not->toBeNull()->and(callWithLeastPrivilege($route)->status())->toBe(200);
});

it('lets an account authorise only its own broadcast channel', function () {
    // The null broadcaster skips the channel callbacks; the reverb driver runs them.
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'test-key',
        'broadcasting.connections.reverb.secret' => 'test-secret',
        'broadcasting.connections.reverb.app_id' => 'test-app',
        'broadcasting.connections.reverb.options.host' => 'localhost',
    ]);
    $owner = User::factory()->withRole('user')->create();
    $other = User::factory()->withRole('user')->create();
    $ask = fn (User $of) => ['socket_id' => '1234.5678', 'channel_name' => "private-App.Models.User.{$of->id}"];

    $this->post('/broadcasting/auth', $ask($owner))->assertForbidden();
    $this->actingAs($owner)->post('/broadcasting/auth', $ask($owner))->assertOk();
    $this->actingAs($owner)->post('/broadcasting/auth', $ask($other))->assertForbidden();
});
