<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Concurrency;

final class NullConcurrencyBarrier implements ConcurrencyBarrier
{
    public function reached(string $point): void
    {
        //
    }
}
