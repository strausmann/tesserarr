<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

/*
 * Licence gate: judges every Composer and npm runtime dependency against
 * licence-policy.yml. Plain PHP, no Composer autoloader, so it also runs on
 * main before the application exists.
 *
 *   composer licenses --format=json --no-dev --locked > composer-licences.json
 *   npx --yes license-checker-rseidelsohn@5.0.1 --production --excludePrivatePackages --json > npm-licences.json
 *   php scripts/licence_gate.php composer-licences.json npm-licences.json licence-policy.yml
 *   php scripts/licence_gate.php --self-test
 *
 * Exit 0: every package allowed. Exit 1: refused packages, one per line.
 * Exit 2: unusable input or policy (including an exception whose licence is
 * not allowed itself).
 *
 * Rules:
 * - A Composer licence list is a choice (composer.json schema: "multiple can be
 *   specified as an array" for a disjunctive licence), so one allowed entry suffices.
 * - An npm licence string is an SPDX expression. A list (old package.json
 *   "licenses" field) is read as AND: the gate does not guess a choice, and a
 *   missing marker in the list makes the package one without licence.
 * - OR needs one allowed side, AND needs all, "X WITH exception" counts as X.
 * - "X+" means "X-or-later"; the deprecated bare GNU ids mean "-only".
 * - license-checker appends "*" to a licence it read from the licence file
 *   instead of package.json; the star is ignored.
 * - No licence, UNKNOWN, UNLICENSED, "Custom: …" or SEE LICENSE IN: refused,
 *   unless x-licence-exceptions names the exact purl.
 */

final class LicenceGateInputError extends RuntimeException {}

/**
 * @param  list<string>  $allowed
 */
function licence_expression_allowed(string $expression, array $allowed): bool
{
    preg_match_all('/\(|\)|[^\s()]+/', $expression, $matches);
    $tokens = $matches[0];
    if ($tokens === []) {
        return false;
    }
    $position = 0;

    try {
        $result = licence_parse_or($tokens, $position, $allowed);
    } catch (LicenceGateInputError) {
        return false;
    }

    return $position === count($tokens) && $result;
}

/**
 * @param  list<string>  $tokens
 * @param  list<string>  $allowed
 */
function licence_parse_or(array $tokens, int &$position, array $allowed): bool
{
    $result = licence_parse_and($tokens, $position, $allowed);
    while (isset($tokens[$position]) && strcasecmp($tokens[$position], 'OR') === 0) {
        $position++;
        $right = licence_parse_and($tokens, $position, $allowed);
        $result = $result || $right;
    }

    return $result;
}

/**
 * @param  list<string>  $tokens
 * @param  list<string>  $allowed
 */
function licence_parse_and(array $tokens, int &$position, array $allowed): bool
{
    $result = licence_parse_atom($tokens, $position, $allowed);
    while (isset($tokens[$position]) && strcasecmp($tokens[$position], 'AND') === 0) {
        $position++;
        $right = licence_parse_atom($tokens, $position, $allowed);
        $result = $result && $right;
    }

    return $result;
}

/**
 * @param  list<string>  $tokens
 * @param  list<string>  $allowed
 */
function licence_parse_atom(array $tokens, int &$position, array $allowed): bool
{
    $token = $tokens[$position] ?? throw new LicenceGateInputError('expression ends early');
    $position++;

    if ($token === '(') {
        $result = licence_parse_or($tokens, $position, $allowed);
        if (($tokens[$position] ?? null) !== ')') {
            throw new LicenceGateInputError('missing )');
        }
        $position++;

        return $result;
    }
    if ($token === ')' || in_array(strtoupper($token), ['AND', 'OR', 'WITH'], true)) {
        throw new LicenceGateInputError("unexpected {$token}");
    }

    if (isset($tokens[$position]) && strcasecmp($tokens[$position], 'WITH') === 0) {
        $position += 2;
        if ($position > count($tokens)) {
            throw new LicenceGateInputError('WITH without exception');
        }
    }

    return in_array(licence_normalise_id($token), $allowed, true);
}

function licence_normalise_id(string $id): string
{
    $id = rtrim($id, '*');
    if (str_ends_with($id, '+')) {
        return substr($id, 0, -1).'-or-later';
    }
    if (preg_match('/^(A|L)?GPL-[0-9.]+$/', $id) === 1) {
        return $id.'-only';
    }

    return $id;
}

