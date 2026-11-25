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
 * A static variable in a function or method keeps its value between
 * requests of the worker, like a static property (NoStaticPropertyRule).
 *
 * @implements Rule<Node\Stmt\Static_>
 */
final class NoStaticVariableRule implements Rule
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
        return Node\Stmt\Static_::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (! WorkerSafeComment::inChecked($scope, $this->namespaces, $this->paths)
            || WorkerSafeComment::justifies($node)) {
            return [];
        }

        $errors = [];
        foreach ($node->vars as $variable) {
            $name = $variable->var->name;
            $errors[] = RuleErrorBuilder::message(sprintf(
                'Static variable $%s outlives the request in worker mode. Use a scoped binding or a per-request value, or justify it with a "worker-safe: <reason>" comment.',
                is_string($name) ? $name : '?',
            ))->identifier('tesserarr.worker.staticVariable')->line($variable->getStartLine())->build();
        }

        return $errors;
    }
}
