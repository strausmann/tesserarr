<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Models\User;
use App\Privacy\BlindIndex;
use App\Privacy\PiiEncrypter;
use App\Privacy\PiiKeys;
use Database\Seeders\RolesSeeder;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;

/*
| tesserarr:pii:rotate reads a batch, then rewrites each row. A confirmation
| that commits in between (here: a second connection holds the change of the
| account from its old to its new address until the rotation waits for the
| row) must survive: the rotation rewrites what is there now, not what it
| read before.
*/

beforeEach(fn () => (new RolesSeeder)->run());

afterEach(function () {
    DB::table('account_email_claims')->delete();
    DB::table('model_has_roles')->delete();
    DB::table('users')->delete();
    (new RolesSeeder)->run();
});

it('keeps a confirmation that commits while the rotation rewrites the row', function () {
    $user = User::factory()->withConfirmedEmail('old@example.org')->create(['username' => 'race-rotate']);
    $pii = app(PiiEncrypter::class);

    config(['database.connections.race_confirm' => config('database.connections.'.config('database.default'))]);
    $confirm = DB::connection('race_confirm');
    $confirm->beginTransaction();
    $confirm->table('users')->where('id', $user->id)->update([
        'email' => $pii->encrypt('new@example.org'),
        'email_bidx' => BlindIndex::of('new@example.org'),
    ]);

    $newKey = 'base64:'.base64_encode(str_repeat('r', 32));
    $rotation = Process::path(base_path())->timeout(60)->env([
        'APP_ENV' => 'testing',
        'TESSERARR_PII_KEY' => $newKey,
        'TESSERARR_PII_PREVIOUS_KEYS' => (string) config('tesserarr.pii.key'),
    ])->start([PHP_BINARY, 'artisan', 'tesserarr:pii:rotate']);

    $waited = false;
    try {
        // The rotation has read its batch and waits for the row.
        $deadline = microtime(true) + 15;
        while (! ($waited = (int) $confirm->scalar("select count(*) from pg_locks where locktype = 'transactionid' and not granted") > 0) && microtime(true) < $deadline) {
            usleep(20_000);
        }
    } finally {
        $confirm->commit();
        $confirm->disconnect();
    }

    $result = $rotation->wait();
    expect($waited)->toBeTrue('the rotation never waited for the row, so the race did not happen')
        ->and($result->successful())->toBeTrue($result->errorOutput());

    $both = (new Encrypter(PiiKeys::decode($newKey), PiiEncrypter::CIPHER))
        ->previousKeys([PiiKeys::decode((string) config('tesserarr.pii.key'))]);
    expect($both->decryptString((string) DB::table('users')->where('id', $user->id)->value('email')))->toBe('new@example.org');
});
