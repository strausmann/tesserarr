<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Http\Ui;

use App\Authorization\Permission;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Which navigation the app frame shows: the friend's (catalogue, tokens,
 * account) or the operator's (status, media, operations, administration).
 * See resources/js/lib/navigation.ts.
 *
 * An account with at least one administrative permission
 * (Permission::administrative()) gets the operator navigation, every other
 * signed-in account the friend's; a guest has none. The permissions are asked
 * through the gate, that is through the Authorizer.
 */
enum Audience: string
{
    case Guest = 'guest';
    case Friend = 'friend';
    case Operator = 'operator';

    public static function of(?User $user): self
    {
        return $user === null ? self::Guest : self::fromGranted(self::granted($user));
    }

    /**
     * The permissions the gate grants $user in this request, in catalogue order.
     *
     * @return list<Permission>
     */
    public static function granted(User $user): array
    {
        $gate = Gate::forUser($user);

        return array_values(array_filter(Permission::cases(), fn (Permission $p) => $gate->allows($p->value)));
    }

    /** @param  list<Permission>  $granted */
    public static function fromGranted(array $granted): self
    {
        return array_intersect(Permission::values($granted), Permission::values(Permission::administrative())) === []
            ? self::Friend
            : self::Operator;
    }
}
