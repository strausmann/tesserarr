<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

/*
 * Coverage gate per directory, on top of the global `pest --coverage --min=80`.
 * Plain PHP, no Composer autoloader, so it also runs on main.
 *
 *   php scripts/coverage_gate.php [--root=<dir>] <clover.xml> [<path>:<min> …]
 *   php scripts/coverage_gate.php --self-test
 *
 * <path> is relative to the project root (default: the working directory),
 * <min> a percentage 0–100 of covered statements.
 *
 * - A path whose directory does not exist yet is skipped with a notice: the
 *   list in ci.yml may name a package before it is built (CONTRIBUTING.md,
 *   "Local CI checks"). Each task that creates a directory below app/ adds its
 *   path and threshold to ci.yml in the same pull request.
 * - A path whose directory holds PHP files of which none is in the report fails:
 *   code that the tests never load is not covered at 100 %.
 * - A path whose files are in the report with 0 statements (interfaces, enums
 *   without methods) passes with a notice, as does a directory without PHP files.
 * - Exit 0: every path meets its minimum. Exit 1: one or more paths below it
 *   or without lines. Exit 2: unreadable report or malformed argument.
 */

final class CoverageGateInputError extends RuntimeException {}

/**
 * Statements and covered statements per file, keyed by the path relative to $root.
 *
 * @return array<string, array{0: int, 1: int}>
 */
function coverage_read_clover(string $file, string $root): array
{
    $previous = libxml_use_internal_errors(true);
    $xml = @simplexml_load_file($file);
    libxml_use_internal_errors($previous);
    if ($xml === false) {
        throw new CoverageGateInputError("cannot read the clover report {$file}");
    }

    $prefix = rtrim($root, '/').'/';
    $files = [];
    foreach ($xml->xpath('//file') ?: [] as $node) {
        $name = (string) $node['name'];
        $relative = str_starts_with($name, $prefix) ? substr($name, strlen($prefix)) : $name;
        // The file-level <metrics> is a direct child; a <class> inside carries its own.
        $metrics = null;
        foreach ($node->children() as $child) {
            if ($child->getName() === 'metrics') {
                $metrics = $child;
            }
        }
        if ($metrics === null) {
            continue;
        }
        $files[$relative] = [(int) $metrics->attributes()['statements'], (int) $metrics->attributes()['coveredstatements']];
    }

    return $files;
}

/**
 * @param  list<string>  $arguments  "<path>:<min>"
 * @return list<array{path: string, min: float}>
 */
function coverage_parse_thresholds(array $arguments): array
{
    $thresholds = [];
    foreach ($arguments as $argument) {
        if (preg_match('/^([^:]+):([0-9]+(?:\.[0-9]+)?)$/', $argument, $m) !== 1 || (float) $m[2] > 100) {
            throw new CoverageGateInputError("malformed threshold '{$argument}', expected <path>:<0-100>");
        }
        $thresholds[] = ['path' => trim($m[1], '/'), 'min' => (float) $m[2]];
    }

    return $thresholds;
}

/**
 * @param  array<string, array{0: int, 1: int}>  $files
 * @param  list<array{path: string, min: float}>  $thresholds
 * @return array{lines: list<string>, failed: bool}
 */
function coverage_judge(array $files, array $thresholds, string $root): array
{
    $lines = [];
    $failed = false;
    foreach ($thresholds as ['path' => $path, 'min' => $min]) {
        if (! is_dir($root.'/'.$path)) {
            $lines[] = "skip: {$path} does not exist yet (minimum {$min} % applies once it does)";

            continue;
        }
        $statements = 0;
        $covered = 0;
        $reported = 0;
        foreach ($files as $file => [$s, $c]) {
            if (str_starts_with($file, $path.'/')) {
                $reported++;
                $statements += $s;
                $covered += $c;
            }
        }
        if ($reported === 0) {
            $php = coverage_count_php_files($root.'/'.$path);
            if ($php > 0) {
                $lines[] = "FAIL: {$path} has {$php} PHP files but no lines in the coverage report (minimum {$min} %)";
                $failed = true;
            } else {
                $lines[] = "ok: {$path} holds no PHP file yet";
            }

            continue;
        }
        if ($statements === 0) {
            $lines[] = "ok: {$path} holds only declarations ({$reported} files in the report, 0 statements)";

            continue;
        }
        $percent = floor($covered / $statements * 1000) / 10;
        if ($percent < $min) {
            $lines[] = sprintf('FAIL: %s %.1f %% < %s %% (%d of %d statements)', $path, $percent, $min, $covered, $statements);
            $failed = true;
        } else {
            $lines[] = sprintf('ok: %s %.1f %% >= %s %%', $path, $percent, $min);
        }
    }

    return ['lines' => $lines, 'failed' => $failed];
}

