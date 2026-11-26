<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Authorization;

use RuntimeException;

/** The built-in roles keep their name and permissions; they cannot be deleted. */
final class BuiltInRoleImmutable extends RuntimeException
{
    public function __construct(string $role)
    {
        parent::__construct(sprintf('The built-in role "%s" cannot be changed or deleted.', $role));
    }
}
