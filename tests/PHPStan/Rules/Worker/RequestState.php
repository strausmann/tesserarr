<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Tests\PHPStan\Rules\Worker;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ReflectionProvider;

/**
 * What a singleton must not be built from: the request, the signed-in user
 * and the auth guards, the session, the configuration, the locale. Octane
 * resets these per request; a singleton built from them keeps the first
 * request's values (https://laravel.com/docs/13.x/octane#dependency-injection-and-octane).
 */
final class RequestState
{
    /**
     * Types a singleton must not receive, neither in its constructor nor as a
     * parameter of the closure that builds it.
     */
    public const TYPES = [
        'Symfony\Component\HttpFoundation\Request',
        'Illuminate\Contracts\Auth\Factory',
        'Illuminate\Contracts\Auth\Guard',
        'Illuminate\Contracts\Auth\Authenticatable',
        'Illuminate\Contracts\Session\Session',
        'Illuminate\Session\SessionManager',
        'Illuminate\Contracts\Config\Repository',
        'Illuminate\Contracts\Translation\Translator',
        'Illuminate\Contracts\Cookie\Factory',
    ];

    /**
     * Additionally refused in a singleton's constructor: the container. A
     * singleton that keeps it resolves from the boot container forever. The
     * closure that builds a binding gets the container as its parameter, which
     * is fine.
     */
    public const CONSTRUCTOR_ONLY_TYPES = [
        'Illuminate\Contracts\Container\Container',
    ];

    /** Container aliases of the request state. */
    public const ALIASES = ['request', 'auth', 'auth.driver', 'session', 'session.store', 'config', 'translator', 'cookie'];

    /** Helper functions that read request state. */
    public const HELPERS = ['request', 'auth', 'session', 'config', 'cookie', 'trans', '__', 'trans_choice'];

    /** Helper functions that resolve from the container by name. */
    public const RESOLVERS = ['app', 'resolve'];

    /** Facades of the request state, and the locale methods of App. */
    public const FACADES = [
        'Illuminate\Support\Facades\Auth',
        'Illuminate\Support\Facades\Request',
        'Illuminate\Support\Facades\Session',
        'Illuminate\Support\Facades\Config',
        'Illuminate\Support\Facades\Cookie',
        'Illuminate\Support\Facades\Lang',
    ];

    public const LOCALE_METHODS = ['getLocale', 'currentLocale', 'getFallbackLocale'];

    /** Container methods that resolve a binding. */
    public const MAKE_METHODS = ['make', 'makeWith', 'get', 'build'];

    public function __construct(private readonly ReflectionProvider $reflectionProvider) {}

    /**
     * Constructor parameters of $class that receive request state.
     *
     * @return list<string> one description per parameter
     */
    public function inConstructorOf(string $class): array
    {
        if (! $this->reflectionProvider->hasClass($class)) {
            return [];
        }

        return self::constructorFindings($this->reflectionProvider->getClass($class), $this->reflectionProvider);
    }

    /**
     * @return list<string>
     */
    public static function constructorFindings(ClassReflection $class, ReflectionProvider $reflectionProvider): array
    {
        if (! $class->hasConstructor()) {
            return [];
        }

        $findings = [];
        foreach ($class->getConstructor()->getOnlyVariant()->getParameters() as $parameter) {
            foreach ($parameter->getType()->getObjectClassNames() as $typeName) {
                $hit = self::matches($typeName, [...self::TYPES, ...self::CONSTRUCTOR_ONLY_TYPES], $reflectionProvider);
                if ($hit) {
                    $findings[] = sprintf('constructor parameter $%s: %s', $parameter->getName(), $typeName);
                }
            }
        }

        return $findings;
    }

    /**
     * Places in a binding closure that read request state.
     *
     * @return list<string>
     */
    public function inClosure(Node\Expr\Closure|Node\Expr\ArrowFunction $closure, Scope $scope): array
    {
        $findings = [];
        foreach ($closure->params as $param) {
            if ($param->type instanceof Node\Name && $param->var instanceof Node\Expr\Variable && is_string($param->var->name)) {
                $typeName = $scope->resolveName($param->type);
                if (self::matches($typeName, self::TYPES, $this->reflectionProvider)) {
                    $findings[] = sprintf('closure parameter $%s: %s', $param->var->name, $typeName);
                }
            }
        }

        $this->collectReads(self::bodyOf($closure), $scope, $findings);

        return $findings;
    }

