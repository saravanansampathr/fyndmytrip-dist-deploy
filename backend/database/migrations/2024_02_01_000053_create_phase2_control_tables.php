<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 admin control: who changed what (audit log) and extra rights
 * given to, or taken from, one user on top of their role.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->index();
            $table->foreignId('tenant_id')->nullable()->index();
            $table->string('action', 20);
            $table->string('subject_type', 60)->index();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('label', 191)->nullable();
            $table->json('changes')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->json('permission_overrides')->nullable()->after('role');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_audit_logs');
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('permission_overrides'));
    }
};
