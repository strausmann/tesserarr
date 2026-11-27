<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Tests\Support\RaceScenarios;

use App\Identity\IdentityClaim;
use App\Identity\LoginMethod;
use App\Invitations\InvitationNotRedeemable;
use App\Invitations\InvitationService;

/**
 * Redeems the invitation with the plain token in the first argument with an
 * OIDC identity whose subject is the second argument; the call stops at the
 * barrier point `invitation.consume` before the conditional UPDATE. Reports
 * "ok" with the account and whether the invitation was used, or the class
 * of the refusal.
 */
final class Redeem implements RaceScenario
{
    public function __construct(private readonly InvitationService $invitations) {}

    public function run(array $arguments): mixed
    {
        $claim = new IdentityClaim(LoginMethod::Oidc, 'example', $arguments[1], 'race-'.$arguments[1]);

        try {
            $redeemed = $this->invitations->redeem($arguments[0], $claim, '192.0.2.1');
        } catch (InvitationNotRedeemable) {
            return ['outcome' => InvitationNotRedeemable::class];
        }

        return ['outcome' => 'ok', 'user' => $redeemed->user->id, 'consumed' => $redeemed->consumed];
    }
}
