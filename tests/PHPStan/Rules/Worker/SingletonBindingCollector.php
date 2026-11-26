<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Tests\PHPStan\Rules\Worker;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\Type\ObjectType;

/**
 * Collects the classes the application keeps as singletons, for
 * SingletonStateRule: `singleton()` and `singletonIf()` on a container or the
 * App facade with a class (`Foo::class`) or a closure that returns
 * `new Foo(...)`. Classes marked #[Singleton] are SingletonAttributeCollector's.
 *
 * scoped() is left out on purpose: Octane forgets scoped instances after every
 * request, so a memo on a scoped service lives for one request only.
 *
 * @implements Collector<Node\Expr\CallLike, list<string>>
 */
final class SingletonBindingCollector implements Collector
{
    private const METHODS = ['singleton', 'singletonif'];

    public function getNodeType(): string
    {
        return Node\Expr\CallLike::class;
    }

    /**
     * @return list<string>|null
     */
    public function processNode(Node $node, Scope $scope): ?array
    {
        if (! ($node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\StaticCall)
            || ! $node->name instanceof Node\Identifier
            || ! in_array($node->name->toLowerString(), self::METHODS, true)
            || ! $this->onContainer($node, $scope)) {
            return null;
        }

        $args = $node->getArgs();
        if ($args === []) {
            return null;
        }
        $concrete = $args[1]->value ?? $args[0]->value;
        $classes = $this->classesOf($concrete, $scope);

        return $classes === [] ? null : $classes;
    }

    private function onContainer(Node\Expr\MethodCall|Node\Expr\StaticCall $call, Scope $scope): bool
    {
        if ($call instanceof Node\Expr\StaticCall) {
            return $call->class instanceof Node\Name
                && $scope->resolveName($call->class) === 'Illuminate\Support\Facades\App';
        }

        return (new ObjectType('Illuminate\Contracts\Container\Container'))
            ->isSuperTypeOf($scope->getType($call->var))
            ->yes();
    }

    /**
     * @return list<string>
     */
    private function classesOf(Node\Expr $expr, Scope $scope): array
    {
        if ($expr instanceof Node\Expr\ClassConstFetch && $expr->class instanceof Node\Name
            && $expr->name instanceof Node\Identifier && $expr->name->toLowerString() === 'class') {
            return [$scope->resolveName($expr->class)];
        }
        if ($expr instanceof Node\Expr\ArrowFunction) {
            return $this->classesOf($expr->expr, $scope);
        }
        if ($expr instanceof Node\Expr\Closure) {
            $classes = [];
            foreach ($expr->stmts as $stmt) {
                if ($stmt instanceof Node\Stmt\Return_ && $stmt->expr !== null) {
                    $classes = [...$classes, ...$this->classesOf($stmt->expr, $scope)];
                }
            }

            return $classes;
        }
        if ($expr instanceof Node\Expr\New_ && $expr->class instanceof Node\Name) {
            return [$scope->resolveName($expr->class)];
        }

        return [];
    }
}
