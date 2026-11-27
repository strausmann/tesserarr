<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Audit\Actor;
use App\Models\User;
use App\Privacy\AccountEmailPolicy;
use App\Privacy\BlindIndex;
use App\Privacy\EmailConfirmation;
use App\Settings\Settings;
use Database\Seeders\RolesSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Process;

/*
| Bob asks for an address whose reservation by Alice ran out: request()
| holds Bob's row and then needs Alice's to free it. When another
| transaction holds Alice's row and then needs Bob's (Alice asking for Bob's
| expired address), the two deadlock; PostgreSQL aborts Bob's request, which
| is run again and succeeds.
*/

beforeEach(function () {
    (new RolesSeeder)->run();
    app(Settings::class)->set(AccountEmailPolicy::SETTING, true, Actor::cli('test'));
});

afterEach(function () {
    DB::table('email_confirmations')->delete();
    DB::table('account_email_claims')->delete();
    DB::table('app_settings')->delete();
    DB::table('model_has_roles')->delete();
    DB::table('users')->delete();
    (new RolesSeeder)->run();
});

it('runs a request again that lost a deadlock while freeing an expired reservation', function () {
    Mail::fake();
    $alice = User::factory()->create(['username' => 'race-alice']);
    $bob = User::factory()->create(['username' => 'race-bob']);
    app(EmailConfirmation::class)->request($alice, 'held@example.org', Actor::user($alice));
    DB::table('account_email_claims')->update(['reserved_until' => now()->subHour()]);
    DB::table('users')->where('id', $alice->id)->update(['pending_email_reserved_until' => now()->subHour()]);

    // The other side: holds Alice's row, later wants Bob's.
    config(['database.connections.race_other' => config('database.connections.'.config('database.default'))]);
    $other = DB::connection('race_other');
    $other->beginTransaction();
    $other->table('users')->where('id', $alice->id)->lockForUpdate()->first(['id']);

    $key = crc32('reclaim-deadlock-retry');
    $bobs = Process::path(base_path())->timeout(60)->env([
        'APP_ENV' => 'testing',
        // Bound but never held: the barrier point passes straight through.
        'TESSERARR_TEST_BARRIER' => 'advisory',
        'TESSERARR_TEST_BARRIER_POINT' => 'email.claim',
        'TESSERARR_TEST_BARRIER_KEY' => (string) $key,
    ])->start([PHP_BINARY, 'artisan', 'tesserarr:test:race', 'email.claim', 'ClaimEmail', 'race-bob', 'held@example.org']);

    $waited = false;
    try {
        $deadline = microtime(true) + 15;
        while (! ($waited = (int) $other->scalar("select count(*) from pg_locks where locktype = 'transactionid' and not granted") > 0) && microtime(true) < $deadline) {
            usleep(20_000);
        }
        $other->table('users')->where('id', $bob->id)->lockForUpdate()->first(['id']);
        $other->commit();
    } finally {
        if ($other->transactionLevel() > 0) {
            $other->rollBack();
        }
        $other->disconnect();
    }

    $result = $bobs->wait();
    $lines = array_values(array_filter(explode("\n", trim($result->output()))));
    $payload = json_decode((string) end($lines), true);

    expect($waited)->toBeTrue('Bob never waited for Alice, so the race did not happen')
        ->and($payload['ok'] ?? false)->toBeTrue($result->output())
        ->and($payload['result']['claimed'] ?? null)->toBeTrue()
        ->and(DB::table('account_email_claims')->where('bidx', BlindIndex::of('held@example.org'))->value('user_id'))->toBe($bob->id);
});
