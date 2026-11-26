<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Tests\Support\Worker;

use App\Authorization\Roles;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Application;
use PDO;

/**
 * Users as committed rows, for two-user tests of pages that read the user's
 * data from the database. The worker application has its own database
 * connection; rows a test writes inside its RefreshDatabase transaction are
 * invisible to it.
 *
 * boot() gives the $boot closure of OneWorker / assertNoStateLeakBetweenUsers():
 * it inserts the users (the ids of the in-memory users from workerUser()) on
 * the worker's connection before the first request and returns the cleanup
 * that deletes them after the last one. Further rows of a page (shares,
 * instances) are written and removed the same way in the page's own boot
 * closure. The tables must exist: CI migrates before Pest, and the Feature
 * suite migrates when it runs first.
 */
final class CommittedUsers
{
    /**
     * @return Closure(Application): (Closure(): void)
     */
    public static function boot(User ...$users): Closure
    {
        return self::withRole(null, ...$users);
    }

    /**
     * As boot(), and each user gets the built-in role "operator": pages of
     * the operator need an account whose role the Authorizer can read.
     *
     * @return Closure(Application): (Closure(): void)
     */
    public static function operators(User ...$users): Closure
    {
        return self::withRole(Roles::OPERATOR, ...$users);
    }

    /**
     * @return Closure(Application): (Closure(): void)
     */
    private static function withRole(?string $role, User ...$users): Closure
    {
        return static function (Application $app) use ($users, $role): Closure {
            // Kept for the cleanup: after the requests the database manager
            // points at the flushed sandbox of the last request.
            $connection = $app->make('db')->connection();
            $table = $connection->table('users');
            $ids = [];
            foreach ($users as $user) {
                $ids[] = $user->getKey();
                $table->insert([
                    'id' => $user->getKey(),
                    'username' => $user->getAttribute('username'),
                    'display_name' => $user->getAttribute('display_name'),
                    // Raw: the address is ciphertext already (workerUser()).
                    'email' => $user->getAttributes()['email'] ?? null,
                    'email_confirmed_at' => $user->getAttributes()['email_confirmed_at'] ?? null,
                    'password' => 'not-a-password-hash',
                ]);
                if ($role !== null) {
                    $connection->table('model_has_roles')->insert([
                        'role_id' => $connection->table('roles')->where(['name' => $role, 'guard_name' => Roles::GUARD])->value('id'),
                        'model_type' => $user->getMorphClass(),
                        'model_id' => $user->getKey(),
                    ]);
                }
            }

            return static function () use ($connection, $ids): void {
                $connection->table('model_has_roles')->whereIn('model_id', $ids)->where('model_type', (new User)->getMorphClass())->delete();
                $connection->table('users')->whereIn('id', $ids)->delete();
            };
        };
    }

    /**
     * How many of these user rows are still in the database, read on a
     * connection of its own (the test has no application of its own here).
     *
     * @param  list<int>  $ids
     */
    public static function remaining(array $ids): int
    {
        $pdo = new PDO(
            sprintf('pgsql:host=%s;port=%s;dbname=%s', getenv('DB_HOST'), getenv('DB_PORT'), getenv('DB_DATABASE')),
            (string) getenv('DB_USERNAME'),
            (string) getenv('DB_PASSWORD'),
        );
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $statement = $pdo->prepare("select count(*) from users where id in ({$placeholders})");
        $statement->execute($ids);

        return (int) $statement->fetchColumn();
    }
}
