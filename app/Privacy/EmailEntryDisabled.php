<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Privacy;

use RuntimeException;

/** No account address can be entered now (AccountEmailPolicy). */
final class EmailEntryDisabled extends RuntimeException
{
    /** @param  'switched_off'|'no_transport'|'confirmed_exists'  $reason */
    public function __construct(public readonly string $reason)
    {
        parent::__construct(__('account.email.disabled.'.$reason));
    }
}
