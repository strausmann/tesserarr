<?php

namespace App\Concurrency;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Test-only barrier: at its one configured point it waits for a PostgreSQL
 * advisory lock that the test process holds, then releases it again at once.
 * Every other point passes straight through.
 *
 * Bound only in the testing environment (AppServiceProvider); see
 * tests/Support/AdvisoryBarrier.php for the side that holds the lock.
 */
final class AdvisoryConcurrencyBarrier implements ConcurrencyBarrier
{
    public function __construct(
        private readonly string $point,
        private readonly int $key,
    ) {
        if (! in_array($point, self::POINTS, true)) {
            throw new InvalidArgumentException("unknown barrier point \"{$point}\"");
        }
    }

    public function reached(string $point): void
    {
        if ($point !== $this->point) {
            return;
        }

        DB::select('select pg_advisory_lock(?)', [$this->key]);
        DB::select('select pg_advisory_unlock(?)', [$this->key]);
    }
}
