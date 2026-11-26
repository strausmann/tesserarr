<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Authorization\Roles;
use App\Models\User;
use Database\Seeders\RolesSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Tests\Support\AdvisoryBarrier;

/*
| Two operators demote each other at the same moment. Both changes are made
| before either process counts the operators (barrier `operators.check`); the
| advisory lock in RoleGuard::assertKeepsAnOperator must serialise the counts,
| so exactly one demotion wins and one operator stays.
*/

beforeEach(fn () => (new RolesSeeder)->run());

// The rows are committed: remove them again, so the suites after this one
// (Worker commits accounts of its own) start from the migrated state.
afterEach(function () {
    DB::table('model_has_roles')->delete();
    DB::table('users')->delete();
    (new RolesSeeder)->run();
});

it('keeps one operator when two operators demote each other at the same time', function () {
    User::factory()->operator()->create(['username' => 'race-alice']);
    User::factory()->operator()->create(['username' => 'race-bob']);

    $results = AdvisoryBarrier::race(2, [
        ['point' => 'operators.check', 'scenario' => 'Demote', 'args' => ['race-bob']],
        ['point' => 'operators.check', 'scenario' => 'Demote', 'args' => ['race-alice']],
    ]);

    $operators = DB::table('model_has_roles')
        ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
        ->where('roles.name', Roles::OPERATOR)
        ->count();

    expect(array_column($results, 'ok'))->toBe([true, true], json_encode($results))
        ->and(collect($results)->pluck('result.demoted')->sort()->values()->all())->toBe([false, true])
        ->and($operators)->toBe(1);
});

it('keeps the first operator when the account is locked while it is being promoted', function () {
    // A fresh installation: no operator yet. One side names the first
    // operator, the other locks the same account at the same moment. The
    // lock must count the promotion (it waits for the account), so it is
    // refused instead of leaving no active operator.
    User::factory()->withRole(Roles::USER)->create(['username' => 'race-first']);

    $key = crc32('first-operator-lock-race');
    config(['database.connections.race_lock' => config('database.connections.'.config('database.default'))]);
    $lock = DB::connection('race_lock');
    $lock->select('select pg_advisory_lock(?)', [$key]);
    $child = fn (string $scenario) => Process::path(base_path())->timeout(60)->env([
        'APP_ENV' => 'testing',
        'TESSERARR_TEST_BARRIER' => 'advisory',
        'TESSERARR_TEST_BARRIER_POINT' => 'operators.check',
        'TESSERARR_TEST_BARRIER_KEY' => (string) $key,
    ])->start([PHP_BINARY, 'artisan', 'tesserarr:test:race', 'operators.check', $scenario, ...($scenario === 'AssignRole' ? ['race-first', Roles::OPERATOR] : ['race-first'])]);
    $waits = fn (string $sql) => (int) $lock->scalar($sql, [$key]);

    try {
        $promote = $child('AssignRole');
        $deadline = microtime(true) + 10;
        while ($waits("select count(*) from pg_locks where locktype = 'advisory' and not granted and objid = ?") < 1 && microtime(true) < $deadline) {
            usleep(20_000);
        }

        $lockIt = $child('LockAccount');
        $deadline = microtime(true) + 10;
        while ($waits("select count(*) from pg_locks where locktype <> 'advisory' and not granted and ? > 0") < 1 && microtime(true) < $deadline) {
            usleep(20_000);
        }
    } finally {
        $lock->select('select pg_advisory_unlock(?)', [$key]);
    }

    $promoted = $promote->wait();
    $locked = $lockIt->wait();
    $lock->disconnect();

    $first = User::query()->where('username', 'race-first')->sole();
    expect($promoted->successful())->toBeTrue($promoted->output())
        ->and($first->getRoleNames()->all())->toBe([Roles::OPERATOR])
        ->and($first->locked_at)->toBeNull($locked->output());
});
