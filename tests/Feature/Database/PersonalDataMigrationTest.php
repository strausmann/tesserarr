<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Privacy\BlindIndex;
use App\Privacy\PiiEncrypter;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| An installation of an earlier release candidate holds plain-text addresses
| (users.email as a contact address since task 8, identities.email). The
| migration 2026_11_27_100000_encrypt_personal_data encrypts them in place: a
| verified address becomes the confirmed account address of the oldest
| account that has it, every other one is removed (it was never confirmed);
| provider addresses are kept. Run against tables in a schema of its own.
*/

afterEach(function () {
    DB::statement('set search_path to public');
});

it('encrypts the addresses of an earlier release in place and keeps only verified, unique ones', function () {
    DB::statement('create schema legacy');
    DB::statement('set search_path to legacy');
    Schema::create('users', function (Blueprint $table) {
        $table->id();
        $table->string('username', 64)->unique();
        $table->string('email')->nullable();
        $table->timestamp('email_verified_at')->nullable();
    });
    Schema::create('identities', function (Blueprint $table) {
        $table->id();
        $table->foreignId('user_id')->constrained()->cascadeOnDelete();
        $table->string('email')->nullable();
    });
    DB::table('users')->insert([
        ['username' => 'alice', 'email' => 'Alice@Example.org', 'email_verified_at' => '2026-01-01 00:00:00'],
        ['username' => 'alice2', 'email' => 'alice@example.org', 'email_verified_at' => '2026-01-02 00:00:00'],
        ['username' => 'bob', 'email' => 'bob@example.org', 'email_verified_at' => null],
        ['username' => 'carol', 'email' => null, 'email_verified_at' => null],
    ]);
    DB::table('identities')->insert(['user_id' => 3, 'email' => 'bob@idp.example.net']);

    (require database_path('migrations/2026_11_27_100000_encrypt_personal_data.php'))->up();

    $users = DB::table('users')->orderBy('id')->get()->keyBy('username');
    $pii = app(PiiEncrypter::class);
    expect($pii->decrypt((string) $users['alice']->email))->toBe('Alice@Example.org')
        ->and($users['alice']->email_bidx)->toBe(BlindIndex::of('alice@example.org'))
        ->and($users['alice']->email_confirmed_at)->not->toBeNull()
        // The younger account with the same address, and the unverified one, lose it.
        ->and($users['alice2']->email)->toBeNull()
        ->and($users['bob']->email)->toBeNull()
        ->and($users['carol']->email)->toBeNull()
        ->and(DB::table('account_email_claims')->get(['bidx', 'user_id', 'kind'])->map(fn ($r) => (array) $r)->all())
        ->toBe([['bidx' => BlindIndex::of('alice@example.org'), 'user_id' => $users['alice']->id, 'kind' => 'confirmed']]);

    $identity = DB::table('identities')->sole();
    expect($identity->email)->not->toContain('@')
        ->and($pii->decrypt((string) $identity->email))->toBe('bob@idp.example.net')
        ->and($identity->email_bidx)->toBe(BlindIndex::of('bob@idp.example.net'));
});
