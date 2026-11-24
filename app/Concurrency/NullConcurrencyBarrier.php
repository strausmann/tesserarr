<?php

namespace App\Concurrency;

final class NullConcurrencyBarrier implements ConcurrencyBarrier
{
    public function reached(string $point): void
    {
        //
    }
}
