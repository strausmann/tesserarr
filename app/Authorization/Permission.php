<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Authorization;

/**
 * The one catalogue of permissions for roles and API tokens (ADR-0016). The
 * value is the name of the gate, of the `can:` middleware and of the token
 * ability. A new permission is added here and to RolesSeeder in a migration.
 */
enum Permission: string
{
    case UsersView = 'users.view';
    case UsersManage = 'users.manage';
    case RolesManage = 'roles.manage';
    case InvitationsCreate = 'invitations.create';
    case InvitationsRevoke = 'invitations.revoke';
    case TokensManageOwn = 'tokens.manage_own';
    case TokensManageAll = 'tokens.manage_all';
    case LoginMethodsManage = 'login_methods.manage';
    case SharesManage = 'shares.manage';
    case GrantsManage = 'grants.manage';
    case CatalogBrowse = 'catalog.browse';
    case CatalogViewAll = 'catalog.view_all';
    case ConnectionsView = 'connections.view';
    case ConnectionsManage = 'connections.manage';
    case SatellitesManage = 'satellites.manage';
    case StatusView = 'status.view';
    case AuditView = 'audit.view';
    case SettingsManage = 'settings.manage';
    case SatelliteExecute = 'satellite.execute';

    /**
     * Everything a role (and with it a user) may hold: all permissions except
     * `satellite.execute`, which only satellite tokens carry.
     *
     * @return list<self>
     */
    public static function assignableToUsers(): array
    {
        $assignable = [];
        foreach (self::cases() as $permission) {
            if ($permission !== self::SatelliteExecute) {
                $assignable[] = $permission;
            }
        }

        return $assignable;
    }

    /**
     * The permissions that make an account an administrator: every
     * permission except browsing the catalogue, the own tokens and
     * `satellite.execute`. Holding one of them puts the account on the
     * operator's side of the interface (and, from task 12, under the MFA rule).
     *
     * @return list<self>
     */
    public static function administrative(): array
    {
        $plain = [self::CatalogBrowse, self::TokensManageOwn, self::SatelliteExecute];

        return array_values(array_filter(self::cases(), fn (self $p) => ! in_array($p, $plain, true)));
    }

    /**
     * @param  list<string>  $values
     * @return list<self>
     */
    public static function fromValues(array $values): array
    {
        return array_map(fn (string $value) => self::from($value), $values);
    }

    /**
     * @param  list<self>  $permissions
     * @return list<string>
     */
    public static function values(array $permissions): array
    {
        return array_map(fn (self $p) => $p->value, $permissions);
    }

    /**
     * $permissions in catalogue order, without duplicates.
     *
     * @param  list<self>  $permissions
     * @return list<self>
     */
    public static function ordered(array $permissions): array
    {
        return array_values(array_filter(self::cases(), fn (self $p) => in_array($p, $permissions, true)));
    }
}
