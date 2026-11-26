<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Tests\Support\RaceScenarios;

use Illuminate\Support\Facades\Artisan;

/** Runs `tesserarr:user:lock <username>` and reports its exit code. */
final class LockAccount implements RaceScenario
{
    public function run(array $arguments): mixed
    {
        return ['exit' => Artisan::call('tesserarr:user:lock', ['username' => $arguments[0]])];
    }
}
