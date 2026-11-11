<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Database Design Document §3: tenants, tenant_hierarchy_closure,
 * tenant_feature_flags, roles_permissions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('parent_tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->enum('type', ['master', 'white_label', 'agent', 'sub_agent', 'api_partner']);
            $table->string('name', 191);
            $table->string('legal_name')->nullable();
            $table->string('custom_domain')->nullable()->unique();
            $table->string('subdomain', 100)->nullable()->unique();
            $table->enum('status', ['pending', 'active', 'suspended', 'closed'])->default('pending');
            $table->unsignedTinyInteger('max_sub_tenant_depth')->default(5);
            $table->json('config')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('tenant_hierarchy_closure', function (Blueprint $table) {
            $table->unsignedBigInteger('ancestor_tenant_id');
            $table->unsignedBigInteger('descendant_tenant_id');
            $table->unsignedSmallInteger('depth');

            $table->primary(['ancestor_tenant_id', 'descendant_tenant_id']);
            $table->index('descendant_tenant_id');
            $table->index('ancestor_tenant_id');

            $table->foreign('ancestor_tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('descendant_tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });

        Schema::create('tenant_feature_flags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->enum('module_key', ['flight', 'hotel', 'bus', 'cab', 'holiday', 'insurance', 'visa', 'cruise']);
            $table->boolean('is_enabled')->default(true);
            $table->json('ai_features_enabled')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'module_key']);
        });

        Schema::create('roles_permissions', function (Blueprint $table) {
            $table->id();
            $table->string('role_key', 50);
            $table->string('permission_key', 100);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['role_key', 'permission_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roles_permissions');
        Schema::dropIfExists('tenant_feature_flags');
        Schema::dropIfExists('tenant_hierarchy_closure');
        Schema::dropIfExists('tenants');
    }
};
