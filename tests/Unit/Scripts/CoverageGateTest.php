<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use Symfony\Component\Process\Process;

// scripts/coverage_gate.php is run as a process against a clover report and a
// throwaway project root, the way CI runs it after `pest --coverage-clover`.

/**
 * A project root with the given directories and a clover report.
 *
 * @param  list<string>  $dirs  directories to create below the root
 * @param  array<string, array{0: int, 1: int}>  $files  relative path => [statements, covered]
 * @return string the root
 */
function coverageFixture(array $dirs, array $files): string
{
    $root = sys_get_temp_dir().'/coverage-gate-'.bin2hex(random_bytes(4));
    foreach ($dirs as $dir) {
        mkdir($root.'/'.$dir, 0777, true);
    }
    if (! is_dir($root)) {
        mkdir($root);
    }

    $xml = '<?xml version="1.0" encoding="UTF-8"?><coverage generated="0"><project timestamp="0">';
    foreach ($files as $path => [$statements, $covered]) {
        $xml .= sprintf(
            '<file name="%s/%s"><class name="X"><metrics statements="%d" coveredstatements="%d"/></class>'
            .'<metrics loc="1" ncloc="1" classes="1" methods="1" coveredmethods="0" statements="%d" coveredstatements="%d" elements="%d" coveredelements="%d"/></file>',
            $root, $path, $statements, $covered, $statements, $covered, $statements, $covered,
        );
    }
    $xml .= '</project></coverage>';
    file_put_contents($root.'/clover.xml', $xml);

    return $root;
}

/**
 * @return array{exit: int, output: string}
 */
function runCoverageGate(string $root, string ...$paths): array
{
    $script = dirname(__DIR__, 3).'/scripts/coverage_gate.php';
    $process = new Process([PHP_BINARY, $script, '--root='.$root, $root.'/clover.xml', ...$paths]);
    $process->run();

    (new Process(['rm', '-rf', $root]))->run();

    return ['exit' => (int) $process->getExitCode(), 'output' => $process->getOutput().$process->getErrorOutput()];
}

it('fails when app/Authorization is below 90', function () {
    $root = coverageFixture(['app/Authorization'], [
        'app/Authorization/Authorizer.php' => [100, 95],
        'app/Authorization/RoleGuard.php' => [100, 80],
    ]);

    $result = runCoverageGate($root, 'app/Authorization:90');

    expect($result['exit'])->toBe(1)
        ->and($result['output'])->toContain('app/Authorization')
        ->and($result['output'])->toContain('87.5');
});

it('passes when app/Authorization meets 90', function () {
    $root = coverageFixture(['app/Authorization'], ['app/Authorization/Authorizer.php' => [10, 9]]);

    expect(runCoverageGate($root, 'app/Authorization:90')['exit'])->toBe(0);
});

it('fails when an existing configured path has no lines', function () {
    $root = coverageFixture(['app/Authorization', 'app/Runtime'], ['app/Runtime/Role.php' => [10, 10]]);
    file_put_contents($root.'/app/Authorization/Authorizer.php', "<?php\n");

    $result = runCoverageGate($root, 'app/Authorization:90');

    expect($result['exit'])->toBe(1)
        ->and($result['output'])->toContain('app/Authorization')
        ->and($result['output'])->toContain('no lines');
});

it('passes a directory that only holds declarations', function () {
    $root = coverageFixture(['app/Tokens'], ['app/Tokens/TokenIssuer.php' => [0, 0]]);
    file_put_contents($root.'/app/Tokens/TokenIssuer.php', "<?php\n\ninterface TokenIssuer {}\n");

    $result = runCoverageGate($root, 'app/Tokens:90');

    expect($result['exit'])->toBe(0)
        ->and($result['output'])->toContain('app/Tokens')
        ->and($result['output'])->toContain('only declarations');
});

it('skips a configured path whose directory does not exist yet', function () {
    $root = coverageFixture(['app/Runtime'], ['app/Runtime/Role.php' => [10, 10]]);

    $result = runCoverageGate($root, 'app/Catalog:85');

    expect($result['exit'])->toBe(0)
        ->and($result['output'])->toContain('app/Catalog')
        ->and($result['output'])->toContain('does not exist');
});

it('does not count a sibling directory with the same prefix', function () {
    $root = coverageFixture(['app/Mfa', 'app/MfaLegacy'], [
        'app/Mfa/Policy.php' => [10, 10],
        'app/MfaLegacy/Old.php' => [10, 0],
    ]);

    expect(runCoverageGate($root, 'app/Mfa:90')['exit'])->toBe(0);
});

it('passes with no configured path once the report is readable', function () {
    $root = coverageFixture([], []);

    expect(runCoverageGate($root)['exit'])->toBe(0);
});

it('stops with exit 2 on an unreadable report or a malformed path argument', function (string $argument) {
    $root = coverageFixture(['app/Runtime'], []);
    if ($argument === 'broken-report') {
        file_put_contents($root.'/clover.xml', '<coverage');
        $argument = 'app/Runtime:80';
    }

    expect(runCoverageGate($root, $argument)['exit'])->toBe(2);
})->with(['broken-report', 'app/Runtime', 'app/Runtime:abc', 'app/Runtime:101']);

it('passes its own self-test', function () {
    $process = new Process([PHP_BINARY, dirname(__DIR__, 3).'/scripts/coverage_gate.php', '--self-test']);
    $process->run();

    expect($process->getExitCode())->toBe(0);
});
