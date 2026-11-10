<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Backs AuthController::forgotPassword()/resetPassword(). Deliberately NOT
 * Laravel's stock password_reset_tokens shape (email as the sole primary
 * key) — a user's email is only unique *within* a tenant (see
 * AuthController::register()'s scoped uniqueness rule and login()'s
 * optional tenant_uuid disambiguation), so two different tenants can each
 * have a user on the same email address. The primary key here is
 * (tenant_id, email) for that reason; config('auth.passwords') /
 * Laravel's built-in Password broker is intentionally not used since it
 * has no concept of tenant scoping.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->unsignedBigInteger('tenant_id');
            $table->string('email');
            $table->string('token');
            $table->timestamp('created_at')->nullable();

            $table->primary(['tenant_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_reset_tokens');
    }
};
