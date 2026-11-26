<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Audit\Actor;
use App\Mail\ConfirmEmailMail;
use App\Models\User;
use App\Privacy\AccountEmailPolicy;
use App\Privacy\EmailConfirmation;
use App\Settings\Settings;
use Database\Seeders\RolesSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Process;

/*
| A resend (request()) holds the account row and then voids the older links;
| a confirmation of one of those links must take the locks in the same
| order (account row, then link), or the two deadlock and one of them fails
| with a server error.
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

it('lets a confirmation and a resend of the same account wait for each other instead of deadlocking', function () {
    Mail::fake();
    $user = User::factory()->create(['username' => 'race-confirm']);
    app(EmailConfirmation::class)->request($user, 'mine@example.org', Actor::user($user));
    $token = '';
    Mail::assertQueued(ConfirmEmailMail::class, function (ConfirmEmailMail $mail) use (&$token) {
        $token = substr($mail->url, (int) strrpos($mail->url, '/') + 1);

        return true;
    });

    // The resend: holds the account row, as request() does.
    config(['database.connections.race_resend' => config('database.connections.'.config('database.default'))]);
    $resend = DB::connection('race_resend');
    $resend->beginTransaction();
    $resend->table('users')->where('id', $user->id)->lockForUpdate()->first(['id']);

    $confirm = Process::path(base_path())->timeout(60)->env([
        'APP_ENV' => 'testing',
        // The barrier is bound but never reached: confirm() has no barrier point.
        'TESSERARR_TEST_BARRIER' => 'advisory',
        'TESSERARR_TEST_BARRIER_POINT' => 'email.claim',
        'TESSERARR_TEST_BARRIER_KEY' => (string) crc32('confirm-lock-order'),
    ])->start([PHP_BINARY, 'artisan', 'tesserarr:test:race', 'email.claim', 'ConfirmEmail', $token]);

    $waited = false;
    $voided = null;
    try {
        $deadline = microtime(true) + 15;
        while (! ($waited = (int) $resend->scalar("select count(*) from pg_locks where locktype = 'transactionid' and not granted") > 0) && microtime(true) < $deadline) {
            usleep(20_000);
        }
        // Then voids the older links, as request() does.
        $voided = $resend->table('email_confirmations')->where('user_id', $user->id)->whereNull('used_at')->update(['used_at' => now()]);
        $resend->commit();
    } finally {
        if ($resend->transactionLevel() > 0) {
            $resend->rollBack();
        }
        $resend->disconnect();
    }

    $result = $confirm->wait();
    $lines = array_values(array_filter(explode("\n", trim($result->output()))));
    $payload = json_decode((string) end($lines), true);

    expect($waited)->toBeTrue('the confirmation never waited, so the race did not happen')
        ->and($voided)->toBe(1)
        ->and($result->successful())->toBeTrue($result->output().$result->errorOutput())
        // The link was voided first, so it is refused cleanly, not with an error.
        ->and($payload['result']['confirmed'] ?? null)->toBeFalse();
});
