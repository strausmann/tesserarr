<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use Symfony\Component\Process\Process;

// The gate is run as a process, the way CI runs it: exit code and output are
// the contract (scripts/licence_gate.php, CONTRIBUTING.md "Dependencies and
// licences"). The inputs imitate `composer licenses --format=json` and
// `license-checker-rseidelsohn --json`; the policy is the real file, so a
// change to licence-policy.yml is judged by these tests too.

/**
 * @param  array<string, list<string>>  $composer  name => licence list
 * @param  array<string, string|list<string>|null>  $npm  name@version => licences (null: field missing)
 * @param  array<string, mixed>|null  $policy  null: the repository's licence-policy.yml
 * @return array{exit: int, output: string}
 */
function runLicenceGate(array $composer = [], array $npm = [], ?array $policy = null): array
{
    $root = dirname(__DIR__, 3);
    $dir = sys_get_temp_dir().'/licence-gate-'.bin2hex(random_bytes(4));
    mkdir($dir);

    $dependencies = [];
    foreach ($composer as $name => $licences) {
        $dependencies[$name] = ['version' => '1.0.0', 'license' => $licences];
    }
    file_put_contents($dir.'/composer.json', json_encode([
        'name' => 'strausmann/tesserarr',
        'version' => 'dev-main',
        'license' => ['AGPL-3.0-or-later'],
        'dependencies' => (object) $dependencies,
    ]));

    $packages = [];
    foreach ($npm as $id => $licences) {
        $packages[$id] = $licences === null ? ['path' => '/x'] : ['licenses' => $licences, 'path' => '/x'];
    }
    file_put_contents($dir.'/npm.json', json_encode((object) $packages));

    $policyFile = $root.'/licence-policy.yml';
    if ($policy !== null) {
        $policyFile = $dir.'/policy.yml';
        file_put_contents($policyFile, json_encode($policy));
    }

    $process = new Process([PHP_BINARY, $root.'/scripts/licence_gate.php', $dir.'/composer.json', $dir.'/npm.json', $policyFile]);
    $process->run();

    array_map('unlink', glob($dir.'/*') ?: []);
    rmdir($dir);

    return ['exit' => (int) $process->getExitCode(), 'output' => $process->getOutput().$process->getErrorOutput()];
}

it('fails on a GPL-2.0-only package', function () {
    $result = runLicenceGate(composer: ['acme/old-gpl' => ['GPL-2.0-only'], 'acme/fine' => ['MIT']]);

    expect($result['exit'])->toBe(1)
        ->and($result['output'])->toContain('acme/old-gpl@1.0.0')
        ->and($result['output'])->toContain('GPL-2.0-only')
        ->and($result['output'])->not->toContain('acme/fine');
});

it('passes a GPL-3.0-or-later npm dependency', function () {
    $result = runLicenceGate(npm: ['copyleft-lib@2.0.0' => 'GPL-3.0-or-later']);

    expect($result['exit'])->toBe(0);
});

it('fails on an SSPL-1.0 package', function () {
    $result = runLicenceGate(npm: ['server-side@1.0.0' => 'SSPL-1.0']);

    expect($result['exit'])->toBe(1)
        ->and($result['output'])->toContain('server-side@1.0.0')
        ->and($result['output'])->toContain('SSPL-1.0');
});

it('fails on a package without licence', function (array $composer, array $npm, string $name) {
    $result = runLicenceGate(composer: $composer, npm: $npm);

    expect($result['exit'])->toBe(1)
        ->and($result['output'])->toContain($name)
        ->and($result['output'])->toContain('no licence');
})->with([
    'composer, empty list' => [['acme/none' => []], [], 'acme/none@1.0.0'],
    'npm, field missing' => [[], ['bare@1.0.0' => null], 'bare@1.0.0'],
    'npm, UNKNOWN' => [[], ['unknown@1.0.0' => 'UNKNOWN'], 'unknown@1.0.0'],
    'npm, UNLICENSED' => [[], ['closed@1.0.0' => 'UNLICENSED'], 'closed@1.0.0'],
]);

it('passes an MIT/Apache-2.0 tree', function () {
    $result = runLicenceGate(
        composer: ['acme/a' => ['MIT'], 'acme/b' => ['Apache-2.0']],
        npm: ['c@1.0.0' => 'MIT', 'd@1.0.0' => 'Apache-2.0', '@scope/e@1.0.0' => 'MIT'],
    );

    expect($result['exit'])->toBe(0)
        ->and($result['output'])->toContain('5 packages');
});

it('treats an OR expression as allowed when one side is allowed', function (array $composer, array $npm) {
    expect(runLicenceGate(composer: $composer, npm: $npm)['exit'])->toBe(0);
})->with([
    'composer list is a choice' => [['acme/dual' => ['BSD-3-Clause', 'GPL-2.0-only', 'GPL-3.0-only']], []],
    'npm expression' => [[], ['dual@1.0.0' => '(MIT OR SSPL-1.0)']],
]);

it('fails an AND expression when one side is refused', function () {
    $result = runLicenceGate(npm: ['mixed@1.0.0' => '(MIT AND SSPL-1.0)']);

    expect($result['exit'])->toBe(1)
        ->and($result['output'])->toContain('mixed@1.0.0');
});

it('fails an npm licence list when one entry is missing or refused', function (array $licences) {
    $result = runLicenceGate(npm: ['listed@1.0.0' => $licences]);

    expect($result['exit'])->toBe(1)
        ->and($result['output'])->toContain('listed@1.0.0');
})->with([
    'MIT and UNKNOWN' => [['MIT', 'UNKNOWN']],
    'MIT and UNLICENSED' => [['MIT', 'UNLICENSED']],
    'MIT and GPL-2.0-only' => [['MIT', 'GPL-2.0-only']],
]);

it('accepts a package without licence when a reviewed exception names it', function () {
    $policy = [
        'allow-licenses' => ['MIT'],
        'x-licence-exceptions' => [
            ['purl' => 'pkg:npm/bare@1.0.0', 'licence' => 'MIT', 'reason' => 'Licence file is the MIT text; package.json has no field.'],
        ],
    ];

    expect(runLicenceGate(npm: ['bare@1.0.0' => null], policy: $policy)['exit'])->toBe(0)
        ->and(runLicenceGate(npm: ['bare@1.0.1' => null], policy: $policy)['exit'])->toBe(1);
});

it('refuses an exception whose licence is not on the allow list', function () {
    $policy = [
        'allow-licenses' => ['MIT'],
        'x-licence-exceptions' => [
            ['purl' => 'pkg:npm/bare@1.0.0', 'licence' => 'SSPL-1.0', 'reason' => 'wrong'],
        ],
    ];

    $result = runLicenceGate(npm: ['bare@1.0.0' => null], policy: $policy);

    expect($result['exit'])->toBe(2)
        ->and($result['output'])->toContain('pkg:npm/bare@1.0.0');
});

it('keeps the allow list of the policy free of licences that are incompatible with AGPL-3.0', function () {
    $policy = json_decode((string) file_get_contents(dirname(__DIR__, 3).'/licence-policy.yml'), true);

    expect($policy['allow-licenses'])->toContain('AGPL-3.0-or-later', 'GPL-3.0-or-later', 'MIT')
        ->not->toContain('GPL-2.0-only', 'LGPL-2.0-only', 'SSPL-1.0');
});

it('passes its own self-test', function () {
    $process = new Process([PHP_BINARY, dirname(__DIR__, 3).'/scripts/licence_gate.php', '--self-test']);
    $process->run();

    expect($process->getExitCode())->toBe(0);
});
