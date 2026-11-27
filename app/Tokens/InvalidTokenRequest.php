<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Tokens;

use InvalidArgumentException;

/**
 * A token that cannot be issued as asked, for a reason other than missing
 * permissions (that is App\Authorization\PrivilegeEscalation). The message
 * is the reason code.
 */
final class InvalidTokenRequest extends InvalidArgumentException
{
    /** The issuer may not issue for this owner (only for themselves, a satellite never). */
    public const OWNER = 'owner';

    /** No permission chosen: a token names what it may do. */
    public const PERMISSIONS = 'permissions';

    /** Empty, too long or with control characters. */
    public const NAME = 'name';

    /** The expiry has passed. */
    public const EXPIRY = 'expiry';

    /**
     * @param  self::OWNER|self::PERMISSIONS|self::NAME|self::EXPIRY  $reason
     */
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
