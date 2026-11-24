<?php

use App\Concurrency\AdvisoryConcurrencyBarrier;
use App\Concurrency\ConcurrencyBarrier;
use App\Concurrency\NullConcurrencyBarrier;
use Illuminate\Support\Facades\Process;
use Tests\Support\AdvisoryBarrier;
use Tests\Support\EnvSnapshot;
use Tests\Support\RaceTimedOut;

beforeEach(function () {
    $this->env = (new EnvSnapshot)->set([
        'TESSERARR_TEST_BARRIER' => null,
        'TESSERARR_TEST_BARRIER_POINT' => null,
        'TESSERARR_TEST_BARRIER_KEY' => null,
    ]);
});

afterEach(function () {
    $this->env->restore();
});

function resolveBarrier(): ConcurrencyBarrier
{
    app()->forgetInstance(ConcurrencyBarrier::class);

    return app(ConcurrencyBarrier::class);
}

it('binds the null barrier by default', function () {
    expect(resolveBarrier())->toBeInstanceOf(NullConcurrencyBarrier::class);
});

it('binds the advisory barrier in the testing environment when asked to', function () {
    $this->env->set([
        'TESSERARR_TEST_BARRIER' => 'advisory',
        'TESSERARR_TEST_BARRIER_POINT' => 'invitation.consume',
        'TESSERARR_TEST_BARRIER_KEY' => '42',
    ]);

    expect(resolveBarrier())->toBeInstanceOf(AdvisoryConcurrencyBarrier::class);
});

it('ignores TESSERARR_TEST_BARRIER outside the testing environment', function () {
    $this->env->set([
        'TESSERARR_TEST_BARRIER' => 'advisory',
        'TESSERARR_TEST_BARRIER_POINT' => 'invitation.consume',
        'TESSERARR_TEST_BARRIER_KEY' => '42',
    ]);
    app()['env'] = 'production';

    expect(resolveBarrier())->toBeInstanceOf(NullConcurrencyBarrier::class);
});

it('refuses a barrier point outside the named list', function () {
    new AdvisoryConcurrencyBarrier('invitation.anything', 42);
})->throws(InvalidArgumentException::class, 'invitation.anything');

it('does not register the race command outside the testing environment', function () {
    $result = Process::path(base_path())
        ->env(['APP_ENV' => 'production'])
        ->run([PHP_BINARY, 'artisan', 'list', '--format=json']);

    $commands = collect(json_decode($result->output(), true)['commands'])->pluck('name');

    expect($result->successful())->toBeTrue()
        ->and($commands)->not->toContain('tesserarr:test:race')
        ->and($commands)->toContain('about');
});

it('stops at the named point only and times out the race helper after 10 seconds', function () {
    $reach = fn (string $point) => fn (int $i) => [
        'point' => 'invitation.consume',
        'scenario' => 'ReachPoint',
        'args' => [$point],
    ];

    // Named point: both children block on the advisory lock until the parent releases it.
    $results = AdvisoryBarrier::race(2, $reach('invitation.consume'));

    expect($results)->toHaveCount(2)
        ->and(array_column($results, 'ok'))->toBe([true, true])
        ->and(array_column(array_column($results, 'result'), 'barrier'))
        ->toBe([AdvisoryConcurrencyBarrier::class, AdvisoryConcurrencyBarrier::class]);

    // Any other point passes straight through, so nobody waits and the helper gives up.
    $started = microtime(true);

    expect(fn () => AdvisoryBarrier::race(2, $reach('invitation.reserve')))
        ->toThrow(RaceTimedOut::class, 'only 0 of 2 children');

    expect(microtime(true) - $started)->toBeGreaterThanOrEqual(10.0)->toBeLessThan(13.0);
});
