<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Audit;

use InvalidArgumentException;

/**
 * A value of the audit context looked like an e-mail address. The log names
 * people by id only and resolves names when it is shown (AuditPresenter,
 * task 8a), so the entry is not written. The message names the key, never
 * the value.
 */
final class PiiInAuditContext extends InvalidArgumentException
{
    public function __construct(public readonly string $path)
    {
        parent::__construct(sprintf('The audit context value at "%s" looks like an e-mail address; audit entries name people by id only.', $path));
    }
}
