<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Authorization;

use RuntimeException;

/** The change would leave no active operator (role operator, not locked). */
final class LastOperatorProtected extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The change would remove the last active operator; promote another account first.');
    }
}
