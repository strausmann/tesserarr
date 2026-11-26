<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use Database\Seeders\RolesSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * The permission catalogue and the built-in roles belong to the schema: an
 * installation has them as soon as it is migrated, without a seeder run
 * (tesserarr:migrate runs migrations only). RolesSeeder is idempotent; a later
 * release that extends the catalogue adds a migration that runs it again.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new RolesSeeder)->run();
    }

    public function down(): void
    {
        // Forward only: a release is rolled back with the backup of tesserarr:migrate.
    }
};
