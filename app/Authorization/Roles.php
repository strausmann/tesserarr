<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Authorization;

/**
 * The two built-in roles. Their permissions are fixed in code and cannot be
 * changed, not even with `roles.manage`; own roles sit between them.
 */
final class Roles
{
    public const OPERATOR = 'operator';

    public const USER = 'user';

    /** Every role lives on the session guard; tokens carry abilities, not roles. */
    public const GUARD = 'web';

    /** @return list<string> */
    public static function builtIn(): array
    {
        return [self::OPERATOR, self::USER];
    }

    public static function isBuiltIn(string $name): bool
    {
        return in_array(mb_strtolower($name), self::builtIn(), true);
    }

    /**
     * The fixed permissions of a built-in role.
     *
     * @return list<Permission>
     */
    public static function permissionsOf(string $builtIn): array
    {
        return match ($builtIn) {
            self::OPERATOR => Permission::assignableToUsers(),
            self::USER => [Permission::CatalogBrowse, Permission::TokensManageOwn],
            default => throw new \InvalidArgumentException("\"{$builtIn}\" is no built-in role"),
        };
    }
}
