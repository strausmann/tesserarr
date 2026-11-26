<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Tests\PHPStan\Rules\Authorization;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Every permission decision goes through App\Authorization\Authorizer
 * (ADR-0016). Reports the role and permission checks of spatie's traits
 * (hasPermissionTo, hasRole and their variants) outside the classes that
 * implement the rules (Authorizer, RoleGuard, from task 12 MfaPolicy).
 *
 * Recognised by method name, whatever the receiver: a method of another
 * class with the same name is reported as well; rename it.
 *
 * @implements Rule<Node\Expr\MethodCall>
 */
final class NoDirectPermissionCheckRule implements Rule
{
    private const METHODS = [
        'haspermissionto',
        'checkpermissionto',
        'hasanypermission',
        'hasallpermissions',
        'hasdirectpermission',
        'hasalldirectpermissions',
        'hasanydirectpermission',
        'hasrole',
        'hasanyrole',
        'hasallroles',
        'hasexactroles',
    ];

    /**
     * @param  list<string>  $allowedClasses  the classes that may check directly
     * @param  list<string>  $namespaces  namespace prefixes to check
     */
    public function __construct(
        private readonly array $allowedClasses,
        private readonly array $namespaces,
    ) {}

    public function getNodeType(): string
    {
        return Node\Expr\MethodCall::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (! $node->name instanceof Node\Identifier || ! in_array($node->name->toLowerString(), self::METHODS, true)) {
            return [];
        }

        $class = $scope->getClassReflection()?->getName();
        $namespace = $scope->getNamespace() ?? '';

        $checked = false;
        foreach ($this->namespaces as $prefix) {
            if (str_starts_with($namespace.'\\', $prefix)) {
                $checked = true;
            }
        }

        if (! $checked || ($class !== null && in_array($class, $this->allowedClasses, true))) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf(
                'Call to %s() is a permission check of its own. Ask the gate (can: middleware, $this->authorize(), Gate::inspect()), which asks App\Authorization\Authorizer (ADR-0016).',
                $node->name->toString(),
            ))->identifier('tesserarr.authorization.directCheck')->build(),
        ];
    }
}
