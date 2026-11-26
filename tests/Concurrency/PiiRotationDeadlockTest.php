<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Models\User;
use App\Privacy\PiiEncrypter;
use App\Privacy\PiiKeys;
use Database\Seeders\RolesSeeder;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;

/*
| tesserarr:pii:rotate locks the rows of a batch one after the other. When
| another transaction holds a later row and then wants an earlier one, the
| two deadlock; PostgreSQL aborts the rotation's batch, which runs again
| (EmailConfirmation::ATTEMPTS) and rewrites every row, each counted once.
*/

beforeEach(fn () => (new RolesSeeder)->run());

afterEach(function () {
    DB::table('account_email_claims')->delete();
    DB::table('model_has_roles')->delete();
    DB::table('users')->delete();
    (new RolesSeeder)->run();
});

it('runs a batch of the rotation again that lost a deadlock', function () {
    $first = User::factory()->withConfirmedEmail('first@example.org')->create(['username' => 'race-first']);
    $second = User::factory()->withConfirmedEmail('second@example.org')->create(['username' => 'race-second']);

    config(['database.connections.race_other' => config('database.connections.'.config('database.default'))]);
    $other = DB::connection('race_other');
    $other->beginTransaction();
    $other->table('users')->where('id', $second->id)->lockForUpdate()->first(['id']);

    $newKey = 'base64:'.base64_encode(str_repeat('d', 32));
    $rotation = Process::path(base_path())->timeout(60)->env([
        'APP_ENV' => 'testing',
        'TESSERARR_PII_KEY' => $newKey,
        'TESSERARR_PII_PREVIOUS_KEYS' => (string) config('tesserarr.pii.key'),
    ])->start([PHP_BINARY, 'artisan', 'tesserarr:pii:rotate']);

    $waited = false;
    try {
        $deadline = microtime(true) + 15;
        while (! ($waited = (int) $other->scalar("select count(*) from pg_locks where locktype = 'transactionid' and not granted") > 0) && microtime(true) < $deadline) {
            usleep(20_000);
        }
        // The rotation holds the first row and waits for the second: now the
        // other side wants the first.
        $other->table('users')->where('id', $first->id)->lockForUpdate()->first(['id']);
        $other->commit();
    } finally {
        if ($other->transactionLevel() > 0) {
            $other->rollBack();
        }
        $other->disconnect();
    }

    $result = $rotation->wait();
    $new = new Encrypter(PiiKeys::decode($newKey), PiiEncrypter::CIPHER);

    expect($waited)->toBeTrue('the rotation never waited, so the race did not happen')
        ->and($result->successful())->toBeTrue($result->output().$result->errorOutput())
        ->and($result->output())->toContain('users.email: 2 of 2 re-encrypted')
        ->and($new->decryptString((string) DB::table('users')->where('id', $first->id)->value('email')))->toBe('first@example.org')
        ->and($new->decryptString((string) DB::table('users')->where('id', $second->id)->value('email')))->toBe('second@example.org');
});
