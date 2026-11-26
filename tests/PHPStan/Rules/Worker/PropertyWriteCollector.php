<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Tests\PHPStan\Rules\Worker;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;

/**
 * Collects writes to `$this->property` outside the constructor, for
 * SingletonStateRule: assignments (also `??=`, `.=`, by reference),
 * `$this->list[] = …`, `$this->map[$key] = …` and `++`/`--`.
 *
 * Not seen: calls that change an object held in a property
 * (`$this->items->push(…)`), and writes through another variable than $this.
 *
 * @implements Collector<Node\Expr, array{class: string, property: string, method: string, line: int}>
 */
final class PropertyWriteCollector implements Collector
{
    public function getNodeType(): string
    {
        return Node\Expr::class;
    }

    /**
     * @return array{class: string, property: string, method: string, line: int}|null
     */
    public function processNode(Node $node, Scope $scope): ?array
    {
        $target = match (true) {
            $node instanceof Node\Expr\Assign, $node instanceof Node\Expr\AssignOp, $node instanceof Node\Expr\AssignRef => $node->var,
            $node instanceof Node\Expr\PreInc, $node instanceof Node\Expr\PostInc,
            $node instanceof Node\Expr\PreDec, $node instanceof Node\Expr\PostDec => $node->var,
            default => null,
        };
        if ($target === null || ! $scope->isInClass()) {
            return null;
        }

        while ($target instanceof Node\Expr\ArrayDimFetch) {
            $target = $target->var;
        }
        if (! $target instanceof Node\Expr\PropertyFetch || ! $target->name instanceof Node\Identifier
            || ! $target->var instanceof Node\Expr\Variable || $target->var->name !== 'this') {
            return null;
        }

        $method = $scope->getFunctionName() ?? '';
        if (strtolower($method) === '__construct') {
            return null;
        }

        return [
            'class' => $scope->getClassReflection()->getName(),
            'property' => $target->name->toString(),
            'method' => $method,
            'line' => $node->getStartLine(),
        ];
    }
}
