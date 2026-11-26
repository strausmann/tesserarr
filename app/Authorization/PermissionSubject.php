<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Authorization;

/**
 * An owner the Authorizer can decide for: a user now, a satellite from task
 * 18 (pre-flight K7). Both methods read the current state from the database,
 * never from a loaded relation: a demotion or a lock takes effect on the next
 * check, not on the next login.
 */
interface PermissionSubject
{
    /** @return list<Permission> */
    public function grantedPermissions(): array;

    /** Locked (a user) or revoked (a satellite). */
    public function isBlocked(): bool;
}
