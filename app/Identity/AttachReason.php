<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Identity;

/**
 * Why an identity was bound to an account (K25): it redeemed an invitation,
 * an OIDC sign-in matched an existing account (task 13), or the account
 * holder linked it themselves (task 16).
 */
enum AttachReason: string
{
    case Invited = 'invited';
    case AutoLinked = 'auto_linked';
    case Linked = 'linked';
}
