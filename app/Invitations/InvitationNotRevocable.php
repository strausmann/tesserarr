<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Invitations;

use RuntimeException;

/** The invitation is unknown, already redeemed or already revoked. */
final class InvitationNotRevocable extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('invitation_not_revocable');
    }
}
