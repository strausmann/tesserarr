<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Tests\Support\RaceScenarios;

use App\Authorization\LastOperatorProtected;
use App\Authorization\RoleGuard;
use App\Authorization\Roles;
use App\Models\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\DB;

/**
 * Demotes the operator named in the first argument to the role "user"
 * through RoleGuard::assertKeepsAnOperator, which stops at the barrier point
 * `operators.check` after the change and before it counts the operators.
 *
 * The demotion runs inside a transaction of this scenario, which commits only
 * once the other side has counted as well or waits at the lock of the guard.
 * Without that hold, the first process often commits before the second one
 * counts (the barrier releases them one after the other), and the race test
 * would pass even without the lock. With it the outcome depends only on the
 * lock:
 *
 * - with the lock, the second side waits at it until the first commits,
 *   then counts no operator and is refused;
 * - without it, both count one operator (each sees only its own change) and
 *   both commit: no operator is left.
 */
final class Demote implements RaceScenario
{
    private const WAIT_SECONDS = 10.0;

    public function __construct(private readonly RoleGuard $guard) {}

    public function run(array $arguments): mixed
    {
        $user = User::query()->where('username', $arguments[0])->firstOrFail();
        $signal = self::signalConnection();
        $key = (int) Env::get('TESSERARR_TEST_BARRIER_KEY') + 1;

        DB::beginTransaction();

        try {
            $this->guard->assertKeepsAnOperator(fn () => $user->syncRoles([Roles::USER]));
        } catch (LastOperatorProtected) {
            DB::rollBack();

            return ['demoted' => false];
        }

        // "I have counted": a shared lock held on a connection of its own.
        $signal->select('select pg_advisory_lock_shared(?)', [$key]);

        $deadline = microtime(true) + self::WAIT_SECONDS;
        while (microtime(true) < $deadline && ! self::otherSideDone($signal, $key)) {
            usleep(10_000);
        }

        DB::commit();
        $signal->disconnect();

        return ['demoted' => true];
    }

    /** Both sides have counted, or the other one waits at a lock. */
    private static function otherSideDone(ConnectionInterface $signal, int $key): bool
    {
        $counted = (int) $signal->scalar(
            "select count(*) from pg_locks where locktype = 'advisory' and granted and mode = 'ShareLock' and classid = 0 and objid = ? and objsubid = 1",
            [$key],
        );
        $waiting = (int) $signal->scalar("select count(*) from pg_locks where locktype = 'advisory' and not granted");

        return $counted >= 2 || $waiting >= 1;
    }

    private static function signalConnection(): ConnectionInterface
    {
        config(['database.connections.race_signal' => config('database.connections.'.config('database.default'))]);
        DB::purge('race_signal');

        return DB::connection('race_signal');
    }
}
