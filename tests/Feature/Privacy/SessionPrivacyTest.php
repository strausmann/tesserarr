<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Audit\Actor;
use App\Models\User;
use App\Privacy\AccountEmailPolicy;
use App\Settings\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
| The session is no copy of personal data (task 8a): a failed validation does
| not flash an address or a token into it, the token of a confirmation link
| never reaches the server in a URL (fragment), and sessions are encrypted by
| default. Run with the database driver, as in production, and read what is
| stored in the `sessions` table.
*/

/**
 * The stored sessions, decoded from Base64 (the database store's format).
 * The requests below send a Referer instead of using from(): that helper
 * starts a session of the test itself, which the request then never stores.
 */
function storedSessions(): string
{
    return DB::table('sessions')->pluck('payload')
        ->map(fn ($payload) => (string) base64_decode((string) $payload, true))->implode("\n");
}

beforeEach(function () {
    config(['session.driver' => 'database']);
    app(Settings::class)->set(AccountEmailPolicy::SETTING, true, Actor::cli('test'));
});

it('encrypts sessions unless SESSION_ENCRYPT says otherwise', function () {
    $previous = getenv('SESSION_ENCRYPT');
    putenv('SESSION_ENCRYPT');
    unset($_ENV['SESSION_ENCRYPT'], $_SERVER['SESSION_ENCRYPT']);
    try {
        $config = require config_path('session.php');
    } finally {
        if ($previous !== false) {
            putenv("SESSION_ENCRYPT={$previous}");
        }
    }

    expect($config['encrypt'])->toBeTrue()
        ->and((string) file_get_contents(base_path('.env.example')))->toContain("\nSESSION_ENCRYPT=true\n");
});

it('keeps an address of a failed validation out of the stored session', function (bool $encrypt) {
    config(['session.encrypt' => $encrypt]);
    $address = 'probe-'.Str::lower(Str::random(6)).'@example.invalid';
    $user = User::factory()->create();

    // Invalid (a space), so the validation fails and Laravel flashes the input.
    $this->actingAs($user)->withHeader('Referer', url('/account'))->put('/account/email', ['email' => $address.' x'])
        ->assertSessionHasErrors('email');

    expect(DB::table('sessions')->count())->toBeGreaterThan(0)
        ->and(storedSessions())->not->toContain($address)
        // Unencrypted, the stored session is readable: the flash happened, without the address.
        ->and($encrypt || str_contains(storedSessions(), '_old_input'))->toBeTrue();
})->with(['unencrypted' => false, 'encrypted' => true]);

it('keeps the token of a confirmation link out of the session', function () {
    config(['session.encrypt' => false]);
    $token = Str::random(43);

    // Too long: the validation fails and would flash the input.
    $this->withHeader('Referer', url('/email/confirm'))->post('/email/confirm', ['token' => $token.str_repeat('x', 200)])
        ->assertSessionHasErrors('token');
    expect(storedSessions())->not->toContain($token)
        ->and(storedSessions())->toContain('_old_input');

    // The link carries the token in the fragment: the page request has none.
    $this->get('/email/confirm')->assertOk();
    // The previous URL the session keeps for that page names it, without any token.
    expect(session()->previousUrl())->toEndWith('/email/confirm');

    $this->withHeader('Referer', url('/email/confirm'))->post('/email/confirm', ['token' => $token])
        ->assertSessionHasErrors('token');

    expect(session()->previousUrl())->not->toContain($token)
        ->and(storedSessions())->not->toContain($token);
});

it('keeps an audit search of a failed validation out of the flashed input', function () {
    config(['session.encrypt' => false]);
    $operator = User::factory()->operator()->create();
    $address = 'probe-q@example.invalid';

    // Too long (max 100): the validation fails and Laravel flashes the input.
    $this->actingAs($operator)->withHeader('Referer', url('/audit'))
        ->get('/audit?q='.urlencode($address.str_repeat('x', 120)).'&period=7d')
        ->assertSessionHasErrors('q');

    $old = DB::table('sessions')->pluck('payload')
        ->map(fn ($payload) => json_decode((string) base64_decode((string) $payload, true), true)['_old_input'] ?? null)
        ->filter()->first();
    expect($old)->toBeArray()->toHaveKey('period')->not->toHaveKey('q');
});
