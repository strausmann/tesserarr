<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Runtime;

use Illuminate\Support\Env;
use InvalidArgumentException;

/**
 * What this installation is: the central instance with users, catalogue and
 * database, or a satellite that only executes jobs next to a media share.
 *
 * Read straight from the process environment, not from config, because the
 * role decides which configuration and routes exist in the first place.
 */
enum InstanceRole: string
{
    case Central = 'central';
    case Satellite = 'satellite';

    public const ENV = 'TESSERARR_ROLE';

    /**
     * @throws InvalidArgumentException on any value other than "central" or "satellite"
     */
    public static function fromEnv(): self
    {
        $value = Env::get(self::ENV);

        if ($value === null || $value === '') {
            return self::Central;
        }

        return self::tryFrom((string) $value) ?? throw new InvalidArgumentException(sprintf(
            '%s must be "central" or "satellite", got "%s"',
            self::ENV,
            (string) $value,
        ));
    }
}
