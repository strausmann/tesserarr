<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Invitations (task 10, Spec 9.1): the only way into the instance.
 *
 * - token_hash is the SHA-256 of the token; the token itself is never stored.
 * - kind "access" makes a new account, "recovery" binds a new sign-in method
 *   to the existing account for_user_id (task 11 adds "setup").
 * - The reservation columns belong to the password and passkey path of
 *   task 11 (InvitationStore::reserve); App\Invitations\InvitationStore is the
 *   only code that writes redeemed_at and the reservation columns.
 * - note is a label the operator chose ("for Sam"): it may name a person and
 *   is encrypted at rest (EncryptedPii, PiiRotation).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invitations', function (Blueprint $table) {
            $table->id();
            $table->char('token_hash', 64)->unique();
            $table->string('kind', 16);
            $table->foreignId('for_user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->text('note')->nullable();
            $table->jsonb('created_by_actor');
            $table->timestampTz('expires_at');
            $table->timestampTz('redeemed_at')->nullable();
            $table->foreignId('redeemed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('redeemed_ip', 45)->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampTz('reserved_until')->nullable();
            $table->char('reservation_hash', 64)->nullable();
            $table->unsignedBigInteger('reserved_user_id')->nullable();
            $table->integer('reservation_count')->default(0);
            $table->timestampsTz();

            $table->index(['redeemed_at', 'revoked_at', 'expires_at']);
        });

        DB::statement("alter table invitations add constraint invitations_kind check (kind in ('access', 'recovery'))");
        // A recovery invitation always names its account; an access invitation never does.
        DB::statement("alter table invitations add constraint invitations_recovery_user check ((kind = 'recovery') = (for_user_id is not null))");
    }

    public function down(): void
    {
        // Forward only: a release is rolled back with the backup of tesserarr:migrate.
    }
};
