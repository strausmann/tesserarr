<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Privacy\BlindIndex;
use App\Privacy\PiiEncrypter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Personal data at rest (task 8a, Spec 16.16).
 *
 * - users.email (the confirmed account address), users.pending_email (the
 *   address waiting for its confirmation link), users.pgp_public_key (task 10a)
 *   and identities.email (the address a provider reported) become ciphertext
 *   of App\Privacy\PiiEncrypter (`text`); each address gets a blind index
 *   column (HMAC, 64 hex characters) to find it by.
 * - account_email_claims holds one row per account address in use, confirmed
 *   or reserved for 24 hours; its primary key on the blind index is the one
 *   place where an address is unique (pre-flight K16). users.email_bidx and
 *   pending_email_bidx are plain indexes.
 * - email_confirmations holds the single-use links: only the SHA-256 of the
 *   token, valid for 60 minutes.
 *
 * In place for installations that ran an earlier release candidate: an
 * address the starter kit had verified (email_verified_at) becomes the
 * confirmed account address of its account, unless an older account already
 * holds the same address; every other address is removed, because it was
 * never confirmed and could not be used under the new rules. Identity
 * addresses are kept. Rows to convert need TESSERARR_PII_KEY and
 * TESSERARR_BLIND_INDEX_KEY; without rows nothing is read.
 */
return new class extends Migration
{
    public function up(): void
    {
        $legacy = DB::table('users')->whereNotNull('email')->orderBy('id')->get(['id', 'email', 'email_verified_at']);
        $identities = DB::table('identities')->whereNotNull('email')->orderBy('id')->get(['id', 'email']);

        Schema::table('users', function (Blueprint $table) {
            $table->text('email')->nullable()->change();
            $table->char('email_bidx', 64)->nullable()->index();
            $table->timestamp('email_confirmed_at')->nullable();
            $table->text('pending_email')->nullable();
            $table->char('pending_email_bidx', 64)->nullable()->index();
            $table->timestamp('pending_email_reserved_until')->nullable();
            $table->text('pgp_public_key')->nullable();
        });

        Schema::table('identities', function (Blueprint $table) {
            $table->text('email')->nullable()->change();
            $table->char('email_bidx', 64)->nullable()->index();
        });

        Schema::create('account_email_claims', function (Blueprint $table) {
            $table->char('bidx', 64)->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 16);
            $table->timestamp('reserved_until')->nullable();
            $table->timestamp('created_at')->useCurrent();

            // One confirmed and at most one reserved address per account.
            $table->unique(['user_id', 'kind']);
        });
        DB::statement("alter table account_email_claims add constraint account_email_claims_kind check (kind in ('confirmed', 'pending'))");
        DB::statement("alter table account_email_claims add constraint account_email_claims_reservation check ((kind = 'pending') = (reserved_until is not null))");

        Schema::create('email_confirmations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->char('email_bidx', 64);
            $table->char('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        if ($legacy->isEmpty() && $identities->isEmpty()) {
            return;
        }

        $pii = app(PiiEncrypter::class);
        $claimed = [];

        foreach ($legacy as $row) {
            $bidx = BlindIndex::of((string) $row->email);

            if ($row->email_verified_at === null || isset($claimed[$bidx])) {
                DB::table('users')->where('id', $row->id)->update(['email' => null]);

                continue;
            }

            $claimed[$bidx] = true;
            DB::table('users')->where('id', $row->id)->update([
                'email' => $pii->encrypt((string) $row->email),
                'email_bidx' => $bidx,
                'email_confirmed_at' => $row->email_verified_at,
            ]);
            DB::table('account_email_claims')->insert(['bidx' => $bidx, 'user_id' => $row->id, 'kind' => 'confirmed']);
        }

        foreach ($identities as $row) {
            DB::table('identities')->where('id', $row->id)->update([
                'email' => $pii->encrypt((string) $row->email),
                'email_bidx' => BlindIndex::of((string) $row->email),
            ]);
        }
    }

    public function down(): void
    {
        // Forward only: a release is rolled back with the backup of tesserarr:migrate.
    }
};
