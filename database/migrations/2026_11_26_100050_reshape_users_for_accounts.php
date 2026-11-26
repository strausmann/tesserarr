<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Identity\UsernameDeriver;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * From the starter kit's accounts to Tesserarr's (Spec 16.16): an account is
 * identified by its username; the e-mail address becomes an optional contact
 * address that is not unique and never a sign-in name; the password is
 * optional (passkey or provider only); an account can be locked.
 *
 * In place, for installations that ran the kit's users table before (the
 * 0.1.0 release candidates): existing rows get a username derived from their
 * address (UsernameDeriver: the part before "@", lower case, numbered when
 * taken) and keep their name as display name. The kit's `name` column only
 * becomes nullable; it is dropped in the following minor release (expand,
 * then contract).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 64)->nullable();
            $table->string('display_name')->default('');
            $table->timestamp('locked_at')->nullable();
            $table->string('name')->nullable()->change();
            $table->string('password')->nullable()->change();
            $table->string('email')->nullable()->change();
            $table->dropUnique('users_email_unique');
        });

        $deriver = app(UsernameDeriver::class);
        foreach (DB::table('users')->orderBy('id')->get(['id', 'name', 'email']) as $row) {
            DB::table('users')->where('id', $row->id)->update([
                'username' => $deriver->derive((string) ($row->email ?? $row->name ?? '')),
                'display_name' => (string) ($row->name ?? ''),
            ]);
        }

        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 64)->nullable(false)->change();
            $table->unique('username');
        });
    }

    public function down(): void
    {
        // Forward only: a release is rolled back with the backup of tesserarr:migrate.
    }
};
