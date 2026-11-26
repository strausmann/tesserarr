<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Tests\Support\Worker\CommittedUsers;
use Tests\Support\Worker\UserRequest;

// Pages that read the user's rows from the database need the rows committed:
// the worker has its own database connection and cannot see a test
// transaction. CommittedUsers writes them while the worker boots and deletes
// them after the last request.

/**
 * @return Closure(Application): (Closure(): void)
 */
function profileFromDatabase(User ...$users): Closure
{
    $commit = CommittedUsers::boot(...$users);

    return function (Application $app) use ($commit): Closure {
        $app->make('router')->get('/_worker/name-from-database', fn (Request $request) => 'Hello '.User::query()
            ->whereKey($request->user()?->getAuthIdentifier())
            ->value('display_name'));

        return $commit($app);
    };
}

it('serves a page from committed rows of both users', function () {
    $alice = workerUser('Alice Database', 900_001);
    $bob = workerUser('Bob Database', 900_002);

    assertNoStateLeakBetweenUsers(
        fn () => UserRequest::as($alice, Request::create('/_worker/name-from-database'), 'Alice Database'),
        fn () => UserRequest::as($bob, Request::create('/_worker/name-from-database'), 'Bob Database'),
        profileFromDatabase($alice, $bob),
    );

    expect(CommittedUsers::remaining([900_001, 900_002]))->toBe(0);
});
