<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

// The appearance cookie picks the theme of the root view. HandleAppearance
// keeps the value on the request, not in View::share(), which would outlive
// the request in worker mode (CONTRIBUTING.md, "Worker-safe code").

it('renders the dark theme when the appearance cookie asks for it', function () {
    $this->withUnencryptedCookie('appearance', 'dark')
        ->get('/')
        ->assertOk()
        ->assertSee('<html lang="en"  class="dark">', false);
});

it('renders no theme class without the cookie', function () {
    $this->get('/')
        ->assertOk()
        ->assertSee('<html lang="en"  class="">', false);
});

it('shares nothing with the views from the appearance middleware', function () {
    $this->withUnencryptedCookie('appearance', 'dark')->get('/')->assertOk();

    expect(view()->getShared())->not->toHaveKey('appearance');
});
