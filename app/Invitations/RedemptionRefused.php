<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Invitations;

use RuntimeException;

/**
 * Internal to InvitationService: thrown inside the redemption savepoint so
 * that it rolls back the use of an invitation that may no longer be
 * redeemed (its issuer may no longer give it, or the account of a recovery
 * invitation is locked). Callers see InvitationNotRedeemable; $reason goes
 * to the audit log.
 */
final class RedemptionRefused extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
