<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Audit\Actor;
use App\Invitations\Invitation;
use App\Invitations\InvitationService;
use App\Models\User;
use Database\Seeders\RolesSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Support\AdvisoryBarrier;

/*
| The same identity redeems two invitations at the same moment (Go audit
| #5.3, K18). Both pass the barrier `invitation.consume`, each uses its own
| invitation, both insert the identity; the unique index lets one win. The
| other's savepoint, with the use of its invitation, is rolled back and it
| signs in as the account the winner made.
*/

beforeEach(fn () => (new RolesSeeder)->run());

afterEach(function () {
    DB::table('identities')->delete();
    DB::table('invitations')->delete();
    DB::table('model_has_roles')->delete();
    DB::table('users')->delete();
    (new RolesSeeder)->run();
});

it('signs both in as one user when the same identity redeems two invitations at once', function () {
    $first = app(InvitationService::class)->issue(Actor::cli('test'))->plainToken;
    $second = app(InvitationService::class)->issue(Actor::cli('test'))->plainToken;

    $results = AdvisoryBarrier::race(2, [
        ['point' => 'invitation.consume', 'scenario' => 'Redeem', 'args' => [$first, 'same-subject']],
        ['point' => 'invitation.consume', 'scenario' => 'Redeem', 'args' => [$second, 'same-subject']],
    ]);

    expect(array_map(fn (array $r) => $r['result']['outcome'] ?? 'error: '.$r['error'], $results))->each->toBe('ok')
        ->and(array_unique(array_column(array_column($results, 'result'), 'user')))->toHaveCount(1)
        ->and(User::query()->count())->toBe(1)
        ->and(Invitation::query()->whereNull('redeemed_at')->count())->toBe(1);
});
