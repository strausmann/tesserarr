<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Tests\PHPStan\Rules\Worker;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * A static property keeps its value for every later request of the worker,
 * of any user (CONTRIBUTING.md, "Worker-safe code"). PHP has no readonly
 * static properties, so every static property is reported unless a
 * "worker-safe: <reason>" comment says why it holds no request data.
 *
 * @implements Rule<Node\Stmt\Property>
 */
final class NoStaticPropertyRule implements Rule
{
    /**
     * @param  list<string>  $namespaces  namespace prefixes to check, e.g. "App\\"
     * @param  list<string>  $paths  files to check that have no namespace, as fnmatch patterns, e.g. "routes/*"
     */
    public function __construct(
        private readonly array $namespaces,
        private readonly array $paths = [],
    ) {}

    public function getNodeType(): string
    {
        return Node\Stmt\Property::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (! $node->isStatic() || ! $scope->isInClass()
            || ! WorkerSafeComment::inChecked($scope, $this->namespaces, $this->paths)
            || WorkerSafeComment::justifies($node)) {
            return [];
        }

        $class = $scope->getClassReflection()->getDisplayName();
        $errors = [];
        foreach ($node->props as $property) {
            $errors[] = RuleErrorBuilder::message(sprintf(
                'Static property %s::$%s outlives the request in worker mode. Use a scoped binding or a per-request value, or justify it with a "worker-safe: <reason>" comment.',
                $class,
                $property->name->toString(),
            ))->identifier('tesserarr.worker.staticProperty')->line($property->getStartLine())->build();
        }

        return $errors;
    }
}
