<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two settings of the interface per user: the language (null: the browser
 * decides, see App\Http\Middleware\SetLocale) and which sections the user
 * collapsed (`ui.collapsed`, a map of section key to bool, see
 * App\Http\Ui\CollapsibleSection). The map is never null, so a single key can
 * be set atomically with jsonb_set.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('locale', 8)->nullable();
            $table->jsonb('ui_collapsed')->default('{}');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['locale', 'ui_collapsed']);
        });
    }
};
