<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * api_partner_credentials (2024_01_01_000005_create_supplier_tables.php) was
 * scaffolded early with client_id/client_secret_hash/allowed_scopes/
 * rate_limit_per_min, but no controller or UI was ever built against it —
 * the Tenants admin screen showed every tenant type the same "White-label
 * settings" link, which made no sense for an API Partner tenant (they don't
 * get a storefront at all): "For API users we will generate API for
 * Specific domain and they will integrate in their domain" (Platform
 * Roadmap). This adds the two fields that were missing for that: a human
 * `label` so a partner with several credentials can tell them apart, and
 * `allowed_domain` — the one domain this credential is meant to be
 * integrated from. Nullable at the column level (existing rows, if any,
 * have neither) but required by ApiPartnerCredentialController::store()'s
 * validation for every new credential going forward.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_partner_credentials', function (Blueprint $table) {
            $table->string('label')->nullable()->after('tenant_id');
            $table->string('allowed_domain')->nullable()->after('client_secret_hash');
        });
    }

    public function down(): void
    {
        Schema::table('api_partner_credentials', function (Blueprint $table) {
            $table->dropColumn(['label', 'allowed_domain']);
        });
    }
};
