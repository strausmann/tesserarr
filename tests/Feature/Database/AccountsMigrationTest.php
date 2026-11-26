<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| An installation that ran the starter kit's users table (name, unique
| plain-text email, password required) before task 8 is upgraded in place by
| 2026_11_26_100050_reshape_users_for_accounts: usernames are derived from the
| existing rows, nothing is lost. Run against a copy of the old table in a
| schema of its own, so the migrated tables of the suite stay untouched.
*/

/** Creates the kit's users table in the schema `legacy` and points the connection at it. */
function legacyUsers(array $rows): void
{
    DB::statement('create schema legacy');
    DB::statement('set search_path to legacy');

    Schema::create('users', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->string('email')->unique();
        $table->timestamp('email_verified_at')->nullable();
        $table->string('password');
        $table->rememberToken();
        $table->timestamps();
    });

    DB::table('users')->insert($rows);
}

function reshape(): void
{
    $migration = require database_path('migrations/2026_11_26_100050_reshape_users_for_accounts.php');
    $migration->up();
}

afterEach(function () {
    DB::statement('set search_path to public');
});

it('derives a unique username from the existing accounts and keeps their names and addresses', function () {
    // Stand-ins for the stored hashes, which the migration must keep as they are.
    [$a, $b, $c] = [str_repeat('a', 12), str_repeat('b', 12), str_repeat('c', 12)];
    legacyUsers([
        ['name' => 'Alice Example', 'email' => 'Alice@example.org', 'password' => $a],
        ['name' => 'Alice Other', 'email' => 'alice@example.net', 'password' => $b],
        ['name' => '!!', 'email' => 'x@example.com', 'password' => $c],
    ]);

    reshape();

    $rows = DB::table('users')->orderBy('id')->get(['username', 'display_name', 'email', 'password', 'locked_at'])->map(fn ($r) => (array) $r)->all();

    expect($rows)->toBe([
        ['username' => 'alice', 'display_name' => 'Alice Example', 'email' => 'Alice@example.org', 'password' => $a, 'locked_at' => null],
        ['username' => 'alice2', 'display_name' => 'Alice Other', 'email' => 'alice@example.net', 'password' => $b, 'locked_at' => null],
        ['username' => 'x-user', 'display_name' => '!!', 'email' => 'x@example.com', 'password' => $c, 'locked_at' => null],
    ]);
});

it('leaves a schema in which an account needs no name, no address and no password', function () {
    legacyUsers([]);

    reshape();

    DB::table('users')->insert(['username' => 'bob']);
    DB::table('users')->insert(['username' => 'carol', 'email' => 'same@example.org']);
    DB::table('users')->insert(['username' => 'dave', 'email' => 'same@example.org']);

    expect(DB::table('users')->count())->toBe(3)
        // In a savepoint: the refused insert must not abort the test's transaction.
        ->and(fn () => DB::transaction(fn () => DB::table('users')->insert(['username' => 'bob'])))
        ->toThrow(UniqueConstraintViolationException::class);
});
