<?php

namespace Tests\Support\RaceScenarios;

/**
 * One side of a race, run in its own `php artisan tesserarr:test:race`
 * process. The return value must be JSON-encodable; the race helper reads it
 * back from the child's output.
 */
interface RaceScenario
{
    /**
     * @param  list<string>  $arguments
     */
    public function run(array $arguments): mixed;
}
