<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Invitations;

use RuntimeException;

/**
 * Internal to InvitationService: thrown inside the redemption savepoint so
 * that it rolls back the use of a recovery invitation whose issuer may no
 * longer give it. Callers see InvitationNotRedeemable.
 */
final class IssuerNoLongerPermitted extends RuntimeException {}
