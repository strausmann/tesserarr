<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Tests\PHPStan\Rules\Worker;

use PhpParser\Node;
use PHPStan\Analyser\Scope;

/**
 * The allowlist of the worker-mode rules: a comment on the node that starts
 * with "worker-safe:" and gives a reason. A bare marker is not enough.
 */
final class WorkerSafeComment
{
    public const MARKER = 'worker-safe:';

    public static function justifies(Node $node): bool
    {
        foreach ($node->getComments() as $comment) {
            if (preg_match('/'.preg_quote(self::MARKER, '/').'[ \t]*\S.{2,}/', $comment->getText()) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the code at $scope is checked: its namespace starts with one of
     * $namespaces, or its file matches one of $paths. Route files have no
     * namespace, so they are found by path (fnmatch, relative to the working
     * directory, as in tests/PHPStan/worker-safety.neon).
     *
     * @param  list<string>  $namespaces  namespace prefixes, each ending in a backslash
     * @param  list<string>  $paths  fnmatch patterns, e.g. "routes/*"
     */
    public static function inChecked(Scope $scope, array $namespaces, array $paths = []): bool
    {
        $namespace = $scope->getNamespace();
        foreach ($namespaces as $prefix) {
            if ($namespace !== null && str_starts_with($namespace.'\\', $prefix)) {
                return true;
            }
        }

        $file = $scope->getFile();
        $root = (string) getcwd().'/';
        $relative = str_starts_with($file, $root) ? substr($file, strlen($root)) : $file;
        foreach ($paths as $pattern) {
            if (fnmatch($pattern, $relative)) {
                return true;
            }
        }

        return false;
    }
}
