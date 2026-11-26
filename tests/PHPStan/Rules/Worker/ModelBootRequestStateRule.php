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
 * A model boots once per worker: boot(), booted() and the boot<Trait>()
 * methods of its traits run for the first query and never again
 * (Model::$booted is static). Request state read there, outside a closure,
 * is the first request's value; a global scope or a model event built from
 * it applies it to every later user. Inside the scope or event closure it is
 * read per query and is fine.
 *
 * The same holds for a scope class that reads request state in its
 * constructor: booted() builds it once.
 *
 * Request state here: auth(), request(), session() and the Auth, Request and
 * Session facades.
 *
 * @implements Rule<Node\Expr\CallLike>
 */
final class ModelBootRequestStateRule implements Rule
{
    private const HELPERS = ['auth', 'request', 'session'];

    private const FACADES = [
        'Illuminate\Support\Facades\Auth',
        'Illuminate\Support\Facades\Request',
        'Illuminate\Support\Facades\Session',
    ];

    private const MODEL = 'Illuminate\Database\Eloquent\Model';

    private const SCOPE = 'Illuminate\Database\Eloquent\Scope';

    private const BOOT = '%s reads request state (%s) in %s(), which runs once per worker: the value of the first request stays in every scope and listener it builds. Read it inside the closure, or justify it with a @phpstan-ignore and a reason.';

    private const SCOPE_CONSTRUCTOR = 'Scope %s reads request state (%s) in its constructor, and a scope is built once in booted(). Read it in apply(), or justify it with a @phpstan-ignore and a reason.';

    public function getNodeType(): string
    {
        return Node\Expr\CallLike::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (! $scope->isInClass() || $scope->isInAnonymousFunction() || $scope->getFunction() === null) {
            return [];
        }
        $read = $this->requestRead($node, $scope);
        if ($read === null) {
            return [];
        }

        $class = $scope->getClassReflection();
        $method = $scope->getFunction()->getName();

        if ($class->is(self::MODEL) && str_starts_with(strtolower($method), 'boot')) {
            $message = sprintf(self::BOOT, 'Model '.$class->getDisplayName(), $read, $method);
        } elseif ($class->implementsInterface(self::SCOPE) && $method === '__construct') {
            $message = sprintf(self::SCOPE_CONSTRUCTOR, $class->getDisplayName(), $read);
        } else {
            return [];
        }

        return [RuleErrorBuilder::message($message)->identifier('tesserarr.worker.bootRequestState')->build()];
    }

    private function requestRead(Node $node, Scope $scope): ?string
    {
        if ($node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name) {
            $function = strtolower($node->name->toString());

            return in_array($function, self::HELPERS, true) ? $function.'()' : null;
        }

        if ($node instanceof Node\Expr\StaticCall && $node->class instanceof Node\Name && $node->name instanceof Node\Identifier) {
            $class = $scope->resolveName($node->class);

            return in_array($class, self::FACADES, true) ? $class.'::'.$node->name->toString().'()' : null;
        }

        return null;
    }
}
