<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Audit\Actor;
use App\Settings\SettingsRegistry;

// The appearance cookie picks the theme of the root view. HandleAppearance
// keeps the value on the request, not in View::share(), which would outlive
// the request in worker mode (CONTRIBUTING.md, "Worker-safe code").

it('renders the dark theme when the appearance cookie asks for it', function () {
    $this->withUnencryptedCookie('appearance', 'dark')
        ->get('/login')
        ->assertOk()
        ->assertSee('<html lang="en" data-theme-default="system"  class="dark">', false);
});

it('renders no theme class without the cookie', function () {
    $this->get('/login')
        ->assertOk()
        ->assertSee('<html lang="en" data-theme-default="system"  class="">', false);
});

it('renders the default theme of the instance until the cookie says otherwise', function () {
    app(SettingsRegistry::class)->update(['ui_theme_default' => 'dark'], Actor::cli('ops'));

    $this->get('/login')->assertSee('<html lang="en" data-theme-default="dark"  class="dark">', false);
    $this->withUnencryptedCookie('appearance', 'light')->get('/login')
        ->assertSee('<html lang="en" data-theme-default="dark"  class="">', false);
});

it('shares nothing with the views from the appearance middleware', function () {
    $this->withUnencryptedCookie('appearance', 'dark')->get('/login')->assertOk();

    expect(view()->getShared())->not->toHaveKey('appearance');
});