/**
 * A licence value that names no licence at all.
 */
function licence_is_missing(string $expression): bool
{
    $value = strtoupper(trim(rtrim(trim($expression), '*')));

    return $value === ''
        || in_array($value, ['UNKNOWN', 'UNLICENSED', 'NONE', 'NOASSERTION', 'PROPRIETARY'], true)
        || str_starts_with($value, 'CUSTOM:')
        || str_starts_with($value, 'SEE LICENSE IN');
}

/**
 * @return array<string, mixed>
 */
function licence_read_json(string $file): array
{
    $text = @file_get_contents($file);
    if ($text === false) {
        throw new LicenceGateInputError("cannot read {$file}");
    }
    $data = json_decode($text, true);
    if (! is_array($data)) {
        throw new LicenceGateInputError("{$file} is not a JSON object");
    }

    return $data;
}

/**
 * The packages of both trees as purl => [label, expression].
 *
 * @param  array<string, mixed>  $composer  output of `composer licenses --format=json`
 * @param  array<string, mixed>  $npm  output of `license-checker-rseidelsohn --json`
 * @return array<string, array{label: string, expression: string}>
 */
function licence_packages(array $composer, array $npm): array
{
    $packages = [];

    $dependencies = $composer['dependencies'] ?? null;
    if (! is_array($dependencies)) {
        throw new LicenceGateInputError('composer input has no "dependencies" object');
    }
    foreach ($dependencies as $name => $package) {
        $version = (string) ($package['version'] ?? '');
        $licences = array_values(array_filter(
            array_map('strval', (array) ($package['license'] ?? [])),
            fn (string $l): bool => ! licence_is_missing($l),
        ));
        $packages["pkg:composer/{$name}@{$version}"] = [
            'label' => "composer {$name}@{$version}",
            'expression' => $licences === [] ? '' : implode(' OR ', array_map(fn ($l) => "({$l})", $licences)),
        ];
    }

    foreach ($npm as $id => $package) {
        $at = strrpos((string) $id, '@');
        if ($at === false || $at === 0) {
            throw new LicenceGateInputError("npm input: cannot read name@version from {$id}");
        }
        // A list is a conjunction: every entry must name an allowed licence. A
        // missing marker inside the list is not dropped, it makes the whole
        // package one without licence (refused unless an exception names it).
        $licences = array_map('strval', (array) ($package['licenses'] ?? ''));
        $missing = array_filter($licences, fn (string $l): bool => licence_is_missing($l));
        $packages["pkg:npm/{$id}"] = [
            'label' => "npm {$id}",
            'expression' => $missing !== [] ? '' : implode(' AND ', array_map(fn ($l) => "({$l})", $licences)),
        ];
    }

    return $packages;
}

/**
 * @param  array<string, mixed>  $policy
 * @param  array<string, array{label: string, expression: string}>  $packages
 * @return list<string> one line per refused package
 */
function licence_refusals(array $policy, array $packages): array
{
    $allowed = $policy['allow-licenses'] ?? null;
    if (! is_array($allowed) || $allowed === []) {
        throw new LicenceGateInputError('policy has no "allow-licenses" list');
    }
    $allowed = array_values(array_map('strval', $allowed));

    $exceptions = [];
    foreach ((array) ($policy['x-licence-exceptions'] ?? []) as $exception) {
        $purl = (string) ($exception['purl'] ?? '');
        $licence = (string) ($exception['licence'] ?? '');
        if ($purl === '' || trim((string) ($exception['reason'] ?? '')) === '') {
            throw new LicenceGateInputError('every exception needs a purl and a reason');
        }
        if (! licence_expression_allowed($licence, $allowed)) {
            throw new LicenceGateInputError("exception for {$purl}: licence '{$licence}' is not on the allow list");
        }
        $exceptions[$purl] = $licence;
    }

    $refused = [];
    foreach ($packages as $purl => $package) {
        $expression = $exceptions[$purl] ?? $package['expression'];
        if ($expression === '') {
            $refused[] = "{$package['label']}: no licence";
        } elseif (! licence_expression_allowed($expression, $allowed)) {
            $refused[] = "{$package['label']}: {$expression}";
        }
    }

    return $refused;
}

