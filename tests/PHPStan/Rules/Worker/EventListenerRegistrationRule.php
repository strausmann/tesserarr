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
use PHPStan\Type\ObjectType;

/**
 * The event dispatcher lives as long as the worker. Octane clones the
 * application per request, but the clone shares the dispatcher object, and
 * none of Octane's listeners resets it (laravel/octane v2.26.0:
 * src/Worker.php handle() and src/Listeners/; OctaneServiceProvider::boot()
 * itself registers its listeners on that dispatcher once). A listener
 * registered in a request, a middleware or a controller is therefore added
 * again on every request, keeps what its closure captured, and runs for every
 * later user.
 *
 * Listeners are registered in register() or boot() of a class in
 * App\Providers, which run once per worker. Checked: Event::listen() and
 * Event::subscribe(), and listen()/subscribe() on a dispatcher, also on
 * app('events'), resolve('events') and $app['events'].
 *
 * @implements Rule<Node\Expr\CallLike>
 */
final class EventListenerRegistrationRule implements Rule
{
    private const METHODS = ['listen', 'subscribe'];

    private const FACADE = 'Illuminate\Support\Facades\Event';

    private const DISPATCHER = 'Illuminate\Contracts\Events\Dispatcher';

    private const PROVIDERS = 'App\Providers\\';

    private const PROVIDER_METHODS = ['register', 'boot'];

    private const MESSAGE = 'Event listener registered with %s in %s: Octane does not reset the event dispatcher, so every call adds a listener that stays for every later request. Register listeners in register() or boot() of a class in App\Providers, or justify it with a @phpstan-ignore and a reason.';

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
        return Node\Expr\CallLike::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $call = $this->registration($node, $scope);
        if ($call === null || ! WorkerSafeComment::inChecked($scope, $this->namespaces, $this->paths)
            || $this->inProviderSetUp($scope)) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf(self::MESSAGE, $call, $this->where($scope)))
                ->identifier('tesserarr.worker.eventListener')
                ->build(),
        ];
    }

    private function registration(Node $node, Scope $scope): ?string
    {
        if ($node instanceof Node\Expr\StaticCall && $node->class instanceof Node\Name
            && $node->name instanceof Node\Identifier
            && in_array($node->name->toLowerString(), self::METHODS, true)
            && $scope->resolveName($node->class) === self::FACADE) {
            return self::FACADE.'::'.$node->name->toString().'()';
        }

        if ($node instanceof Node\Expr\MethodCall && $node->name instanceof Node\Identifier
            && in_array($node->name->toLowerString(), self::METHODS, true)
            && ($this->isDispatcher($node->var, $scope))) {
            return $node->name->toString().'()';
        }

        return null;
    }

    private function isDispatcher(Node\Expr $expr, Scope $scope): bool
    {
        if ((new ObjectType(self::DISPATCHER))->isSuperTypeOf($scope->getType($expr))->yes()) {
            return true;
        }

        // Resolved by name; without Larastan their type is mixed.
        if ($expr instanceof Node\Expr\FuncCall && $expr->name instanceof Node\Name
            && in_array(strtolower($expr->name->toString()), ['app', 'resolve'], true)) {
            $arg = $expr->getArgs()[0]->value ?? null;

            return $arg instanceof Node\Scalar\String_ && $arg->value === 'events';
        }

        return $expr instanceof Node\Expr\ArrayDimFetch && $expr->dim instanceof Node\Scalar\String_
            && $expr->dim->value === 'events';
    }

    /**
     * register() or boot() of a class in App\Providers, closures inside them
     * included (they run while the provider boots).
     */
    private function inProviderSetUp(Scope $scope): bool
    {
        return $scope->isInClass()
            && str_starts_with($scope->getClassReflection()->getName(), self::PROVIDERS)
            && in_array($scope->getFunctionName(), self::PROVIDER_METHODS, true);
    }

    private function where(Scope $scope): string
    {
        if (! $scope->isInClass()) {
            return str_replace((string) getcwd().'/', '', $scope->getFile());
        }

        return $scope->getClassReflection()->getDisplayName().'::'.($scope->getFunctionName() ?? '?').'()';
    }
}
