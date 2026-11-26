<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

// The language of the interface: the profile first, then the browser
// (Accept-Language), then English (App\Http\Middleware\SetLocale).

it('picks de from Accept-Language de-CH', function () {
    $this->get('/login', ['Accept-Language' => 'de-CH,de;q=0.9,en;q=0.8'])
        ->assertInertia(fn (Assert $page) => $page
            ->where('locale', 'de')
            ->where('translations', fn ($t) => $t['nav.home'] === 'Startseite'));
});

it('falls back to en for a language it does not have', function () {
    $this->get('/login', ['Accept-Language' => 'fr-FR,fr;q=0.9'])
        ->assertInertia(fn (Assert $page) => $page
            ->where('locale', 'en')
            ->where('translations', fn ($t) => $t['nav.home'] === 'Home'));
});

it('falls back to en without Accept-Language', function () {
    $this->get('/login', ['Accept-Language' => ''])
        ->assertInertia(fn (Assert $page) => $page->where('locale', 'en'));
});

it('prefers the language of the profile over the browser', function () {
    $user = User::factory()->create(['locale' => 'de']);

    $this->actingAs($user)
        ->get('/dashboard', ['Accept-Language' => 'en-US'])
        ->assertInertia(fn (Assert $page) => $page->where('locale', 'de'));
});

it('follows the browser when the profile names no language', function () {
    $user = User::factory()->create(['locale' => null]);

    $this->actingAs($user)
        ->get('/dashboard', ['Accept-Language' => 'de'])
        ->assertInertia(fn (Assert $page) => $page->where('locale', 'de'));
});

it('sets the lang attribute of the page', function () {
    $this->get('/login', ['Accept-Language' => 'de'])
        ->assertSee('<html lang="de"', false);
});

it('saves the language of the profile and clears it again', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from('/settings/appearance')
        ->patch('/settings/language', ['locale' => 'de'])
        ->assertRedirect('/settings/appearance');
    expect($user->refresh()->locale)->toBe('de');

    $this->actingAs($user)->patch('/settings/language', ['locale' => null]);
    expect($user->refresh()->locale)->toBeNull();
});

it('rejects a language it does not have', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch('/settings/language', ['locale' => 'fr'])
        ->assertSessionHasErrors('locale');
    expect($user->refresh()->locale)->toBeNull();
});
