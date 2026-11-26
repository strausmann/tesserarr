<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Authorization\Roles;
use App\Models\User;
use Database\Seeders\RolesSeeder;
use Illuminate\Support\Facades\DB;
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
