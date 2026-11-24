<?php

namespace Tests\Support;

use Closure;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Process\InvokedProcessPool;
use Illuminate\Process\Pool;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Pest\TestSuite;

/**
 * Forces a race window between child processes.
 *
 * The parent takes a PostgreSQL advisory lock on its own connection, starts
 * the children (`php artisan tesserarr:test:race …`) without waiting for
 * them, and polls pg_locks until $n children block on that lock at the named
 * barrier point. Then it releases the lock, so all of them continue at the
 * same moment, and collects their results.
 *
 * Concurrency::run() cannot be used here: it only returns after every child
 * has finished (ProcessDriver: ->start()->wait()), so the parent could never
 * release the lock the children wait for.
 */
final class AdvisoryBarrier
{
    public const TIMEOUT_SECONDS = 10.0;

    private const CONNECTION = 'advisory_barrier';

    /**
     * @param  int  $n  number of children that must block at the point before the lock is released
     * @param  list<array{point: string, scenario: string, args?: list<string>}>|Closure(int): array{point: string, scenario: string, args?: list<string>}  $tasks  one entry per child; a closure is called for 0 … $n-1
     * @param  int|null  $key  advisory lock key; defaults to crc32 of the running test's name so parallel tests never count each other's waiters
     * @return list<array{exit: int|null, ok: bool, result: mixed, error: string|null, output: string}>
     *
     * @throws RaceTimedOut when fewer than $n children reach the point within the timeout
     */
    public static function race(int $n, array|Closure $tasks, ?int $key = null, float $timeout = self::TIMEOUT_SECONDS): array
    {
        $specs = $tasks instanceof Closure ? array_map($tasks, range(0, $n - 1)) : array_values($tasks);
        $key ??= crc32(TestSuite::getInstance()->test->name());

        $lock = self::lockConnection();
        $lock->select('select pg_advisory_lock(?)', [$key]);

        $waiting = 0;
        $timedOut = false;

        try {
            $pool = self::start($specs, $key);

            $deadline = microtime(true) + $timeout;

            while (($waiting = self::waiters($lock, $key)) < $n) {
                if (microtime(true) >= $deadline) {
                    $timedOut = true;
                    break;
                }

                usleep(20_000);
            }
        } finally {
            $lock->select('select pg_advisory_unlock(?)', [$key]);
        }

        $results = $pool->wait();

        $lock->disconnect();
        DB::purge(self::CONNECTION);

        if ($timedOut) {
            throw new RaceTimedOut(sprintf('only %d of %d children reached the barrier within %.0f seconds', $waiting, $n, $timeout));
        }

        return array_map(self::result(...), array_values($results->collect()->all()));
    }

    private static function lockConnection(): ConnectionInterface
    {
        config(['database.connections.'.self::CONNECTION => config('database.connections.'.config('database.default'))]);
        DB::purge(self::CONNECTION);

        return DB::connection(self::CONNECTION);
    }

    /**
     * @param  list<array{point: string, scenario: string, args?: list<string>}>  $specs
     */
    private static function start(array $specs, int $key): InvokedProcessPool
    {
        return Process::pool(function (Pool $pool) use ($specs, $key) {
            foreach ($specs as $index => $spec) {
                $pool->as((string) $index)
                    ->path(base_path())
                    ->timeout(60)
                    ->env([
                        'APP_ENV' => 'testing',
                        'TESSERARR_TEST_BARRIER' => 'advisory',
                        'TESSERARR_TEST_BARRIER_POINT' => $spec['point'],
                        'TESSERARR_TEST_BARRIER_KEY' => (string) $key,
                    ])
                    ->command([PHP_BINARY, 'artisan', 'tesserarr:test:race', $spec['point'], $spec['scenario'], ...($spec['args'] ?? [])]);
            }
        })->start();
    }

    /**
     * Sessions blocked on this key. A key below 2^32 is stored with
     * classid 0, objid = key and objsubid 1 (single bigint form).
     */
    private static function waiters(ConnectionInterface $connection, int $key): int
    {
        return (int) $connection->scalar(
            "select count(*) from pg_locks where locktype = 'advisory' and not granted and classid = 0 and objid = ? and objsubid = 1",
            [$key],
        );
    }

    /**
     * @return array{exit: int|null, ok: bool, result: mixed, error: string|null, output: string}
     */
    private static function result(ProcessResult $process): array
    {
        $lines = array_values(array_filter(explode("\n", trim($process->output()))));
        $payload = json_decode((string) end($lines), true);

        return [
            'exit' => $process->exitCode(),
            'ok' => $process->successful() && is_array($payload) && ($payload['ok'] ?? false) === true,
            'result' => is_array($payload) ? ($payload['result'] ?? null) : null,
            'error' => is_array($payload) ? ($payload['error'] ?? null) : trim($process->errorOutput()),
            'output' => $process->output(),
        ];
    }
}
