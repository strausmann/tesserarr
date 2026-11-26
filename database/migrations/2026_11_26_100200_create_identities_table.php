<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The sign-in identities of an account at external methods (OIDC, Plex,
 * forward-auth header). One subject of one provider belongs to one account.
 * `email` is the address the provider reported, not the account's contact
 * address (task 8a encrypts it).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('identities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('method', 32);
            $table->string('provider_key', 64);
            $table->string('subject');
            $table->string('email')->nullable();
            $table->timestamps();

            $table->unique(['method', 'provider_key', 'subject']);
        });
    }

    public function down(): void
    {
        // Forward only: a release is rolled back with the backup of tesserarr:migrate.
    }
};
