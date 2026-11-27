<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Privacy;

use RuntimeException;

/**
 * The address is the account address of another account, confirmed or
 * reserved. The message says so without naming the other account: a
 * deliberate answer of the operator (Spec 16.16), throttled per user
 * (EmailConfirmation::CHECKS_PER_HOUR).
 */
final class EmailAlreadyClaimed extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(__('account.email.taken'));
    }
}
