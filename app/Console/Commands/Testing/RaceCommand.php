<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Console\Commands\Testing;

use App\Concurrency\AdvisoryConcurrencyBarrier;
use App\Concurrency\ConcurrencyBarrier;
use Illuminate\Console\Command;
use Illuminate\Support\Env;
use Throwable;

/**
 * One side of a forced race (tests/Support/AdvisoryBarrier.php).
 *
 * Runs a scenario class from tests/Support/RaceScenarios in this process
 * and prints its result as one JSON line. The command only exists in the
 * testing environment: outside it, isEnabled() keeps it out of the console.
 */
final class RaceCommand extends Command
{
    protected $signature = 'tesserarr:test:race
        {point : barrier point this child is bound to (TESSERARR_TEST_BARRIER_POINT)}
        {scenario : class name below Tests\\Support\\RaceScenarios}
        {arguments?* : passed to the scenario}';

    protected $description = 'Test only: run one side of a race at a concurrency barrier';

    public function isEnabled(): bool
    {
        return $this->laravel->environment('testing');
    }

    public function handle(ConcurrencyBarrier $barrier): int
    {
        if (! $barrier instanceof AdvisoryConcurrencyBarrier) {
            return $this->finish(false, error: 'the advisory barrier is not bound (TESSERARR_TEST_BARRIER=advisory missing)');
        }

        $point = $this->stringArgument('point');

        if (Env::get('TESSERARR_TEST_BARRIER_POINT') !== $point) {
            return $this->finish(false, error: "bound to another barrier point than \"{$point}\"");
        }

        $class = 'Tests\\Support\\RaceScenarios\\'.$this->stringArgument('scenario');

        if (! class_exists($class)) {
            return $this->finish(false, error: "unknown race scenario {$class}");
        }

        try {
            $result = $this->laravel->make($class)->run(array_values((array) $this->argument('arguments')));
        } catch (Throwable $e) {
            return $this->finish(false, error: $e::class.': '.$e->getMessage());
        }

        return $this->finish(true, $result);
    }

    private function stringArgument(string $name): string
    {
        $value = $this->argument($name);

        return is_string($value) ? $value : '';
    }

    private function finish(bool $ok, mixed $result = null, ?string $error = null): int
    {
        $this->line((string) json_encode(['ok' => $ok, 'result' => $result, 'error' => $error], JSON_UNESCAPED_SLASHES));

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
