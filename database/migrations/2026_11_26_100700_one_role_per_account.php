<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * An account has exactly one role (Spec 9.1): the permissions of an account
 * are the permissions of its role, and the operator guard counts accounts by
 * that one role. spatie's pivot allows several; this index refuses a second.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('create unique index model_has_roles_one_role_per_model on model_has_roles (model_type, model_id)');
    }

    public function down(): void
    {
        // Forward only: a release is rolled back with the backup of tesserarr:migrate.
    }
};