function coverage_count_php_files(string $dir): int
{
    $count = 0;
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
            $count++;
        }
    }

    return $count;
}

function coverage_self_test(): int
{
    $root = sys_get_temp_dir().'/coverage-self-test-'.bin2hex(random_bytes(4));
    mkdir($root.'/app/Authorization', 0777, true);
    mkdir($root.'/app/Mfa', 0777, true);
    mkdir($root.'/app/MfaOld', 0777, true);
    mkdir($root.'/app/Tokens', 0777, true);
    touch($root.'/app/Mfa/Policy.php');
    $files = [
        'app/Tokens/TokenIssuer.php' => [0, 0],
        'app/Authorization/A.php' => [100, 95],
        'app/Authorization/B.php' => [100, 80],
        'app/MfaOld/C.php' => [10, 10],
    ];
    $cases = [
        [['app/Authorization:90'], true, '87.5'],
        [['app/Authorization:85'], false, '87.5'],
        [['app/Mfa:90'], true, 'no lines'],
        [['app/Tokens:90'], false, 'only declarations'],
        [['app/Catalog:85'], false, 'does not exist'],
        [[], false, ''],
    ];
    $failures = 0;
    foreach ($cases as [$args, $shouldFail, $needle]) {
        $result = coverage_judge($files, coverage_parse_thresholds($args), $root);
        $text = implode("\n", $result['lines']);
        if ($result['failed'] !== $shouldFail || ($needle !== '' && ! str_contains($text, $needle))) {
            $failures++;
            fwrite(STDERR, 'FAIL: '.json_encode($args).' -> '.$text."\n");
        }
    }
    foreach (['app/X', 'app/X:abc', 'app/X:101'] as $bad) {
        try {
            coverage_parse_thresholds([$bad]);
            $failures++;
            fwrite(STDERR, "FAIL: accepted '{$bad}'\n");
        } catch (CoverageGateInputError) {
        }
    }
    unlink($root.'/app/Mfa/Policy.php');
    foreach (['app/Tokens', 'app/MfaOld', 'app/Mfa', 'app/Authorization', 'app'] as $dir) {
        rmdir($root.'/'.$dir);
    }
    rmdir($root);

    echo $failures === 0 ? "coverage gate self-test: 9 checks passed\n" : "coverage gate self-test: {$failures} failed\n";

    return $failures === 0 ? 0 : 1;
}

/**
 * @param  list<string>  $argv
 */
function coverage_gate_main(array $argv): int
{
    $arguments = array_slice($argv, 1);
    if (($arguments[0] ?? null) === '--self-test') {
        return coverage_self_test();
    }

    $root = (string) getcwd();
    if (isset($arguments[0]) && str_starts_with($arguments[0], '--root=')) {
        $root = substr(array_shift($arguments), strlen('--root='));
    }
    $report = array_shift($arguments);
    if ($report === null) {
        fwrite(STDERR, "usage: php scripts/coverage_gate.php [--root=<dir>] <clover.xml> [<path>:<min> …]\n");

        return 2;
    }

    try {
        $files = coverage_read_clover($report, $root);
        $result = coverage_judge($files, coverage_parse_thresholds($arguments), $root);
    } catch (CoverageGateInputError $e) {
        fwrite(STDERR, 'coverage gate: '.$e->getMessage()."\n");

        return 2;
    }

    if ($result['lines'] === []) {
        echo 'coverage gate: report readable ('.count($files)." files), no per-directory minimum configured\n";
    }
    foreach ($result['lines'] as $line) {
        echo $line."\n";
    }

    return $result['failed'] ? 1 : 0;
}

exit(coverage_gate_main($argv));
