<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The audit log (ADR-0009). Append-only at database level: the triggers
 * refuse every UPDATE, DELETE and TRUNCATE, so neither the
 * application nor a forgotten query can rewrite what happened. Actors and
 * subjects are kept as kind and id, never as an e-mail address.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_entries', function (Blueprint $table) {
            $table->id();
            $table->timestampTz('occurred_at')->useCurrent()->index();
            $table->string('action', 64)->index();
            $table->string('actor_type', 16);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_label')->nullable();
            $table->string('subject_type', 64)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->jsonb('context')->default('{}');
            $table->string('outcome', 16)->default('done');

            $table->index(['actor_type', 'actor_id']);
            $table->index(['subject_type', 'subject_id']);
        });

        // OR REPLACE: migrate:fresh drops the tables, not the function.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION audit_entries_append_only() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'audit_entries is append-only: % refused', TG_OP
                    USING ERRCODE = 'insufficient_privilege';
            END;
            $$;

            CREATE TRIGGER audit_entries_append_only
                BEFORE UPDATE OR DELETE ON audit_entries
                FOR EACH ROW EXECUTE FUNCTION audit_entries_append_only();

            CREATE TRIGGER audit_entries_no_truncate
                BEFORE TRUNCATE ON audit_entries
                FOR EACH STATEMENT EXECUTE FUNCTION audit_entries_append_only();
            SQL);
    }

    public function down(): void
    {
        // Forward only: a release is rolled back with the backup of tesserarr:migrate.
    }
};
