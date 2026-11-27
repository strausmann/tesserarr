<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Privacy;

use RuntimeException;

/** More than EmailConfirmation::CHECKS_PER_HOUR addresses were checked by one user within the hour. */
final class EmailClaimThrottled extends RuntimeException
{
    public function __construct(public readonly int $retryAfter)
    {
        parent::__construct(__('account.email.throttled', ['minutes' => (string) max(1, (int) ceil($retryAfter / 60))]));
    }
}
