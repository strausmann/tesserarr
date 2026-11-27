<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Authorization;

/**
 * The ready-made selections of the token form and of `tesserarr:token:issue
 * --preset` (Spec 9.5). Each carries exactly the one permission its job
 * needs. `invitations` carries `invitations.create`, which the catalogue
 * counts as administrative (Permission::administrative()): Spec 9.5 names
 * "create invitations" as a preset, so it is the one deliberate exception;
 * every other administrative permission is on a token only when it is
 * ticked one by one. Satellite tokens are no user tokens; they come with the
 * satellites (task 18).
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
