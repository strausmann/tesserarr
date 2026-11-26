<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

// Fixture of NoGlobalVariableRule: analysed by PHPStan in the rule tests,
// never loaded.

namespace App\Fixtures\GlobalState;

final class UsesGlobals
{
    public function leak(): mixed
    {
        global $currentUser, $tenant;
        $currentUser = request()->user();

        return $tenant;
    }

    public function justified(): mixed
    {
        // worker-safe: reads the class map the image wrote at build time, never request data
        global $classMap;

        return $classMap;
    }
}
