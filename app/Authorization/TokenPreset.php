<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Authorization;

/**
 * The ready-made selections of the token form and of `tesserarr:token:issue
 * --preset` (Spec 9.5). None carries an administrative permission: those are
 * only on a token when they are ticked one by one. Satellite tokens are no
 * user tokens; they come with the satellites (task 18).
 */
enum TokenPreset: string
{
    case ArrIntegration = 'arr';
    case InvitationIssuer = 'invitations';

    /** @return list<Permission> */
    public function permissions(): array
    {
        return match ($this) {
            self::ArrIntegration => [Permission::CatalogBrowse],
            self::InvitationIssuer => [Permission::InvitationsCreate],
        };
    }
}
