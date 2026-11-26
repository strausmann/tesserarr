<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Authorization;

use RuntimeException;

/** An actor tried to grant permissions they do not hold (or nobody may hold). */
final class PrivilegeEscalation extends RuntimeException
{
    /**
     * @param  list<Permission>  $permissions  the permissions that were refused
     */
    public function __construct(public readonly array $permissions, string $why = 'the actor does not hold')
    {
        parent::__construct(sprintf('Refused to grant %s: %s them.', implode(', ', Permission::values($permissions)), $why));
    }
}
