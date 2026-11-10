<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Database Design Document §3.2.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('uuid')->unique();
            $table->string('name', 191);
            $table->string('email', 191)->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('password_hash')->nullable();
            $table->enum('role', [
                'super_admin', 'staff', 'partner_admin', 'agent', 'sub_agent',
                'staff_user', 'api_user', 'customer',
            ]);
            $table->enum('status', ['invited', 'active', 'suspended', 'deactivated'])->default('invited');
            $table->text('two_factor_secret')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'email']);
            $table->unique(['tenant_id', 'phone']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
