<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Audit\Actor;
use App\Models\User;
use App\Privacy\AccountEmailPolicy;
use App\Privacy\EmailConfirmation;
use App\Settings\Settings;
use Database\Seeders\RolesSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Process;

/*
| The hourly release of expired reservations runs while a request of the
| same account replaces its expired reservation: the request holds the
| account row and then locks the claim. The release must take the locks in
| the same order (account row, then claim), or the two deadlock.
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

it('lets the release of expired reservations and a request of the same account wait for each other', function () {
    Mail::fake();
    $user = User::factory()->create(['username' => 'race-expire']);
    app(EmailConfirmation::class)->request($user, 'held@example.org', Actor::user($user));
    // Run out an hour ago.
    DB::table('account_email_claims')->update(['reserved_until' => now()->subHour()]);
    DB::table('users')->where('id', $user->id)->update(['pending_email_reserved_until' => now()->subHour()]);

    // The request: holds the account row, as request() does.
    config(['database.connections.race_request' => config('database.connections.'.config('database.default'))]);
    $request = DB::connection('race_request');
    $request->beginTransaction();
    $request->table('users')->where('id', $user->id)->lockForUpdate()->first(['id']);

    $release = Process::path(base_path())->timeout(60)->env(['APP_ENV' => 'testing'])
        ->start([PHP_BINARY, 'artisan', 'tesserarr:email:release-expired']);

    $waited = false;
    $claims = null;
    try {
        $deadline = microtime(true) + 15;
        while (! ($waited = (int) $request->scalar("select count(*) from pg_locks where locktype = 'transactionid' and not granted") > 0) && microtime(true) < $deadline) {
            usleep(20_000);
        }
        // Then locks the expired claim, as dropExpiredReservation() does.
        $claims = $request->table('account_email_claims')->where('user_id', $user->id)->lockForUpdate()->get(['bidx'])->count();
        $request->commit();
    } finally {
        if ($request->transactionLevel() > 0) {
            $request->rollBack();
        }
        $request->disconnect();
    }

    $result = $release->wait();

    expect($waited)->toBeTrue('the release never waited, so the race did not happen')
        ->and($claims)->toBe(1)
        ->and($result->successful())->toBeTrue($result->output().$result->errorOutput())
        ->and($result->output())->toContain('1 reservation(s) released')
        ->and(DB::table('account_email_claims')->count())->toBe(0);
});
