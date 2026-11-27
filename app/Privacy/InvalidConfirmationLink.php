<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Privacy;

use RuntimeException;

/**
 * The confirmation link is unknown, used, expired, or its address is no longer
 * the one waiting on the account. One answer for every case: the page does
 * not tell which.
 */
final class InvalidConfirmationLink extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(__('account.email.link_invalid'));
    }
}
