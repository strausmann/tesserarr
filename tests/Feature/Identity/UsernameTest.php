<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Authorization\Roles;
use App\Identity\Rules\DisplayName;
use App\Identity\Rules\Username;
use App\Identity\UsernameDeriver;
use App\Models\User;
use Illuminate\Support\Facades\Validator;

function usernameErrors(mixed $value): array
{
    return Validator::make(['username' => $value], ['username' => [new Username]])->errors()->get('username');
}

it('accepts lower-case usernames of 3 to 64 characters', function (string $name) {
    expect(usernameErrors($name))->toBe([]);
})->with(['bob', 'alice.b', 'jo_42', 'zoë', str_repeat('a', 64)]);

it('refuses usernames that are too short, too long, upper-case or not NFC', function (string $name) {
    expect(usernameErrors($name))->not->toBe([]);
})->with(['ab', str_repeat('a', 65), 'Bob', "zoe\u{0308}", 'bob smith', 'bob/../x']);

it('refuses a username that is no string', function () {
    expect(usernameErrors(['bob']))->not->toBe([])->and(usernameErrors(42))->not->toBe([]);
});

it('rejects control characters in username and display name', function () {
    $displayErrors = Validator::make(['display_name' => "Bob\u{0007}"], ['display_name' => [new DisplayName]])->errors()->get('display_name');

    expect(usernameErrors("bob\u{0000}x"))->not->toBe([])
        ->and(usernameErrors("bo\u{200E}b"))->not->toBe([])
        ->and($displayErrors)->not->toBe([])
        ->and(Validator::make(['display_name' => 'Bob Smith'], ['display_name' => [new DisplayName]])->errors()->all())->toBe([]);
});

it('rejects a username that contains @ on redemption, rename, CLI and API', function (string $path) {
    $operator = User::factory()->operator()->create(['username' => 'alice']);

    match ($path) {
        'rename' => $this->actingAs($operator)
            ->patch('/settings/profile', ['username' => 'alice@example.org', 'display_name' => 'Alice'])
            ->assertSessionHasErrors(['username' => __('validation.username.at_sign')]),
        'cli' => $this->artisan('tesserarr:role:assign', ['username' => 'alice@example.org', 'role' => 'user'])
            ->expectsOutputToContain(__('validation.username.at_sign'))->assertFailed(),
    };

    expect($operator->fresh()?->username)->toBe('alice');
})->with(['rename', 'cli']);

it('normalises a renamed username to lower case NFC and keeps it unique', function () {
    $alice = User::factory()->withRole(Roles::USER)->create(['username' => 'alice']);
    User::factory()->create(['username' => 'zoë']);

    $this->actingAs($alice)->patch('/settings/profile', ['username' => 'ZOE'."\u{0308}", 'display_name' => 'Alice'])
        ->assertSessionHasErrors('username');
    $this->actingAs($alice)->patch('/settings/profile', ['username' => 'Alice.B', 'display_name' => 'Alice B'])
        ->assertSessionHasNoErrors();

    expect($alice->fresh()?->username)->toBe('alice.b')->and($alice->fresh()?->display_name)->toBe('Alice B');
});

it('derives a username without @ from an email-shaped preferred_username of a provider', function () {
    User::factory()->create(['username' => 'bob']);
    User::factory()->create(['username' => 'bob2']);

    $deriver = app(UsernameDeriver::class);

    expect($deriver->derive('Bob@contoso.example'))->toBe('bob3')
        ->and($deriver->derive('Kim.Lee@idp.example'))->toBe('kim.lee')
        ->and($deriver->derive('a@b.example'))->toBe('a-user')
        ->and($deriver->derive("Zo\u{0065}\u{0308}!#@x.example"))->toBe('zoë')
        ->and($deriver->derive('@@@'))->toBe('user');
});
