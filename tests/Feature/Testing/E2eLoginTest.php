<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// The browser tests sign in through /_testing/login/{role}. It exists only in
// the e2e image: APP_ENV=testing and TESSERARR_E2E_LOGIN=true, both set by the
// `e2e` stage of docker/Dockerfile. Everywhere else it answers 404.

beforeEach(function () {
    $this->env = app()->environment();
});

afterEach(function () {
    app()['env'] = $this->env;
});

it('answers 404 on /_testing/login outside testing', function () {
    config(['tesserarr.e2e_login' => true]);
    app()['env'] = 'production';

    $this->get('/_testing/login/operator')->assertNotFound();

    $this->assertGuest();
    expect(User::query()->count())->toBe(0);
});

it('answers 404 on /_testing/login without the e2e image flag', function () {
    config(['tesserarr.e2e_login' => false]);

    $this->get('/_testing/login/operator')->assertNotFound();

    $this->assertGuest();
    expect(User::query()->count())->toBe(0);
});

it('answers 404 on /_testing/login for an unknown role', function () {
    config(['tesserarr.e2e_login' => true]);

    $this->get('/_testing/login/root')->assertNotFound();

    $this->assertGuest();
});

it('signs in the fixture account of the role and redirects to the dashboard', function (string $role) {
    config(['tesserarr.e2e_login' => true]);

    $this->get("/_testing/login/{$role}")->assertRedirect(route('dashboard'));

    $user = User::query()->sole();
    expect($user->email)->toBe("e2e-{$role}@example.test");
    $this->assertAuthenticatedAs($user);
})->with(['friend', 'operator']);

it('reuses the fixture account on a second sign-in', function () {
    config(['tesserarr.e2e_login' => true]);

    $this->get('/_testing/login/friend');
    $this->get('/_testing/login/friend');

    expect(User::query()->count())->toBe(1);
});
