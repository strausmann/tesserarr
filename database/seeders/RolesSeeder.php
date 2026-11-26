<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Database\Seeders;

use App\Authorization\Permission;
use App\Authorization\Roles;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Brings the permission catalogue and the two built-in roles into the
 * database: one row per Permission case (satellite.execute included, for
 * satellite tokens), `operator` with everything assignable to users, `user`
 * with browsing the catalogue and the own tokens. Idempotent: run by the
 * migration 2026_11_26_100500_seed_built_in_roles and by DatabaseSeeder.
 * Own roles are never touched; the built-in ones are set back to their fixed
 * permissions.
 *
 * Plain queries instead of spatie's models: a migration must not depend on
 * the permission cache or on model events.
 */
final class RolesSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        DB::table('permissions')->upsert(
            array_map(fn (Permission $p) => ['name' => $p->value, 'guard_name' => Roles::GUARD, 'created_at' => $now, 'updated_at' => $now], Permission::cases()),
            ['name', 'guard_name'],
            ['updated_at'],
        );

        DB::table('roles')->upsert(
            array_map(fn (string $role) => ['name' => $role, 'guard_name' => Roles::GUARD, 'created_at' => $now, 'updated_at' => $now], Roles::builtIn()),
            ['name', 'guard_name'],
            ['updated_at'],
        );

        $permissionIds = DB::table('permissions')->where('guard_name', Roles::GUARD)->pluck('id', 'name');

        foreach (Roles::builtIn() as $role) {
            $roleId = DB::table('roles')->where(['name' => $role, 'guard_name' => Roles::GUARD])->value('id');

            DB::table('role_has_permissions')->where('role_id', $roleId)->delete();
            DB::table('role_has_permissions')->insert(array_map(
                fn (Permission $p) => ['role_id' => $roleId, 'permission_id' => $permissionIds[$p->value]],
                Roles::permissionsOf($role),
            ));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
