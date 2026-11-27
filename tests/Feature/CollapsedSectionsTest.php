<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Http\Ui\CollapsibleSection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

// `ui.collapsed`: which sections a user collapsed, kept on the server per
// user, so it holds on every device (task 7, Collapsible.svelte).

const COLLAPSED_URL = '/api/v1/app/me/ui/collapsed';

it('writes the value when a section is toggled', function () {
    $user = User::factory()->operator()->create();

    $this->actingAs($user)
        ->withHeader('X-Requested-With', 'tesserarr')->putJson(COLLAPSED_URL, ['section' => CollapsibleSection::DashboardAttention->value, 'collapsed' => true])
        ->assertNoContent();

    expect($user->refresh()->ui_collapsed)->toBe(['dashboard.attention' => true]);
});

it('shows the stored value after a reload and in a second session', function () {
    $user = User::factory()->operator()->create();
    $this->actingAs($user)
        ->withHeader('X-Requested-With', 'tesserarr')->putJson(COLLAPSED_URL, ['section' => 'dashboard.attention', 'collapsed' => false])
        ->assertNoContent();

    // A reload: the next request reads the user afresh, as the session guard
    // does (actingAs would otherwise keep the object from before the write).
    $this->actingAs($user->refresh())
        ->get('/dashboard')
        ->assertInertia(fn (Assert $page) => $page->where('ui.collapsed', ['dashboard.attention' => false]));

    // A second session: another device of the same user.
    auth()->forgetGuards();
    $this->flushSession();
    $this->actingAs(User::query()->findOrFail($user->id))
        ->get('/dashboard')
        ->assertInertia(fn (Assert $page) => $page->where('ui.collapsed', ['dashboard.attention' => false]));
});

it('keeps the other sections when one is toggled', function () {
    $user = User::factory()->operator()->create(['ui_collapsed' => ['dashboard.attention' => true, 'retired' => true]]);

    $this->actingAs($user)
        ->withHeader('X-Requested-With', 'tesserarr')->putJson(COLLAPSED_URL, ['section' => 'dashboard.attention', 'collapsed' => false])
        ->assertNoContent();

    expect($user->refresh()->ui_collapsed)->toEqual(['dashboard.attention' => false, 'retired' => true]);
});

it('rejects a section it does not know', function () {
    $user = User::factory()->operator()->create();

    $this->actingAs($user)
        ->withHeader('X-Requested-With', 'tesserarr')->putJson(COLLAPSED_URL, ['section' => 'no.such.section', 'collapsed' => true])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('section');

    expect($user->refresh()->ui_collapsed)->toBe([]);
});

it('rejects a value that is not a boolean', function () {
    $user = User::factory()->operator()->create();

    $this->actingAs($user)
        ->withHeader('X-Requested-With', 'tesserarr')->putJson(COLLAPSED_URL, ['section' => 'dashboard.attention', 'collapsed' => 'maybe'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('collapsed');
});

it('turns a guest away', function () {
    $this->putJson(COLLAPSED_URL, ['section' => 'dashboard.attention', 'collapsed' => true])
        ->assertUnauthorized();
});

it('hands only the known sections to the page', function () {
    $user = User::factory()->operator()->create(['ui_collapsed' => ['dashboard.attention' => true, 'retired' => true]]);

    $this->actingAs($user)->get('/dashboard')
        ->assertInertia(fn (Assert $page) => $page->where('ui.collapsed', ['dashboard.attention' => true]));
});
