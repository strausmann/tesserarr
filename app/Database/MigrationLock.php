<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Database;

/**
 * The lock `artisan tesserarr:migrate` holds while it checks, backs up and
 * migrates, so two migrate containers never do it at the same time.
 */
interface MigrationLock
{
    /**
     * Waits up to $timeoutSeconds; false when another run still holds the lock.
     */
    public function acquire(int $timeoutSeconds): bool;

    public function release(): void;
}