function licence_self_test(): int
{
    $allowed = ['MIT', 'Apache-2.0', 'BSD-3-Clause', 'GPL-3.0-or-later', 'GPL-2.0-or-later', 'LGPL-2.1-only'];
    $cases = [
        ['MIT', true],
        ['GPL-2.0-only', false],
        ['GPL-3.0-or-later', true],
        ['SSPL-1.0', false],
        ['(MIT OR SSPL-1.0)', true],
        ['(MIT AND SSPL-1.0)', false],
        ['MIT AND (Apache-2.0 OR SSPL-1.0)', true],
        ['GPL-2.0+', true],
        ['GPL-2.0', false],
        ['LGPL-2.1', true],
        ['GPL-2.0-or-later WITH Classpath-exception-2.0', true],
        ['MIT*', true],
        ['(MIT', false],
        ['MIT OR', false],
        ['', false],
    ];
    $failures = 0;
    foreach ($cases as [$expression, $expected]) {
        $actual = licence_expression_allowed($expression, $allowed);
        if ($actual !== $expected) {
            $failures++;
            fwrite(STDERR, sprintf("FAIL: '%s' allowed=%s, expected %s\n", $expression, var_export($actual, true), var_export($expected, true)));
        }
    }

    $packages = licence_packages(
        ['dependencies' => [
            'acme/dual' => ['version' => '1.0.0', 'license' => ['BSD-3-Clause', 'GPL-2.0-only']],
            'acme/old' => ['version' => '1.0.0', 'license' => ['GPL-2.0-only']],
            'acme/none' => ['version' => '1.0.0', 'license' => []],
        ]],
        ['@scope/fine@1.0.0' => ['licenses' => 'MIT'], 'bare@1.0.0' => [], 'excused@1.0.0' => ['licenses' => 'UNKNOWN'], 'half@1.0.0' => ['licenses' => ['MIT', 'UNKNOWN']]],
    );
    $refused = licence_refusals(
        ['allow-licenses' => $allowed, 'x-licence-exceptions' => [['purl' => 'pkg:npm/excused@1.0.0', 'licence' => 'MIT', 'reason' => 'self-test']]],
        $packages,
    );
    $expected = ['composer acme/old@1.0.0: (GPL-2.0-only)', 'composer acme/none@1.0.0: no licence', 'npm bare@1.0.0: no licence', 'npm half@1.0.0: no licence'];
    if ($refused !== $expected) {
        $failures++;
        fwrite(STDERR, 'FAIL: refusals '.json_encode($refused)."\n");
    }

    echo $failures === 0 ? 'licence gate self-test: '.(count($cases) + 1)." checks passed\n" : "licence gate self-test: {$failures} failed\n";

    return $failures === 0 ? 0 : 1;
}

/**
 * @param  list<string>  $argv
 */
function licence_gate_main(array $argv): int
{
    if (($argv[1] ?? null) === '--self-test') {
        return licence_self_test();
    }
    if (count($argv) !== 4) {
        fwrite(STDERR, "usage: php scripts/licence_gate.php <composer-licences.json> <npm-licences.json> <licence-policy.yml>\n");

        return 2;
    }

    try {
        $packages = licence_packages(licence_read_json($argv[1]), licence_read_json($argv[2]));
        $refused = licence_refusals(licence_read_json($argv[3]), $packages);
    } catch (LicenceGateInputError $e) {
        fwrite(STDERR, 'licence gate: '.$e->getMessage()."\n");

        return 2;
    }

    if ($refused !== []) {
        echo 'Refused by licence-policy.yml ('.count($refused)." packages):\n";
        foreach ($refused as $line) {
            echo "- {$line}\n";
        }
        echo "See CONTRIBUTING.md, 'Dependencies and licences'.\n";

        return 1;
    }

    echo 'Licence gate: '.count($packages)." packages allowed.\n";

    return 0;
}

// $_SERVER['argv'] rather than $argv: PHP 8.5 reports register_argc_argv as
// off, so static analysis treats the global $argv as possibly undefined. The
// CLI fills $_SERVER['argv'] either way.
exit(licence_gate_main($_SERVER['argv']));
