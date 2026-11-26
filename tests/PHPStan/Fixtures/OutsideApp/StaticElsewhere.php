<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

// Fixture: code outside the App namespace is not checked.

namespace Database\Seeders\Fixture;

final class StaticElsewhere
{
    private static ?string $cache = null;

    public function get(): ?string
    {
        static $x = 1;

        return self::$cache;
    }
}
