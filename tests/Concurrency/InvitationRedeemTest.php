<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Audit\Actor;
use App\Invitations\InvitationNotRedeemable;
use App\Invitations\InvitationService;
use App\Models\User;
use Database\Seeders\RolesSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Support\AdvisoryBarrier;

/*
| Two redemptions of the same invitation at the same moment (barrier
| `invitation.consume`, before the conditional UPDATE of InvitationStore):
| exactly one makes an account (Spec 9.1, "atomic redemption").
*/

beforeEach(fn () => (new RolesSeeder)->run());

afterEach(function () {
    DB::table('identities')->delete();
    DB::table('invitations')->delete();
    DB::table('model_has_roles')->delete();
    DB::table('users')->delete();
    (new RolesSeeder)->run();
});

/** @param  list<array{exit: int|null, ok: bool, result: mixed, error: string|null, output: string}>  $results */
function outcomes(array $results): array
{
    return array_map(fn (array $r) => is_array($r['result']) ? $r['result']['outcome'] : 'error: '.$r['error'], $results);
}

it('lets exactly one of two parallel redemptions win', function () {
    $plain = app(InvitationService::class)->issue(Actor::cli('test'))->plainToken;

    $results = AdvisoryBarrier::race(2, fn (int $i) => [
        'point' => 'invitation.consume', 'scenario' => 'Redeem', 'args' => [$plain, "subject-{$i}"],
    ]);

    expect(outcomes($results))->toContain('ok')->toContain(InvitationNotRedeemable::class)
        ->and(User::query()->count())->toBe(1)
        ->and(DB::table('identities')->count())->toBe(1);
});
