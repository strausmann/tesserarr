<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Database;

use Illuminate\Database\ConnectionInterface;

/**
 * A PostgreSQL session advisory lock. `migrate --isolated` cannot take this
 * role: its lock lives in the cache table the first migration creates, so it
 * fails on a fresh installation with the database cache store. The lock falls
 * by itself when the session ends.
 */
final class PostgresMigrationLock implements MigrationLock
{
    /** "tesserarr:migrate" as a fixed 64-bit number. */
    public const KEY = 7_514_731_002_316_401_517;

    public function __construct(private readonly ConnectionInterface $connection) {}

    /**
     * Polls pg_try_advisory_lock instead of waiting in pg_advisory_lock with a
     * lock_timeout: a timeout error would abort an enclosing transaction.
     */
    public function acquire(int $timeoutSeconds): bool
    {
        $deadline = microtime(true) + max(1, $timeoutSeconds);
        do {
            $row = $this->connection->selectOne('select pg_try_advisory_lock(?) as locked', [self::KEY]);
            if ((bool) ($row->locked ?? false)) {
                return true;
            }
            usleep(200_000);
        } while (microtime(true) < $deadline);

        return false;
    }

    public function release(): void
    {
        $this->connection->select('select pg_advisory_unlock(?)', [self::KEY]);
    }
}
