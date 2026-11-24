<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Models\User;
use App\Runtime\E2eImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

// The browser tests sign in through /_testing/login/{role}. The route exists
// only in the e2e image: its stage adds routes/testing.php and app/Testing/
// and creates the marker file. The repository holds both files, so these
// tests switch the image on by creating the marker.

/** Creates the marker and registers the routes as the e2e image would at boot. */
function bootE2eImage(): void
{
    touch(base_path(E2eImage::MARKER));
    E2eImage::registerRoutes();
    Route::getRoutes()->refreshNameLookups();
}

afterEach(function () {
    @unlink(base_path(E2eImage::MARKER));
});

it('does not register /_testing/login without the marker of the e2e image', function () {
    E2eImage::registerRoutes();

    expect(Route::has('testing.login'))->toBeFalse();
    $this->get('/_testing/login/operator')->assertNotFound();
    $this->assertGuest();
    expect(User::query()->count())->toBe(0);
});

it('does not register /_testing/login when the route file is missing', function () {
    touch(base_path(E2eImage::MARKER));
    $routes = base_path(E2eImage::ROUTES);
    rename($routes, $routes.'.away');

    try {
        expect(E2eImage::enabled())->toBeFalse();
        E2eImage::registerRoutes();
        expect(Route::has('testing.login'))->toBeFalse();
    } finally {
        rename($routes.'.away', $routes);
    }
});

it('signs in the fixture account of the role and redirects to the dashboard', function (string $role) {
    bootE2eImage();

    $this->get("/_testing/login/{$role}")->assertRedirect(route('dashboard'));

    $user = User::query()->sole();
    expect($user->email)->toBe("e2e-{$role}@example.test");
    $this->assertAuthenticatedAs($user);
})->with(['friend', 'operator']);

it('answers 404 on /_testing/login for an unknown role', function () {
    bootE2eImage();

    $this->get('/_testing/login/root')->assertNotFound();
    $this->assertGuest();
});

it('answers 404 when the marker disappears after the route was registered', function () {
    bootE2eImage();
    unlink(base_path(E2eImage::MARKER));

    $this->get('/_testing/login/operator')->assertNotFound();
    $this->assertGuest();
    expect(User::query()->count())->toBe(0);
});

it('reuses the fixture account on a second sign-in', function () {
    bootE2eImage();

    $this->get('/_testing/login/friend');
    $this->get('/_testing/login/friend');

    expect(User::query()->count())->toBe(1);
});
