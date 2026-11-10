<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Volvo, Scania, Benz, Ashok layland, Eicher" — the bus results filter
 * sidebar (see App\Support\BusType) already has a "Bus brand" facet for
 * MockBusSupplierAdapter's own fictional operators, but a tenant's own
 * manually-entered bus_inventory_items row had no field to set it from —
 * BusInventoryItem::toBusOfferArray() hardcoded 'bus_brand' => null for
 * every one of them. This is the one genuinely new column that request
 * needed: unlike AC/non-AC and seat type, a chassis/vehicle brand has no
 * existing free-text field (bus_type) to parse it out of.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bus_inventory_items', function (Blueprint $table) {
            $table->string('bus_brand', 100)->nullable()->after('dropping_point');
        });
    }

    public function down(): void
    {
        Schema::table('bus_inventory_items', function (Blueprint $table) {
            $table->dropColumn('bus_brand');
        });
    }
};
