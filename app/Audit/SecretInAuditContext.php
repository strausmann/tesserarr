<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Audit;

use InvalidArgumentException;

/**
 * The context of an audit entry contained a key that names a secret. The log
 * never holds secrets (ADR-0009 point 6), so the entry is not written. The
 * message names the key, never its value.
 */
final class SecretInAuditContext extends InvalidArgumentException
{
    public function __construct(public readonly string $path)
    {
        parent::__construct(sprintf('The audit context key "%s" looks like a secret; audit entries never carry secrets.', $path));
    }
}
