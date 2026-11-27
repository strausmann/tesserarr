<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Invitations;

/**
 * Access makes a new account; recovery binds a new sign-in method to an
 * existing one (Spec 9.1, 9.3), which keeps its role, grants and history.
 * Task 11 adds the setup invitation of the first run.
 */
enum InvitationKind: string
{
    case Access = 'access';
    case Recovery = 'recovery';
}
