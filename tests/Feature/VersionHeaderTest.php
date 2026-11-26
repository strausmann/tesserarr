<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

// The running version (TESSERARR_VERSION, config app.version) on every answer
// of the application, so an open tab built for another version notices it
// (resources/js/lib/updates.svelte.ts). Files are left alone: a browser or a
// proxy keeps a copy of them and would report an old version from it. The
// files of public/ never reach PHP (Caddy serves them, see e2e/update-notice
// .spec.ts); a file PHP sends itself is checked here.

const VERSION_HEADER = 'X-Tesserarr-Version';

beforeEach(function () {
    config(['app.version' => '1.2.3']);
});

it('sends X-Tesserarr-Version on api and inertia responses but not on static assets', function () {
    $user = User::factory()->create();
    // Routes owned by this test: one of the token API, one file.
    Route::middleware('token-api')->get('/api/v1/_probe', fn () => ['ok' => true]);
    Route::get('/_probe/file', fn () => response()->file(public_path('favicon.svg')));

    // An Inertia page, first visit and an Inertia visit.
    $this->get('/login')->assertOk()->assertHeader(VERSION_HEADER, '1.2.3');
    $this->actingAs($user)
        ->get('/dashboard', ['X-Inertia' => 'true', 'X-Inertia-Version' => '1.2.3'])
        ->assertOk()
        ->assertHeader('X-Inertia', 'true')
        ->assertHeader(VERSION_HEADER, '1.2.3');

    // The session API (/api/v1/app), answers and errors.
    $this->actingAs($user)->getJson('/api/v1/app/version')
        ->assertOk()
        ->assertExactJson(['version' => '1.2.3'])
        ->assertHeader(VERSION_HEADER, '1.2.3');
    $this->actingAs($user)->putJson('/api/v1/app/me/ui/collapsed', ['section' => 'nope'])
        ->assertUnprocessable()
        ->assertHeader(VERSION_HEADER, '1.2.3');

    // The token API (/api/v1), and a path below /api/ without a route.
    $this->getJson('/api/v1/_probe')->assertOk()->assertHeader(VERSION_HEADER, '1.2.3');
    $this->getJson('/api/v1/nothing-here')->assertNotFound()->assertHeader(VERSION_HEADER, '1.2.3');

    // A file sent by PHP carries no version.
    $this->get('/_probe/file')->assertOk()->assertHeaderMissing(VERSION_HEADER);
});

it('names the running version in the header of an outdated Inertia visit', function () {
    // The tab was built for 1.2.2: Inertia answers 409 with the location of
    // a full visit. A background request (router.reload) does not follow it;
    // the header tells the page that a new version runs.
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/dashboard', ['X-Inertia' => 'true', 'X-Inertia-Version' => '1.2.2'])
        ->assertStatus(409)
        ->assertHeader('X-Inertia-Version', '1.2.3')
        ->assertHeader(VERSION_HEADER, '1.2.3');
});

it('uses the running version as the Inertia asset version and page prop', function () {
    $this->get('/login')->assertInertia(fn (Assert $page) => $page
        ->where('version', '1.2.3'));

    expect($this->get('/login')->viewData('page')['version'])->toBe('1.2.3');
});

it('names the commit as well, so two builds of the rolling tag dev differ', function () {
    config(['app.version' => 'dev', 'app.revision' => '4f2a9c1']);
    $user = User::factory()->create();

    $this->get('/login')
        ->assertHeader(VERSION_HEADER, 'dev+4f2a9c1')
        ->assertInertia(fn (Assert $page) => $page->where('version', 'dev'));
    expect($this->get('/login')->viewData('page')['version'])->toBe('dev+4f2a9c1');
    $this->actingAs($user)->getJson('/api/v1/app/version')
        ->assertExactJson(['version' => 'dev'])
        ->assertHeader(VERSION_HEADER, 'dev+4f2a9c1');

    // A build without a known commit (a local or CI build) names the version alone.
    config(['app.revision' => 'unknown']);
    $this->get('/login')->assertHeader(VERSION_HEADER, 'dev');
});

it('answers the version check without a session, so it keeps no sign-in alive', function () {
    // An unattended tab asks every five minutes; were the route in the `web`
    // group, every check would renew the session and its cookie.
    $response = $this->getJson('/api/v1/app/version')
        ->assertOk()
        ->assertExactJson(['version' => '1.2.3'])
        ->assertHeader(VERSION_HEADER, '1.2.3')
        ->assertCookieMissing((string) config('session.cookie'))
        ->assertCookieMissing('XSRF-TOKEN');

    expect($response->headers->getCookies())->toBe([])
        ->and(app('router')->getRoutes()->getByName('app-api.version')?->gatherMiddleware())->toBe([]);
});
