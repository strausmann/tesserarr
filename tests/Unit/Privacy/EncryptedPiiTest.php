<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Identity\Identity;
use App\Models\User;
use App\Privacy\PiiEncrypter;
use App\Privacy\PiiKeyRefused;
use App\Privacy\PiiKeys;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/*
| Personal data at rest (task 8a): the addresses of accounts and identities and
| the PGP key are encrypted with a key of their own (TESSERARR_PII_KEY), never
| with APP_KEY, so a database dump, a backup or a leaked APP_KEY alone reveals
| none of them.
*/

/** A 32-byte key of $char, as the environment would hold it. */
function piiKey(string $char): string
{
    return 'base64:'.base64_encode(str_repeat($char, 32));
}

/** Use $config as the keys of this test (the singletons read them once). */
function usePiiKeys(array $config): void
{
    config(['tesserarr.pii' => [...(array) config('tesserarr.pii'), ...$config]]);
    app()->forgetInstance(PiiKeys::class);
    app()->forgetInstance(PiiEncrypter::class);
}

it('stores users.email, identities.email and pgp_public_key encrypted with the pii key, not APP_KEY', function () {
    $address = 'probe-'.Str::lower(Str::random(8)).'@example.invalid';
    $user = User::factory()->create(['email' => $address, 'pgp_public_key' => "-----BEGIN PGP PUBLIC KEY BLOCK-----\n{$address}"]);
    $identity = Identity::query()->create([
        'user_id' => $user->id, 'method' => 'oidc', 'provider_key' => 'example', 'subject' => 's-1', 'email' => $address,
    ]);

    $rows = [
        DB::table('users')->where('id', $user->id)->value('email'),
        DB::table('users')->where('id', $user->id)->value('pgp_public_key'),
        DB::table('identities')->where('id', $identity->id)->value('email'),
    ];

    $pii = new Encrypter(PiiKeys::decode((string) config('tesserarr.pii.key')), PiiEncrypter::CIPHER);
    foreach ($rows as $raw) {
        expect($raw)->toBeString()->not->toContain('@')->not->toContain($address)
            ->and(fn () => Crypt::decryptString($raw))->toThrow(DecryptException::class)
            ->and($pii->decryptString($raw))->toContain($address);
    }

    expect($user->refresh()->email)->toBe($address)
        ->and($identity->refresh()->email)->toBe($address);
});

it('keeps an empty address empty instead of encrypting nothing', function () {
    $user = User::factory()->create(['email' => null]);

    expect(DB::table('users')->where('id', $user->id)->value('email'))->toBeNull()
        ->and($user->refresh()->email)->toBeNull();
});

it('reads values written with a previous key after rotation and rewrites them with the current key', function () {
    usePiiKeys(['key' => piiKey('o'), 'previous_keys' => '']);
    $user = User::factory()->create(['email' => 'rotate@example.invalid']);
    $old = DB::table('users')->where('id', $user->id)->value('email');

    usePiiKeys(['key' => piiKey('n'), 'previous_keys' => piiKey('o')]);
    expect($user->refresh()->email)->toBe('rotate@example.invalid')
        ->and(app(PiiEncrypter::class)->isCurrent((string) $old))->toBeFalse();

    $this->artisan('tesserarr:pii:rotate')->assertSuccessful();

    $new = (string) DB::table('users')->where('id', $user->id)->value('email');
    expect($new)->not->toBe($old)
        ->and(app(PiiEncrypter::class)->isCurrent($new))->toBeTrue();

    // Without the old key the rewritten value still reads: it is on the new key alone.
    usePiiKeys(['key' => piiKey('n'), 'previous_keys' => '']);
    expect($user->refresh()->email)->toBe('rotate@example.invalid');
});

it('counts in a dry run of the rotation and changes nothing', function () {
    usePiiKeys(['key' => piiKey('o'), 'previous_keys' => '']);
    $user = User::factory()->create(['email' => 'dry@example.invalid']);
    $old = DB::table('users')->where('id', $user->id)->value('email');
    usePiiKeys(['key' => piiKey('n'), 'previous_keys' => piiKey('o')]);

    $this->artisan('tesserarr:pii:rotate', ['--dry-run' => true])
        ->expectsOutputToContain('users.email: 1 of 1 would be re-encrypted')
        ->doesntExpectOutputToContain('dry@example.invalid')
        ->assertSuccessful();

    expect(DB::table('users')->where('id', $user->id)->value('email'))->toBe($old);
});

