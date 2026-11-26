<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Settings;

use InvalidArgumentException;

/** A setting key that is not a dotted lower-case name such as "email.allowed". */
final class UnknownSetting extends InvalidArgumentException
{
    public function __construct(string $key)
    {
        parent::__construct(sprintf('"%s" is not a valid setting key (lower-case words joined by dots).', $key));
    }
}
