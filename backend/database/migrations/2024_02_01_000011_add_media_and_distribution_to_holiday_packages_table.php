<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two additions to the DMC package catalog, both from real-world usage of
 * the admin CRM:
 *
 * 1. A package needs more than one cover photo — a gallery of images plus
 *    optional video links — so `holiday_package_media` is a child table
 *    (same day-by-day/price-tier pattern as itinerary/price tiers) rather
 *    than more columns on holiday_packages.
 *
 * 2. Distribution control for tenant-owned packages. A partner/agent/
 *    white-label tenant can now add their own inventory (tenant_id set,
 *    supplier_code=manual) via the same admin CRM; by default that listing
 *    is only visible on their own storefront (existing tenant_id scoping).
 *    Two independent opt-ins widen that:
 *      - share_on_platform_site: also list it on FyndMyTrip's own direct
 *        (no-tenant) site.
 *      - share_with_network: list it everywhere — every other tenant
 *        (partner/agent reselling), and by extension every API/white-label
 *        consumer and both B2C and B2B channels, since they all read the
 *        same catalog through the same tenant-scoped search endpoint.
 *    Platform-wide packages (tenant_id NULL) are already visible to
 *    everyone, so these two flags are meaningful only for tenant-owned rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('holiday_packages', function (Blueprint $table) {
            $table->boolean('share_on_platform_site')->default(false)->after('tenant_id');
            $table->boolean('share_with_network')->default(false)->after('share_on_platform_site');
        });

        Schema::create('holiday_package_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('holiday_package_id')->constrained('holiday_packages')->cascadeOnDelete();
            $table->enum('media_type', ['image', 'video'])->default('image');
            $table->string('url', 500);
            $table->string('caption', 255)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            // Explicit short name — same MySQL 64-char identifier limit fix
            // as the itinerary-days and price-tiers tables above.
            $table->index(['holiday_package_id', 'media_type', 'sort_order'], 'hp_media_pkg_type_sort_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('holiday_package_media');

        Schema::table('holiday_packages', function (Blueprint $table) {
            $table->dropColumn(['share_on_platform_site', 'share_with_network']);
        });
    }
};
