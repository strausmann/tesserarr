<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Invitations;

use InvalidArgumentException;

/**
 * An invitation that cannot be made as asked, named by the field at fault:
 * `days` (a recovery invitation lasts three days at most), `note` (too long
 * or with control characters) or `kind` (a recovery invitation without an
 * account, an access invitation with one).
 */
final class InvalidInvitationRequest extends InvalidArgumentException
{
    public const DAYS = 'days';

    public const NOTE = 'note';

    public const KIND = 'kind';

    public function __construct(public readonly string $field)
    {
        parent::__construct($field);
    }
}
