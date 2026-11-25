<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Tests\PHPStan\Rules\Worker;

use PhpParser\Modifiers;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * A public property that is not readonly can be written by any caller. On an
 * object the worker shares between requests (a singleton, a controller or
 * middleware instance, a service resolved at boot) that write is visible to
 * the next request of another user (CONTRIBUTING.md, "Worker-safe code").
 *
 * Declared and promoted properties are both checked. Static properties are
 * left to NoStaticPropertyRule. Classes that are one object per row, job or
 * message by design (Eloquent models, queued jobs) are listed in $exempt.
 *
 * @implements Rule<InClassNode>
 */
final class NoMutablePublicPropertyRule implements Rule
{
    /**
     * @param  list<string>  $namespaces  namespace prefixes to check, e.g. "App\\"
     * @param  list<class-string>  $exempt  classes and interfaces whose subtypes are not checked
     */
    public function __construct(
        private readonly array $namespaces,
        private readonly array $exempt,
    ) {}

    public function getNodeType(): string
    {
        return InClassNode::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $class = $node->getClassReflection();
        if ($class->isReadOnly() || $class->isEnum()
            || ! WorkerSafeComment::inChecked($scope->getNamespace(), $this->namespaces)) {
            return [];
        }
        foreach ($this->exempt as $exempt) {
            if ($class->is($exempt)) {
                return [];
            }
        }

        $errors = [];
        foreach ($this->writablePublicProperties($node->getOriginalNode()) as $name => $declaration) {
            $errors[] = RuleErrorBuilder::message(sprintf(
                'Public property %s::$%s can be written by any caller and outlives the request when the object is shared. Make it readonly or private, or justify it with a "worker-safe: <reason>" comment.',
                $class->getDisplayName(),
                $name,
            ))->identifier('tesserarr.worker.mutablePublicProperty')->line($declaration->getStartLine())->build();
        }

        return $errors;
    }

    /**
     * Declared and promoted instance properties that anyone may write.
     *
     * @return array<string, Node>
     */
    private function writablePublicProperties(Node\Stmt\ClassLike $class): array
    {
        $found = [];
        foreach ($class->getProperties() as $property) {
            if ($property->isPublic() && ! $property->isStatic() && ! $property->isReadonly()
                && ! $this->restrictsWrites($property->flags) && ! WorkerSafeComment::justifies($property)) {
                foreach ($property->props as $item) {
                    $found[$item->name->toString()] = $item;
                }
            }
        }

        foreach ($class->getMethod('__construct')->params ?? [] as $param) {
            if ($param->isPromoted() && $param->isPublic() && ! $param->isReadonly()
                && ! $this->restrictsWrites($param->flags) && ! WorkerSafeComment::justifies($param)
                && $param->var instanceof Node\Expr\Variable && is_string($param->var->name)) {
                $found[$param->var->name] = $param;
            }
        }

        return $found;
    }

    /**
     * public private(set) and public protected(set): only the class writes.
     */
    private function restrictsWrites(int $flags): bool
    {
        return ($flags & (Modifiers::PRIVATE_SET | Modifiers::PROTECTED_SET)) !== 0;
    }
}
