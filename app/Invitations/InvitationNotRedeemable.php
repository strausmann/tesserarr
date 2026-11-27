<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Invitations;

use RuntimeException;

/**
 * The invitation cannot be redeemed. One message for every reason (unknown,
 * used, revoked, expired, reserved by another redemption, a recovery invitation
 * its issuer may no longer give): the reason is in
 * the audit log only, so the answer tells nobody which tokens exist.
 */
final class InvitationNotRedeemable extends RuntimeException
{
    public const UNKNOWN = 'unknown';

    public const REDEEMED = 'redeemed';

    public const REVOKED = 'revoked';

    public const EXPIRED = 'expired';

    public const RESERVED = 'reserved';

    public const IDENTITY_TAKEN = 'identity_taken';

    /** A recovery invitation whose issuer may no longer give it (see InvitationService::recoveryTarget). */
    public const ISSUER_LACKS_PERMISSION = 'issuer_lacks_permission';

    public function __construct(public readonly string $reason)
    {
        parent::__construct('invitation_not_redeemable');
    }
}