    /**
     * Walks what runs while the binding is built. A closure inside the body is
     * deferred (the service may call it per use) and is skipped, unless it is
     * invoked right there: `(fn () => request()->ip())()` runs at construction.
     *
     * @param  array<mixed>  $nodes
     * @param  list<string>  $findings
     */
    private function collectReads(array $nodes, Scope $scope, array &$findings): void
    {
        foreach ($nodes as $node) {
            if (is_array($node)) {
                $this->collectReads($node, $scope, $findings);

                continue;
            }
            if (! $node instanceof Node) {
                continue;
            }
            if ($node instanceof Node\Expr\Closure || $node instanceof Node\Expr\ArrowFunction) {
                continue;
            }
            if ($node instanceof Node\Expr\FuncCall
                && ($node->name instanceof Node\Expr\Closure || $node->name instanceof Node\Expr\ArrowFunction)) {
                $this->collectReads(self::bodyOf($node->name), $scope, $findings);
                $this->collectReads($node->args, $scope, $findings);

                continue;
            }

            $finding = $this->readOf($node, $scope);
            if ($finding !== null) {
                $findings[] = $finding;
            }
            foreach ($node->getSubNodeNames() as $name) {
                $this->collectReads([$node->{$name}], $scope, $findings);
            }
        }
    }

    /**
     * @return array<Node>
     */
    private static function bodyOf(Node\Expr\Closure|Node\Expr\ArrowFunction $closure): array
    {
        return $closure instanceof Node\Expr\ArrowFunction ? [$closure->expr] : $closure->stmts;
    }

    private function readOf(Node $node, Scope $scope): ?string
    {
        if ($node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name) {
            $function = strtolower($node->name->toString());
            if (in_array($function, self::HELPERS, true)) {
                return $function.'()';
            }
            if (in_array($function, self::RESOLVERS, true)) {
                $target = $this->resolvedTarget($node->getArgs()[0] ?? null, $scope);

                return $target === null ? null : $function.'('.$target.')';
            }
        }

        if ($node instanceof Node\Expr\StaticCall && $node->class instanceof Node\Name && $node->name instanceof Node\Identifier) {
            $class = $scope->resolveName($node->class);
            $method = $node->name->toString();
            if (in_array($class, self::FACADES, true)
                || ($class === 'Illuminate\Support\Facades\App' && in_array($method, self::LOCALE_METHODS, true))) {
                return $class.'::'.$method.'()';
            }
        }

        if ($node instanceof Node\Expr\MethodCall && $node->name instanceof Node\Identifier) {
            $method = $node->name->toString();
            if (in_array($method, self::MAKE_METHODS, true)) {
                $target = $this->resolvedTarget($node->getArgs()[0] ?? null, $scope);

                return $target === null ? null : 'make('.$target.')';
            }
            if (in_array($method, self::LOCALE_METHODS, true)) {
                return $method.'()';
            }
        }

        if ($node instanceof Node\Expr\ArrayDimFetch && $node->dim instanceof Node\Scalar\String_
            && in_array($node->dim->value, self::ALIASES, true) && $node->var instanceof Node\Expr\Variable
            && is_string($node->var->name)) {
            return sprintf("\$%s['%s']", $node->var->name, $node->dim->value);
        }

        return null;
    }

    /**
     * The name of the request state a resolver argument points to, or null.
     */
    private function resolvedTarget(?Node\Arg $arg, Scope $scope): ?string
    {
        if ($arg === null) {
            return null;
        }
        if ($arg->value instanceof Node\Scalar\String_) {
            $name = $arg->value->value;

            return in_array($name, self::ALIASES, true) || self::matches($name, self::TYPES, $this->reflectionProvider) ? $name : null;
        }
        if ($arg->value instanceof Node\Expr\ClassConstFetch && $arg->value->class instanceof Node\Name
            && $arg->value->name instanceof Node\Identifier && $arg->value->name->toLowerString() === 'class') {
            $name = $scope->resolveName($arg->value->class);

            return self::matches($name, self::TYPES, $this->reflectionProvider) ? $name : null;
        }

        return null;
    }

    /**
     * @param  list<string>  $types
     */
    private static function matches(string $typeName, array $types, ReflectionProvider $reflectionProvider): bool
    {
        if (! $reflectionProvider->hasClass($typeName)) {
            return false;
        }
        $class = $reflectionProvider->getClass($typeName);
        foreach ($types as $type) {
            if ($class->is($type)) {
                return true;
            }
        }

        return false;
    }
}
