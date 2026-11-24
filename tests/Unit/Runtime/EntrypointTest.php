<?php

use App\Runtime\InstanceRole;
use App\Runtime\ProcessRole;
use Symfony\Component\Process\Process;

function runEntrypoint(array $arguments, ?string $role): Process
{
    $process = new Process(
        ['sh', dirname(__DIR__, 3).'/docker/entrypoint.sh', ...$arguments],
        env: ['TESSERARR_ROLE' => $role ?? false],
    );
    $process->run();

    return $process;
}

it('refuses every process role on an instance of the other role', function (ProcessRole $process) {
    $wrong = $process->instanceRole() === InstanceRole::Central ? InstanceRole::Satellite : InstanceRole::Central;

    $result = runEntrypoint([$process->value], $wrong->value);

    expect($result->getExitCode())->toBe(64)
        ->and($result->getErrorOutput())->toContain("process role '{$process->value}' needs TESSERARR_ROLE={$process->instanceRole()->value}");
})->with(ProcessRole::cases());

it('treats an unset TESSERARR_ROLE as central', function () {
    $result = runEntrypoint([ProcessRole::Satellite->value], null);

    expect($result->getExitCode())->toBe(64)
        ->and($result->getErrorOutput())->toContain('needs TESSERARR_ROLE=satellite');
});

it('rejects an unknown command with exit code 64', function () {
    $result = runEntrypoint(['hub'], 'central');

    expect($result->getExitCode())->toBe(64)
        ->and($result->getErrorOutput())->toContain("unknown command 'hub'");
});
