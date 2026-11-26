<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Tests\PHPStan\Rules\Privacy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;

/**
 * Only App\Privacy\EmailConfirmation writes the account address (task 8a,
 * Nachprüfung P17): users.email, email_bidx, email_confirmed_at,
 * email_verified_at, pending_email*, and the table account_email_claims. No
 * sign-in, no redemption, no controller sets them; App\Privacy\PiiRotation
 * may rewrite them under a new key. The model keeps the columns out of mass
 * assignment as well ($guarded); this rule is the second line.
 *
 * Reports, in code of the App namespace outside the allowed classes:
 *
 * - an assignment to one of the properties on the user model
 *   ($user->email = …, also ??= and the like), and setAttribute('email', …);
 * - fill, forceFill, update, create and their kin with an array literal that
 *   names one of the columns, on the model, its builders or statically;
 * - a write of the query builder (insert, update, upsert, updateOrInsert,
 *   delete, …) on table('users') with one of the columns, and any write on
 *   table('account_email_claims').
 *
 * Limits: raw SQL (DB::update('update users …')), a column name built at
 * runtime and a table name in a variable are not recognised.
 *
 * @implements Rule<Node\Expr>
 */
final class OnlyEmailConfirmationWritesAccountEmailRule implements Rule
{
    public const MESSAGE = 'Only App\Privacy\EmailConfirmation writes the account address (users.email, its blind index, the confirmation and reservation columns, account_email_claims). No sign-in writes it; a provider address belongs to the identity (Spec 16.16, task 8a).';

    public const FIELDS = [
        'email',
        'email_bidx',
        'email_confirmed_at',
        'email_verified_at',
        'pending_email',
        'pending_email_bidx',
        'pending_email_reserved_until',
    ];

    private const MASS_METHODS = [
        'fill', 'forcefill', 'update', 'updatequietly', 'create', 'forcecreate', 'forcecreatequietly', 'createquietly',
        'updateorcreate', 'firstorcreate', 'firstornew', 'createorfirst', 'make', 'newmodelinstance', 'insert', 'upsert',
    ];

    private const QUERY_WRITES = ['insert', 'insertorignore', 'insertgetid', 'insertusing', 'update', 'upsert', 'updateorinsert', 'delete', 'increment', 'decrement', 'truncate'];

    /**
     * @param  list<string>  $userClasses  the account models, e.g. App\Models\User
     * @param  list<string>  $allowedClasses  the classes that may write
     */
    public function __construct(
        private readonly array $userClasses,
        private readonly array $allowedClasses,
    ) {}

    public function getNodeType(): string
    {
        return Node\Expr::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (! str_starts_with($scope->getNamespace() ?? '', 'App')) {
            return [];
        }

        $class = $scope->getClassReflection()?->getName();
        if ($class !== null && in_array($class, $this->allowedClasses, true)) {
            return [];
        }

        if (! $this->writes($node, $scope)) {
            return [];
        }

        return [
            RuleErrorBuilder::message(self::MESSAGE)
                ->identifier('tesserarr.privacy.accountEmailWrite')
                ->build(),
        ];
    }

    private function writes(Node $node, Scope $scope): bool
    {
        if ($node instanceof Node\Expr\Assign || $node instanceof Node\Expr\AssignOp || $node instanceof Node\Expr\AssignRef) {
            $target = $node->var;

            return $target instanceof Node\Expr\PropertyFetch
                && $target->name instanceof Node\Identifier
                && in_array($target->name->toString(), self::FIELDS, true)
                && $this->isUser($scope->getType($target->var));
        }

        if (! ($node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\StaticCall) || ! $node->name instanceof Node\Identifier) {
            return false;
        }

        $method = $node->name->toLowerString();
        $args = array_values($node->getArgs());

        $table = $node instanceof Node\Expr\MethodCall ? self::tableOf($node->var) : null;
        if ($table !== null && in_array($method, self::QUERY_WRITES, true)) {
            return $table === 'account_email_claims' || ($table === 'users' && self::namesAField($args));
        }

        if ($method === 'setattribute' || $method === 'setrawattributes') {
            $first = $args[0]->value ?? null;

            return $this->onUser($node, $scope) && (
                ($first instanceof Node\Scalar\String_ && in_array($first->value, self::FIELDS, true))
                || ($first instanceof Node\Expr\Array_ && self::namesAField($args))
            );
        }

        return in_array($method, self::MASS_METHODS, true) && self::namesAField($args) && $this->onUser($node, $scope);
    }

    /** The table of a query builder chain that started with table('…') (DB facade or a connection). */
    private static function tableOf(Node\Expr $expr): ?string
    {
        while ($expr instanceof Node\Expr\MethodCall || $expr instanceof Node\Expr\StaticCall) {
            if ($expr->name instanceof Node\Identifier && $expr->name->toLowerString() === 'table') {
                $first = $expr->getArgs()[0]->value ?? null;

                return $first instanceof Node\Scalar\String_ ? $first->value : null;
            }
            if (! $expr instanceof Node\Expr\MethodCall) {
                return null;
            }
            $expr = $expr->var;
        }

        return null;
    }

    /**
     * @param  list<Node\Arg>  $args
     */
    private static function namesAField(array $args): bool
    {
        foreach ($args as $arg) {
            if (! $arg->value instanceof Node\Expr\Array_) {
                continue;
            }
            foreach ($arg->value->items as $item) {
                if ($item->key instanceof Node\Scalar\String_ && in_array($item->key->value, self::FIELDS, true)) {
                    return true;
                }
                // A list of rows (insert([[…], […]])).
                if ($item->value instanceof Node\Expr\Array_ && self::namesAField([new Node\Arg($item->value)])) {
                    return true;
                }
            }
        }

        return false;
    }

    private function onUser(Node\Expr\MethodCall|Node\Expr\StaticCall $node, Scope $scope): bool
    {
        if ($node instanceof Node\Expr\StaticCall) {
            return $node->class instanceof Node\Name && in_array($scope->resolveName($node->class), $this->userClasses, true);
        }

        $type = $scope->getType($node->var);
        if ($this->isUser($type)) {
            return true;
        }

        if (! (new ObjectType(Builder::class))->isSuperTypeOf($type)->yes()) {
            return false;
        }

        return $this->isUser($type->getTemplateType(Builder::class, 'TModel'));
    }

    private function isUser(Type $type): bool
    {
        if (! (new ObjectType(Model::class))->isSuperTypeOf($type)->yes()) {
            return false;
        }

        foreach ($this->userClasses as $class) {
            if ((new ObjectType($class))->isSuperTypeOf($type)->yes()) {
                return true;
            }
        }

        return false;
    }
}
