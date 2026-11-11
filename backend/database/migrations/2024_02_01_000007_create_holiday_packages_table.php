<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Holiday Management (DMC) portal's package catalog — pre-populated
 * inventory browsed by destination/category (unlike Flights, which are
 * searched live against a supplier). Packages are sourced from Akbar
 * Travels / TripJack (supplier_code + supplier_package_ref for re-sync) or
 * entered manually by staff. tenant_id NULL = platform-wide catalog entry,
 * same convention as CashbackRule/PaymentMethodRoute — packages are not
 * tenant-scoped automatically (no BelongsToTenant trait), the owning
 * controller filters manually so platform staff can curate one shared
 * catalog every tenant sells from, with room for a tenant's own exclusive
 * package later.
 *
 * Pricing carries both net_price (supplier cost) and sell_price (net +
 * markup_percent, default 10.00 per the "price it at +10%" instruction) so
 * the markup is transparent and re-computable if the percentage changes.
 * Per-occupancy pricing (adult/child/infant) lives in
 * holiday_package_price_tiers; day-by-day itinerary in
 * holiday_package_itinerary_days.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('holiday_packages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();

            $table->enum('supplier_code', ['akbar', 'tripjack', 'manual'])->default('manual');
            $table->string('supplier_package_ref', 191)->nullable();

            $table->string('slug', 191)->unique();
            $table->string('name', 191);
            $table->enum('package_type', ['tour', 'activity'])->default('tour');
            $table->enum('booking_scope', ['domestic', 'international'])->default('international');
            $table->string('category', 100)->nullable();
            $table->char('destination_country', 2)->nullable();
            $table->string('destination_city', 191)->nullable();
            $table->string('route_summary', 255)->nullable();

            $table->unsignedSmallInteger('duration_nights');
            $table->unsignedSmallInteger('duration_days');
            $table->string('max_pax_label', 100)->nullable();
            $table->string('hotel_category', 100)->nullable();

            $table->boolean('is_fixed_departure')->default(false);
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();

            $table->string('cover_image_url', 500)->nullable();

            $table->decimal('net_price', 12, 2);
            $table->decimal('markup_percent', 5, 2)->default(10.00);
            $table->decimal('sell_price', 12, 2);
            $table->char('currency', 3)->default('INR');

            $table->json('inclusions')->nullable();
            $table->json('exclusions')->nullable();
            $table->json('terms')->nullable();

            $table->enum('status', ['draft', 'active', 'paused', 'expired'])->default('draft');
            $table->timestamp('synced_at')->nullable();

            $table->timestamps();

            $table->index(['status', 'booking_scope']);
            $table->index(['destination_country']);
            $table->index(['category']);
            $table->index(['supplier_code', 'supplier_package_ref']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('holiday_packages');
    }
};
