<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Role names are unique without regard to case ("Helper" and "helper" are
 * one role), in the database: the check of the role page alone would let two
 * requests at the same time create both.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('create unique index roles_name_lower_unique on roles (lower(name), guard_name)');
    }

    public function down(): void
    {
        // Forward only: a release is rolled back with the backup of tesserarr:migrate.
    }
};
