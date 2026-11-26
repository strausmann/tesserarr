<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Tests\PHPStan\Rules\Authorization;

use Illuminate\Database\Eloquent\Builder;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;

/**
 * An account is never found by its e-mail address (Spec 16.16): the address
 * is optional contact data, not unique, and not a sign-in name. Reports a
 * query of the user model that filters or matches on `email`:
 * where('email', …), whereEmail(…), firstWhere('email', …), where(['email' => …]),
 * firstOrCreate/updateOrCreate/firstOrNew(['email' => …]), on the model or on
 * one of its query builders.
 *
 * Limits: a query over DB::table('users') and a column name built at runtime
 * are not recognised.
 *
 * @implements Rule<Node\Expr\CallLike>
 */
final class NoUserLookupByEmailRule implements Rule
{
    private const COLUMN_METHODS = ['where', 'orwhere', 'firstwhere', 'wherein', 'orwherein', 'wherelike', 'orwherelike'];

    private const ATTRIBUTE_METHODS = ['firstorcreate', 'updateorcreate', 'firstornew', 'createorfirst', 'where', 'orwhere'];

    /**
     * @param  list<string>  $userClasses  the account models, e.g. App\Models\User
     */
    public function __construct(private readonly array $userClasses) {}

    public function getNodeType(): string
    {
        return Node\Expr\CallLike::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (! ($node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\StaticCall)
            || ! $node->name instanceof Node\Identifier) {
            return [];
        }

        $method = $node->name->toLowerString();
        if (! $this->looksUpByEmail($method, array_values($node->getArgs())) || ! $this->onUserModel($node, $scope)) {
            return [];
        }

        return [
            RuleErrorBuilder::message('An account is never looked up by its e-mail address: the address is contact data and not unique. Look the account up by username or identity (Spec 16.16).')
                ->identifier('tesserarr.authorization.userByEmail')
                ->build(),
        ];
    }

    /**
     * @param  list<Node\Arg>  $args
     */
    private function looksUpByEmail(string $method, array $args): bool
    {
        if ($method === 'whereemail' || $method === 'orwhereemail') {
            return true;
        }

        $first = $args[0]->value ?? null;

        if (in_array($method, self::COLUMN_METHODS, true) && $first instanceof Node\Scalar\String_) {
            return self::isEmailColumn($first->value);
        }

        if (in_array($method, self::ATTRIBUTE_METHODS, true) && $first instanceof Node\Expr\Array_) {
            foreach ($first->items as $item) {
                if ($item->key instanceof Node\Scalar\String_ && self::isEmailColumn($item->key->value)) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function isEmailColumn(string $column): bool
    {
        return $column === 'email' || str_ends_with($column, '.email');
    }

    private function onUserModel(Node\Expr\MethodCall|Node\Expr\StaticCall $node, Scope $scope): bool
    {
        if ($node instanceof Node\Expr\StaticCall) {
            return $node->class instanceof Node\Name && in_array($scope->resolveName($node->class), $this->userClasses, true);
        }

        $type = $scope->getType($node->var);

        foreach ($this->userClasses as $class) {
            if ((new ObjectType($class))->isSuperTypeOf($type)->yes() || $this->isBuilderOf($type, $class)) {
                return true;
            }
        }

        return false;
    }

    private function isBuilderOf(Type $type, string $class): bool
    {
        if (! (new ObjectType(Builder::class))->isSuperTypeOf($type)->yes()) {
            return false;
        }

        $model = $type->getTemplateType(Builder::class, 'TModel');

        return (new ObjectType($class))->isSuperTypeOf($model)->yes();
    }
}
