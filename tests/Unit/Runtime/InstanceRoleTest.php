<?php

use App\Runtime\InstanceRole;
use App\Runtime\ProcessRole;
use Tests\Support\EnvSnapshot;

beforeEach(function () {
    $this->env = (new EnvSnapshot)->set(['TESSERARR_ROLE' => null]);
});

afterEach(function () {
    $this->env->restore();
});

it('defaults to the central role', fn () => expect(InstanceRole::fromEnv())->toBe(InstanceRole::Central));

it('treats an empty role as the central default', function () {
    $this->env->set(['TESSERARR_ROLE' => '']);

    expect(InstanceRole::fromEnv())->toBe(InstanceRole::Central);
});

it('reads the satellite role', function () {
    $this->env->set(['TESSERARR_ROLE' => 'satellite']);

    expect(InstanceRole::fromEnv())->toBe(InstanceRole::Satellite);
});

it('refuses an unknown role', function () {
    putenv('TESSERARR_ROLE=hub');
    InstanceRole::fromEnv();
})->throws(InvalidArgumentException::class, 'TESSERARR_ROLE');

it('does not accept a role in another spelling', function () {
    $this->env->set(['TESSERARR_ROLE' => 'Satellite']);
    InstanceRole::fromEnv();
})->throws(InvalidArgumentException::class);

it('assigns every process role to exactly one instance role', function () {
    $byInstance = collect(ProcessRole::cases())
        ->groupBy(fn (ProcessRole $process) => $process->instanceRole()->value)
        ->map(fn ($processes) => $processes->map(fn (ProcessRole $p) => $p->value)->all())
        ->all();

    expect($byInstance)->toBe([
        'central' => ['web', 'worker', 'scheduler', 'reverb'],
        'satellite' => ['satellite'],
    ]);
});
