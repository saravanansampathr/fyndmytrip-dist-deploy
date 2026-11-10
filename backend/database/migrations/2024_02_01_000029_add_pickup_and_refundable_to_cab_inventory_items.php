<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3's Cab storefront (the third real booking-flow vertical, after
 * Bus and Hotel): cab_inventory_items was created with only origin/
 * destination/travel_date/provider_name/car_type/included_km/
 * extra_km_fare — no pickup time or duration, unlike bus_inventory_items,
 * and no refundability flag. CabInventoryItem::toCabOfferArray() needs
 * both, same as the equivalent bus/hotel migrations added before it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cab_inventory_items', function (Blueprint $table) {
            $table->string('pickup_time', 5)->nullable()->after('travel_date');
            $table->unsignedInteger('duration_minutes')->nullable()->after('extra_km_fare');
            $table->boolean('is_refundable')->default(true)->after('duration_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('cab_inventory_items', function (Blueprint $table) {
            $table->dropColumn(['pickup_time', 'duration_minutes', 'is_refundable']);
        });
    }
};
