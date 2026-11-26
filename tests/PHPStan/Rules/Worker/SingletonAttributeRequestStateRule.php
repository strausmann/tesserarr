<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Tests\PHPStan\Rules\Worker;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassNode;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * The attribute form of SingletonRequestStateRule: a class marked
 * #[Singleton] is checked by its constructor parameters. #[Scoped] is fine.
 *
 * @implements Rule<InClassNode>
 */
final class SingletonAttributeRequestStateRule implements Rule
{
    private const ATTRIBUTE = 'Illuminate\Container\Attributes\Singleton';

    private const MESSAGE = 'Singleton %s is built from request state (%s). The value of the first request stays for every later request in worker mode. Bind it with scoped() or read the value per call, or justify it with a "worker-safe: <reason>" comment.';

    public function __construct(private readonly ReflectionProvider $reflectionProvider) {}

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
        $isSingleton = false;
        foreach ($class->getAttributes() as $attribute) {
            $isSingleton = $isSingleton || $attribute->getName() === self::ATTRIBUTE;
        }
        if (! $isSingleton || WorkerSafeComment::justifies($node->getOriginalNode())) {
            return [];
        }

        $errors = [];
        foreach (RequestState::constructorFindings($class, $this->reflectionProvider) as $finding) {
            $errors[] = RuleErrorBuilder::message(sprintf(self::MESSAGE, $class->getDisplayName(), $finding))
                ->identifier('tesserarr.worker.singletonRequestState')
                ->build();
        }

        return $errors;
    }
}
