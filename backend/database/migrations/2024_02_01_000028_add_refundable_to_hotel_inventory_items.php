<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3's Hotel storefront (the second real booking-flow vertical, after
 * Bus): HotelInventoryItem::toHotelOfferArray() needs to say whether a
 * manually-entered rate is refundable, same as bus_inventory_items gained
 * is_refundable in the prior migration — nights/duration doesn't apply
 * here the way it did for bus (check_in_date/check_out_date already give
 * the stay length directly).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hotel_inventory_items', function (Blueprint $table) {
            $table->boolean('is_refundable')->default(true)->after('meal_plan');
        });
    }

    public function down(): void
    {
        Schema::table('hotel_inventory_items', function (Blueprint $table) {
            $table->dropColumn('is_refundable');
        });
    }
};
