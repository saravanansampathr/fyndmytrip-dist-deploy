<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3's Bus vertical: a manually-entered BusInventoryItem now merges
 * into real search results the same way FlightInventoryItem does (see
 * BusInventoryItem::toBusOfferArray()), which needs a duration to compute
 * an arrival time and a refundability flag to show alongside real
 * MOCKBUS-adapter offers — both already exist on flight_inventory_items,
 * added here for parity.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bus_inventory_items', function (Blueprint $table) {
            $table->unsignedInteger('duration_minutes')->nullable()->after('departure_time');
            $table->boolean('is_refundable')->default(true)->after('duration_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('bus_inventory_items', function (Blueprint $table) {
            $table->dropColumn(['duration_minutes', 'is_refundable']);
        });
    }
};
