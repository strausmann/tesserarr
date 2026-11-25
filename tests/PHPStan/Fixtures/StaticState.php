<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

// Fixture of NoStaticPropertyRule and NoStaticVariableRule: analysed by
// PHPStan in the rule tests, never loaded.

namespace App\Fixtures\StaticState;

final class Leaky
{
    private static ?string $lastUser = null;

    public static int $hits = 0;

    public function remember(string $user): string
    {
        static $seen = [];
        $seen[] = $user;

        return self::$lastUser ??= $user;
    }
}

final class Justified
{
    // worker-safe: a process-wide counter of compiled templates, never request data
    private static int $compiled = 0;

    /** worker-safe: holds the class map of the image, identical for every request */
    private static array $classMap = [];

    public function count(): int
    {
        // worker-safe: memoises a pure function of its argument
        static $memo = [];

        return self::$compiled + count(self::$classMap) + count($memo);
    }
}

final class EmptyReason
{
    // worker-safe:
    private static ?string $tenant = null;
}

final class Clean
{
    public const LIMIT = 5;

    public function __construct(private readonly string $name) {}

    public function name(): string
    {
        return $this->name;
    }
}
