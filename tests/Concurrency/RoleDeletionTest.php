<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Authorization\Permission;
use App\Models\User;
use Database\Seeders\RolesSeeder;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\InvokedProcess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Spatie\Permission\Models\Role;

/*
| A role is deleted while an account is being given that role. The deletion
| moves its members to the role "user", which the actor may only do with the
| permissions of "user". The member that arrives during the deletion must be
| part of that check: the deletion locks the role row, and the assignment
| (foreign key of model_has_roles) waits for it or is waited for.
|
| Order forced without a timing guess: the assignment stops at the barrier
| `operators.check` with its row written but not committed; the deletion is
| started and the test waits until it blocks on a row lock; then the
| assignment commits.
*/

beforeEach(fn () => (new RolesSeeder)->run());

afterEach(function () {
    DB::table('model_has_roles')->delete();
    DB::table('users')->delete();
    DB::table('roles')->whereNotIn('name', ['operator', 'user'])->delete();
    (new RolesSeeder)->run();
});

/** One `tesserarr:test:race` child, bound to the barrier point `operators.check`. */
function raceChild(int $key, string $scenario, array $args): InvokedProcess
{
    return Process::path(base_path())->timeout(60)->env([
        'APP_ENV' => 'testing',
        'TESSERARR_TEST_BARRIER' => 'advisory',
        'TESSERARR_TEST_BARRIER_POINT' => 'operators.check',
        'TESSERARR_TEST_BARRIER_KEY' => (string) $key,
    ])->start([PHP_BINARY, 'artisan', 'tesserarr:test:race', 'operators.check', $scenario, ...$args]);
}

function waitUntil(Closure $condition, string $what): void
{
    $deadline = microtime(true) + 10;
    while (! $condition()) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException("timed out waiting until {$what}");
        }
        usleep(20_000);
    }
}

function raceResult(ProcessResult $result): array
{
    $lines = array_values(array_filter(explode("\n", trim($result->output()))));

    return (array) json_decode((string) end($lines), true);
}

it('checks the member that is given the role while the role is deleted', function () {
    User::factory()->operator()->create(['username' => 'race-op']);
    Role::create(['name' => 'helpers', 'guard_name' => 'web'])->syncPermissions([Permission::AuditView->value]);
    Role::create(['name' => 'co-maintainer', 'guard_name' => 'web'])
        ->syncPermissions([Permission::RolesManage->value, Permission::AuditView->value]);
    User::factory()->withRole('co-maintainer')->create(['username' => 'race-actor']);
    User::factory()->withRole('user')->create(['username' => 'race-bob']);

    $key = crc32('role-deletion-race');
    config(['database.connections.race_lock' => config('database.connections.'.config('database.default'))]);
    $lock = DB::connection('race_lock');
    $lock->select('select pg_advisory_lock(?)', [$key]);

    try {
        $assign = raceChild($key, 'AssignRole', ['race-bob', 'helpers']);
        waitUntil(fn () => (int) $lock->scalar("select count(*) from pg_locks where locktype = 'advisory' and not granted and objid = ?", [$key]) === 1, 'the assignment waits at the barrier');

        $delete = raceChild($key, 'DeleteRole', ['race-actor', 'helpers']);
        waitUntil(fn () => (int) $lock->scalar("select count(*) from pg_locks where locktype <> 'advisory' and not granted") >= 1, 'the deletion waits for the assignment');
    } finally {
        $lock->select('select pg_advisory_unlock(?)', [$key]);
    }

    $assigned = raceResult($assign->wait());
    $deleted = raceResult($delete->wait());
    $lock->disconnect();

    expect($assigned['ok'] ?? null)->toBeTrue(json_encode($assigned))
        ->and($deleted['ok'] ?? null)->toBeTrue(json_encode($deleted))
        ->and($deleted['result']['deleted'] ?? null)->toBeFalse()
        ->and((string) ($deleted['result']['refused'] ?? ''))->toContain('tokens.manage_own')
        ->and(Role::query()->where('name', 'helpers')->exists())->toBeTrue()
        ->and(User::query()->where('username', 'race-bob')->sole()->getRoleNames()->all())->toBe(['helpers']);
});
