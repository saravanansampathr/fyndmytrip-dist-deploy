<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Database Design Document §8. credentials_encrypted uses TEXT rather than
 * the documented VARBINARY(2048): Eloquent's `encrypted:array` cast (Laravel
 * envelope encryption keyed from APP_KEY, matching the "encrypted at rest"
 * requirement) serialises to a base64 string, not raw binary, so TEXT is
 * the correct column type for that cast. Swapping in AWS Secrets Manager/
 * Vault-backed encryption per the Security, Compliance & DevOps Plan is a
 * cast-class change, not a schema change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 100);
            $table->json('supported_modules');
            $table->string('adapter_class', 150);
            $table->timestamps();
        });

        Schema::create('markup_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->enum('module', ['flight', 'hotel', 'bus', 'cab', 'holiday', 'insurance', 'visa', 'cruise']);
            $table->enum('rule_type', ['flat', 'percentage', 'slab']);
            $table->json('config');
            $table->enum('applies_to_role', ['agent', 'sub_agent', 'b2c']);
            $table->timestamps();
        });

        Schema::create('tenant_supplier_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained('suppliers')->cascadeOnDelete();
            $table->text('credentials_encrypted');
            $table->foreignId('markup_rule_id')->nullable()->constrained('markup_rules')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->unsignedTinyInteger('priority_order')->default(1);
            $table->timestamps();

            $table->unique(['tenant_id', 'supplier_id']);
        });

        Schema::create('api_partner_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('client_id')->unique();
            $table->string('client_secret_hash');
            $table->json('allowed_scopes');
            $table->unsignedInteger('rate_limit_per_min')->default(60);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_partner_credentials');
        Schema::dropIfExists('tenant_supplier_configs');
        Schema::dropIfExists('markup_rules');
        Schema::dropIfExists('suppliers');
    }
};
