<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Tests\Worker\Fixtures;

/**
 * Deliberately leaky, in a way only a guest sees: every signed-in user is
 * greeted by their own name, a guest by the name the static remembers. The
 * second user's own data covers such a leak; the guest request after them
 * does not.
 */
final class GuestGreeting
{
    private static ?string $first = null;

    private static ?string $last = null;

    public static function first(?string $name): string
    {
        self::$first ??= $name;

        return 'Hello '.($name ?? self::$first ?? 'guest');
    }

    public static function last(?string $name): string
    {
        self::$last = $name ?? self::$last;

        return 'Hello '.(self::$last ?? 'guest');
    }

    public static function forget(): void
    {
        self::$first = null;
        self::$last = null;
    }
}