it('recomputes the blind index in the rotation when a previous blind index key is set', function () {
    usePiiKeys(['blind_index_key' => str_repeat('a', 32), 'blind_index_previous_key' => null]);
    $identity = Identity::query()->create([
        'user_id' => User::factory()->create()->id, 'method' => 'oidc', 'provider_key' => 'example', 'subject' => 's-1', 'email' => 'Idx@Example.invalid',
    ]);
    $old = DB::table('identities')->where('id', $identity->id)->value('email_bidx');

    usePiiKeys(['blind_index_key' => str_repeat('b', 32), 'blind_index_previous_key' => str_repeat('a', 32)]);
    $this->artisan('tesserarr:pii:rotate')->assertSuccessful();

    expect(DB::table('identities')->where('id', $identity->id)->value('email_bidx'))
        ->not->toBe($old)
        ->toBe(hash_hmac('sha256', 'idx@example.invalid', str_repeat('b', 32)));
});

it('refuses keys that are missing, malformed or equal to APP_KEY', function (array $pii, string $reason) {
    $appKey = piiKey('k');

    expect(fn () => PiiKeys::fromConfig([
        'key' => piiKey('p'),
        'previous_keys' => '',
        'blind_index_key' => str_repeat('b', 32),
        'blind_index_previous_key' => null,
        ...$pii,
    ], $appKey))->toThrow(function (PiiKeyRefused $e) use ($reason) {
        expect($e->reason)->toBe($reason)
            // The message names the variable, never a value.
            ->and($e->getMessage())->not->toContain(base64_encode(str_repeat('k', 32)));
    });
})->with([
    'pii key missing' => [['key' => null], 'pii_key_missing'],
    'pii key empty' => [['key' => ''], 'pii_key_missing'],
    'pii key too short' => [['key' => 'base64:'.base64_encode('short')], 'pii_key_invalid'],
    'pii key equals APP_KEY' => [['key' => piiKey('k')], 'pii_key_is_app_key'],
    'pii key equals APP_KEY written raw' => [['key' => str_repeat('k', 32)], 'pii_key_is_app_key'],
    'previous key malformed' => [['previous_keys' => 'base64:AAAA'], 'pii_previous_key_invalid'],
    'blind index key missing' => [['blind_index_key' => null], 'blind_index_key_missing'],
    'blind index key too short' => [['blind_index_key' => 'short'], 'blind_index_key_invalid'],
    'blind index key equals the pii key' => [['blind_index_key' => piiKey('p')], 'blind_index_key_reused'],
    'previous blind index key too short' => [['blind_index_previous_key' => 'short'], 'blind_index_previous_key_invalid'],
    'previous blind index key equals the current one' => [['blind_index_previous_key' => str_repeat('b', 32)], 'blind_index_previous_key_reused'],
    'previous blind index key equals the pii key' => [['blind_index_previous_key' => piiKey('p')], 'blind_index_previous_key_reused'],
    'previous blind index key equals APP_KEY' => [['blind_index_previous_key' => piiKey('k')], 'blind_index_previous_key_reused'],
]);

it('accepts a previous blind index key of its own', function () {
    $keys = PiiKeys::fromConfig([
        'key' => piiKey('p'), 'previous_keys' => '', 'blind_index_key' => str_repeat('b', 32), 'blind_index_previous_key' => str_repeat('o', 32),
    ], piiKey('k'));

    expect($keys->blindIndexPrevious)->toBe(str_repeat('o', 32));
});

it('refuses to boot without TESSERARR_PII_KEY or with the same value as APP_KEY', function (array $env, string $expected) {
    // A long-running process of the central instance (the queue worker here)
    // checks the keys while it boots, before it touches a job.
    $result = Process::path(base_path())->timeout(30)->env([
        'APP_ENV' => 'testing',
        'APP_KEY' => piiKey('k'),
        'TESSERARR_BLIND_INDEX_KEY' => str_repeat('b', 32),
        ...$env,
    ])->run([PHP_BINARY, 'artisan', 'queue:work', '--once', '--stop-when-empty']);

    expect($result->successful())->toBeFalse()
        ->and($result->output().$result->errorOutput())->toContain($expected)
        ->not->toContain(base64_encode(str_repeat('k', 32)));
})->with([
    'missing' => [['TESSERARR_PII_KEY' => ''], 'TESSERARR_PII_KEY is not set'],
    'same as APP_KEY' => [['TESSERARR_PII_KEY' => piiKey('k')], 'TESSERARR_PII_KEY must differ from APP_KEY'],
]);

it('boots the queue worker with valid keys', function () {
    $result = Process::path(base_path())->timeout(30)->env([
        'APP_ENV' => 'testing',
        'APP_KEY' => piiKey('k'),
    ])->run([PHP_BINARY, 'artisan', 'queue:work', 'database', '--once', '--stop-when-empty']);

    expect($result->successful())->toBeTrue($result->errorOutput());
});
