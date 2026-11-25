<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Tests\PHPStan\Rules\Worker;

use PhpParser\Node;

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
     * @param  list<string>  $namespaces  namespace prefixes, each ending in a backslash
     */
    public static function inChecked(?string $namespace, array $namespaces): bool
    {
        if ($namespace === null) {
            return false;
        }

        foreach ($namespaces as $prefix) {
            if (str_starts_with($namespace.'\\', $prefix)) {
                return true;
            }
        }

        return false;
    }
}
