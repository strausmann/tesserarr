<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Authorization;

/**
 * The answer of the Authorizer: allowed or not, and why. The reason is
 * returned with a refused gate (`message` of the 403), so a test can tell
 * which condition refused.
 */
final readonly class AuthorizationDecision
{
    public const OK = 'ok';

    public const OWNER_LACKS_PERMISSION = 'owner_lacks_permission';

    public const TOKEN_LACKS_PERMISSION = 'token_lacks_permission';

    public const LOCKED = 'locked';

    public const REVOKED = 'revoked';

    public const TOKEN_MISSING = 'token_missing';

    /**
     * @param  self::OK|self::OWNER_LACKS_PERMISSION|self::TOKEN_LACKS_PERMISSION|self::LOCKED|self::REVOKED|self::TOKEN_MISSING  $reason
     */
    private function __construct(
        public bool $allowed,
        public string $reason,
    ) {}

    public static function allow(): self
    {
        return new self(true, self::OK);
    }

    /**
     * @param  self::OWNER_LACKS_PERMISSION|self::TOKEN_LACKS_PERMISSION|self::LOCKED|self::REVOKED|self::TOKEN_MISSING  $reason
     */
    public static function deny(string $reason): self
    {
        return new self(false, $reason);
    }
}
