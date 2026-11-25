<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Tests\Support\RaceScenarios;

use App\Concurrency\ConcurrencyBarrier;

/**
 * Reaches the barrier point given as the first argument and reports which
 * barrier the child process was bound to. Used to test the barrier itself.
 */
final class ReachPoint implements RaceScenario
{
    public function __construct(private readonly ConcurrencyBarrier $barrier) {}

    public function run(array $arguments): mixed
    {
        $this->barrier->reached($arguments[0]);

        return ['barrier' => $this->barrier::class];
    }
}
