<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Audit\Actor;
use App\Models\User;
use App\Privacy\AccountEmailPolicy;
use App\Privacy\BlindIndex;
use App\Settings\Settings;
use Database\Seeders\RolesSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Support\AdvisoryBarrier;

/*
| Two accounts ask for the same account address at the same moment (barrier
| `email.claim`, after both freed stale reservations, before either claims).
| The primary key of account_email_claims must let exactly one win (pre-flight
| K16: the one place where an address is unique).
*/

beforeEach(function () {
    (new RolesSeeder)->run();
    app(Settings::class)->set(AccountEmailPolicy::SETTING, true, Actor::cli('test'));
});

afterEach(function () {
    DB::table('account_email_claims')->delete();
    DB::table('email_confirmations')->delete();
    DB::table('app_settings')->delete();
    DB::table('model_has_roles')->delete();
    DB::table('users')->delete();
    (new RolesSeeder)->run();
});

it('lets exactly one of two concurrent attempts store the same account address win', function () {
    User::factory()->create(['username' => 'race-alice']);
    User::factory()->create(['username' => 'race-bob']);

    $results = AdvisoryBarrier::race(2, [
        ['point' => 'email.claim', 'scenario' => 'ClaimEmail', 'args' => ['race-alice', 'same@example.org']],
        ['point' => 'email.claim', 'scenario' => 'ClaimEmail', 'args' => ['race-bob', 'Same@Example.org']],
    ]);

    expect(array_column($results, 'ok'))->toBe([true, true], json_encode($results))
        ->and(collect($results)->pluck('result.claimed')->sort()->values()->all())->toBe([false, true])
        ->and(DB::table('account_email_claims')->where('bidx', BlindIndex::of('same@example.org'))->count())->toBe(1)
        ->and(DB::table('users')->where('pending_email_bidx', BlindIndex::of('same@example.org'))->count())->toBe(1);
});
