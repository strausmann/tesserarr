<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Tests\PHPStan\Rules\Worker;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\CollectedDataNode;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * A singleton that writes its own properties after construction keeps what
 * one request left there for every later request: a memo of the current
 * user, a list it appends to, a counter. That is the most common real leak in
 * worker mode (task 4c review). A singleton only writes its properties in the
 * constructor; per-request state belongs in a scoped binding or is passed per
 * call.
 *
 * Works on what SingletonBindingCollector and PropertyWriteCollector found
 * across the whole analysis, so a binding in a provider and a write in the
 * service's own file meet here. A write in a parent class counts for the
 * bound child.
 *
 * @implements Rule<CollectedDataNode>
 */
final class SingletonStateRule implements Rule
{
    private const MESSAGE = 'Singleton %s writes $%s in %s(), so what one request leaves there stays for every later request in worker mode. Keep per-request state out of the singleton (scoped() or a value passed per call), or justify it with a @phpstan-ignore and a reason.';

    public function __construct(private readonly ReflectionProvider $reflectionProvider) {}

    public function getNodeType(): string
    {
        return CollectedDataNode::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $singletons = [];
        foreach ([SingletonBindingCollector::class, SingletonAttributeCollector::class] as $collector) {
            foreach (array_merge(...array_values($node->get($collector))) as $classes) {
                foreach ($classes as $class) {
                    if ($this->reflectionProvider->hasClass($class)) {
                        $singletons[$class] = $this->reflectionProvider->getClass($class);
                    }
                }
            }
        }
        if ($singletons === []) {
            return [];
        }

        $errors = [];
        foreach ($node->get(PropertyWriteCollector::class) as $file => $writes) {
            foreach ($writes as $write) {
                foreach ($singletons as $name => $singleton) {
                    if (! $singleton->is($write['class'])) {
                        continue;
                    }
                    $errors[] = RuleErrorBuilder::message(sprintf(
                        self::MESSAGE,
                        $name,
                        $write['property'],
                        $write['class'].'::'.$write['method'],
                    ))->identifier('tesserarr.worker.singletonState')->file($file)->line($write['line'])->build();
                }
            }
        }

        return $errors;
    }
}
