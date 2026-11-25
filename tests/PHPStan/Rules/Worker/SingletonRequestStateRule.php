<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Tests\PHPStan\Rules\Worker;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;

/**
 * A singleton is built once per worker and handed to every later request. A
 * singleton built from the request, the auth guard or user, the session, the
 * configuration or the locale keeps the first request's values
 * (CONTRIBUTING.md, "Worker-safe code"). Such a binding uses scoped(), or the
 * service reads the value per call.
 *
 * Checked: `singleton()` and `singletonIf()` on a container and on the App
 * facade. A class binding is checked by its constructor parameters, a
 * closure by its parameters and by what it reads (RequestState). A
 * "worker-safe: <reason>" comment on the statement allows one binding.
 *
 * @implements Rule<Node\Stmt\Expression>
 */
final class SingletonRequestStateRule implements Rule
{
    private const METHODS = ['singleton', 'singletonif'];

    private const MESSAGE = 'Singleton %s is built from request state (%s). The value of the first request stays for every later request in worker mode. Bind it with scoped() or read the value per call, or justify it with a "worker-safe: <reason>" comment.';

    private readonly RequestState $requestState;

    public function __construct(private readonly ReflectionProvider $reflectionProvider)
    {
        $this->requestState = new RequestState($reflectionProvider);
    }

    public function getNodeType(): string
    {
        return Node\Stmt\Expression::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $call = $node->expr;
        if (! $this->isSingletonBinding($call, $scope) || WorkerSafeComment::justifies($node)) {
            return [];
        }
        /** @var Node\Expr\MethodCall|Node\Expr\StaticCall $call */
        $args = $call->getArgs();
        if ($args === []) {
            return [];
        }

        $abstract = $this->className($args[0]->value, $scope);
        $concrete = $args[1]->value ?? null;
        $name = $abstract ?? ($args[0]->value instanceof Node\Scalar\String_ ? $args[0]->value->value : '?');

        if ($concrete instanceof Node\Expr\Closure || $concrete instanceof Node\Expr\ArrowFunction) {
            $findings = $this->requestState->inClosure($concrete, $scope);
        } else {
            $class = $concrete === null ? $abstract : $this->className($concrete, $scope);
            $findings = $class === null ? [] : $this->requestState->inConstructorOf($class);
        }

        $errors = [];
        foreach ($findings as $finding) {
            $errors[] = RuleErrorBuilder::message(sprintf(self::MESSAGE, $name, $finding))
                ->identifier('tesserarr.worker.singletonRequestState')
                ->line($call->getStartLine())
                ->build();
        }

        return $errors;
    }

    private function isSingletonBinding(Node\Expr $call, Scope $scope): bool
    {
        if (! ($call instanceof Node\Expr\MethodCall || $call instanceof Node\Expr\StaticCall)
            || ! $call->name instanceof Node\Identifier
            || ! in_array($call->name->toLowerString(), self::METHODS, true)) {
            return false;
        }

        if ($call instanceof Node\Expr\StaticCall) {
            return $call->class instanceof Node\Name
                && $scope->resolveName($call->class) === 'Illuminate\Support\Facades\App';
        }

        return (new ObjectType('Illuminate\Contracts\Container\Container'))
            ->isSuperTypeOf($scope->getType($call->var))
            ->yes();
    }

    /**
     * The class an argument names: `Foo::class` or a string that is a known
     * class. Anything else (an alias such as 'greeter') gives null.
     */
    private function className(Node\Expr $expr, Scope $scope): ?string
    {
        if ($expr instanceof Node\Expr\ClassConstFetch && $expr->class instanceof Node\Name
            && $expr->name instanceof Node\Identifier && $expr->name->toLowerString() === 'class') {
            return $scope->resolveName($expr->class);
        }
        if ($expr instanceof Node\Scalar\String_ && $this->reflectionProvider->hasClass($expr->value)) {
            return $expr->value;
        }

        return null;
    }
}
