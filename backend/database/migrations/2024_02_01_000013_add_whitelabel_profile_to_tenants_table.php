<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * White-label onboarding fields — "add the domain to our whitelabel API
 * users and need to give option of choose templates... need a option to
 * Map DNS and set their profile detail and business details... choose what
 * are all service we're giving in whitelabel". All nullable additions, no
 * `->change()` on existing columns (no doctrine/dbal in this project).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->unsignedTinyInteger('template_id')->nullable()->after('subdomain');
            $table->string('logo_url', 500)->nullable()->after('template_id');
            $table->string('support_email', 191)->nullable()->after('logo_url');
            $table->string('support_phone', 30)->nullable()->after('support_email');
            $table->text('business_address')->nullable()->after('support_phone');
            $table->string('business_registration_no', 100)->nullable()->after('business_address');
            $table->enum('dns_status', ['not_configured', 'pending', 'verified'])->default('not_configured')->after('business_registration_no');
            $table->timestamp('dns_verified_at')->nullable()->after('dns_status');
            // Which of the 8 Product Modules this white-label site exposes
            // to its own end customers — null means "all modules" (the
            // pre-existing default for every tenant created before this
            // migration, and for any tenant that never restricts itself).
            $table->json('enabled_modules')->nullable()->after('dns_verified_at');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn([
                'template_id', 'logo_url', 'support_email', 'support_phone',
                'business_address', 'business_registration_no', 'dns_status',
                'dns_verified_at', 'enabled_modules',
            ]);
        });
    }
};
