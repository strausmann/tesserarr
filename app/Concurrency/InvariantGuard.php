<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Concurrency;

use Illuminate\Database\ConnectionInterface;
use Throwable;

/**
 * Runs a change in a transaction and keeps an invariant that spans rows, such
 * as "at least one active operator" (RoleGuard) or "at least one sign-in
 * method" (task 16, pre-flight D6).
 *
 * Order inside the transaction: the change, then the transaction-level
 * advisory lock on $key, then the check. The lock serialises check and
 * commit across processes: a second process that made its change waits at the
 * lock until the first has committed, and its check then reads that commit
 * (read committed). A violated check rolls the change back and throws
 * $onViolation.
 *
 * The change runs before the lock on purpose: a barrier the change passes
 * (ConcurrencyBarrier) can then hold two processes after their changes and
 * before their checks, which is the window the lock has to close. Taking the
 * lock first would hide that window from the race test.
 */
final class InvariantGuard
{
    public function __construct(private readonly ConnectionInterface $db) {}

    /**
     * @template T
     *
     * @param  callable(): T  $change
     * @param  callable(T): bool  $check  true while the invariant holds; gets what the change returned
     * @return T
     */
    public function underLock(string $key, callable $change, callable $check, Throwable $onViolation): mixed
    {
        return $this->db->transaction(function () use ($key, $change, $check, $onViolation) {
            $result = $change();

            $this->db->select('select pg_advisory_xact_lock(hashtext(?))', [$key]);

            if (! $check($result)) {
                throw $onViolation;
            }

            return $result;
        });
    }
}
