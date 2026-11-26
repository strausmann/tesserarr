<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Models\User;
use App\Runtime\E2eImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

// The browser tests sign in through /_testing/login/{role}. The route exists
// only in the e2e image: its stage adds routes/testing.php and app/Testing/
// and creates the marker file. These tests build that layout in a temporary
// directory and point E2eImage at it, so they never touch the checkout: a
// marker left behind there would offer the sign-in to a local server.

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/tesserarr-e2e-image-'.bin2hex(random_bytes(6));
    File::ensureDirectoryExists($this->root.'/routes');
    File::copy(base_path(E2eImage::ROUTES), $this->root.'/'.E2eImage::ROUTES);
    E2eImage::useRoot($this->root);
});

afterEach(function () {
    E2eImage::useRoot(null);
    File::deleteDirectory($this->root);
});

/** Creates the marker and registers the routes as the e2e image would at boot. */
function bootE2eImage(string $root): void
{
    touch($root.'/'.E2eImage::MARKER);
    E2eImage::registerRoutes();
    Route::getRoutes()->refreshNameLookups();
}

it('does not register /_testing/login without the marker of the e2e image', function () {
    E2eImage::registerRoutes();

    expect(Route::has('testing.login'))->toBeFalse();
    $this->get('/_testing/login/operator')->assertNotFound();
    $this->assertGuest();
    expect(User::query()->count())->toBe(0);
});

it('does not register /_testing/login when the route file is missing', function () {
    touch($this->root.'/'.E2eImage::MARKER);
    unlink($this->root.'/'.E2eImage::ROUTES);

    expect(E2eImage::enabled())->toBeFalse();
    E2eImage::registerRoutes();
    expect(Route::has('testing.login'))->toBeFalse();
});

it('signs in the fixture account of the role with that role and redirects to its home', function (string $role, string $roleName, string $home) {
    bootE2eImage($this->root);

    $this->get("/_testing/login/{$role}")->assertRedirect(route($home));

    $user = User::query()->sole();
    expect($user->username)->toBe("e2e-{$role}")
        ->and($user->getRoleNames()->all())->toBe([$roleName]);
    $this->assertAuthenticatedAs($user);
})->with([
    'friend' => ['friend', 'user', 'friend.home'],
    'operator' => ['operator', 'operator', 'dashboard'],
]);

it('answers 404 on /_testing/login for an unknown role', function () {
    bootE2eImage($this->root);

    $this->get('/_testing/login/root')->assertNotFound();
    $this->assertGuest();
});

it('answers 404 when the marker disappears after the route was registered', function () {
    bootE2eImage($this->root);
    unlink($this->root.'/'.E2eImage::MARKER);

    $this->get('/_testing/login/operator')->assertNotFound();
    $this->assertGuest();
    expect(User::query()->count())->toBe(0);
});

it('reuses the fixture account on a second sign-in', function () {
    bootE2eImage($this->root);

    $this->get('/_testing/login/friend');
    $this->get('/_testing/login/friend');

    expect(User::query()->count())->toBe(1);
});

it('looks up the marker in the application root again once the override is cleared', function () {
    touch($this->root.'/'.E2eImage::MARKER);
    expect(E2eImage::enabled())->toBeTrue();

    E2eImage::useRoot(null);

    expect(E2eImage::enabled())->toBe(is_file(base_path(E2eImage::MARKER)));
});

// Last in the file: none of the tests above may leave a marker in the checkout
// or change its route file.
it('leaves no marker in the checkout and its route file in place', function () {
    expect(base_path(E2eImage::MARKER))->not->toBeFile()
        ->and(base_path(E2eImage::ROUTES))->toBeFile()
        ->and(base_path(E2eImage::ROUTES.'.away'))->not->toBeFile();
});
