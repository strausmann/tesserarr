<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            // The sign-in name: lower case NFC, never with "@" (App\Identity\Rules\Username).
            $table->string('username', 64)->unique();
            $table->string('display_name')->default('');
            // The contact address: optional, not unique, never a sign-in name
            // (Spec 16.16; task 8a encrypts it and adds the confirmation).
            $table->string('email')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            // Null for an account that signs in with a passkey or a provider only.
            $table->string('password')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
