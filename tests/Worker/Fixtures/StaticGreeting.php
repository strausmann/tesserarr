<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Tests\Worker\Fixtures;

/**
 * Deliberately leaky: a static cache keeps the first name it saw for every
 * later request of the worker. The self-test of the leak helper expects it
 * to fail.
 */
final class StaticGreeting
{
    private static ?string $name = null;

    public static function for(?string $name): string
    {
        return 'Hello '.(self::$name ??= $name ?? 'guest');
    }

    public static function forget(): void
    {
        self::$name = null;
    }
}
