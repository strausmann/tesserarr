<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\ConcurrencyTestCase;
use Tests\Support\Worker\StateLeak;
use Tests\Support\Worker\UserRequest;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature', 'Unit/Authorization');

// Races between child processes: they only see committed rows.
pest()->extend(ConcurrencyTestCase::class)
    ->use(DatabaseTruncation::class)
    ->in('Concurrency');

require_once __DIR__.'/Support/Routes.php';

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * A TCP port on 127.0.0.1 that nothing listens on: bound once to get a free
 * number from the kernel, then closed again.
 */
function closedLocalPort(): int
{
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);

    if ($socket === false) {
        throw new RuntimeException("cannot reserve a local port: {$errorMessage}");
    }

    $name = (string) stream_socket_get_name($socket, false);
    fclose($socket);

    return (int) substr($name, (int) strrpos($name, ':') + 1);
}

/**
 * Two users one after the other in ONE application instance, without a boot
 * in between, the way a FrankenPHP/Octane worker serves them. The second
 * user, and a guest after them, must not see anything of the first user.
 * Mandatory for every page and controller that loads user-specific data
 * (CONTRIBUTING.md, "Worker-safe code").
 *
 * Each closure returns a fresh UserRequest: the user, the request and the
 * strings only that user may see. $boot runs once on the booted worker
 * application, for fixtures; a closure it returns runs after the last request.
 * Pages with database rows use CommittedUsers::boot() for it. Details:
 * Tests\Support\Worker\StateLeak.
 *
 * @param  Closure(): UserRequest  $requestAsA
 * @param  Closure(): UserRequest  $requestAsB
 * @param  (Closure(Application): mixed)|null  $boot
 */
function assertNoStateLeakBetweenUsers(Closure $requestAsA, Closure $requestAsB, ?Closure $boot = null): void
{
    StateLeak::assertNone($requestAsA, $requestAsB, $boot);
}

/**
 * A signed-in user for OneWorker that exists only in memory: the worker
 * application has its own database connection, which cannot see rows a test
 * wrote inside its transaction. A page that asks the Authorizer (or signs out
 * an account it cannot find) needs the row committed: CommittedUsers.
 */
function workerUser(string $name, int $id = 0): User
{
    $user = new User;
    // Raw attributes: casting a date here would need a database connection.
    $user->setRawAttributes([
        'id' => $id !== 0 ? $id : crc32($name),
        'username' => strtolower(str_replace(' ', '.', $name)),
        'display_name' => $name,
        'email' => strtolower(str_replace(' ', '.', $name)).'@example.org',
        'email_verified_at' => '2026-01-01 00:00:00',
    ], true);
    $user->exists = true;

    return $user;
}
