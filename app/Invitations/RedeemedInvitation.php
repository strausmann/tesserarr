<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Invitations;

use App\Models\User;

/**
 * The account a redemption signs in. $consumed is false when the identity
 * already belonged to an account (the same identity redeemed another
 * invitation at the same moment, Go audit #5.3): then the account is
 * signed in and this invitation stays unused.
 */
final readonly class RedeemedInvitation
{
    public function __construct(
        public User $user,
        public InvitationKind $kind,
        public bool $consumed = true,
    ) {}
}
