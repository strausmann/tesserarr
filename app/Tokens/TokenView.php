<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Tokens;

use App\Authorization\Permission;
use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * A token as the interface shows it: the fields listed one by one, never the
 * serialised model, so neither the hash nor a later column reaches a page.
 */
final class TokenView
{
    /**
     * @return array{id: int, name: string, permissions: list<string>, created_at: string|null, last_used_at: string|null, expires_at: string|null, mine: bool, owner: array{id: int, username: string, display_name: string}|null}
     */
    public static function of(PersonalAccessToken $token, User $viewer, ?User $owner = null): array
    {
        /** @var list<string> $abilities */
        $abilities = array_values(array_filter((array) $token->getAttribute('abilities'), 'is_string'));
        $known = array_values(array_filter(array_map(fn (string $a) => Permission::tryFrom($a), $abilities)));

        return [
            'id' => (int) $token->getKey(),
            'name' => (string) $token->getAttribute('name'),
            'permissions' => Permission::values(Permission::ordered($known)),
            'created_at' => $token->created_at?->toIso8601String(),
            'last_used_at' => $token->last_used_at?->toIso8601String(),
            'expires_at' => $token->expires_at?->toIso8601String(),
            'mine' => (string) $token->getAttribute('tokenable_id') === (string) $viewer->getKey()
                && $token->getAttribute('tokenable_type') === $viewer->getMorphClass(),
            'owner' => $owner === null ? null : [
                'id' => (int) $owner->getKey(),
                'username' => (string) $owner->username,
                'display_name' => (string) $owner->display_name,
            ],
        ];
    }
}
