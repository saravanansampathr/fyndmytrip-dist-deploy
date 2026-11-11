<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3's fifth and final real-booking-flow vertical — Cruise. Adds the
 * same is_refundable flag Bus/Hotel/Cab/Insurance already carry, so
 * CruiseInventoryItem can report it through FareRuleResult exactly like
 * every other own-inventory model does.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cruise_inventory_items', function (Blueprint $table) {
            $table->boolean('is_refundable')->default(true)->after('cabin_type');
        });
    }

    public function down(): void
    {
        Schema::table('cruise_inventory_items', function (Blueprint $table) {
            $table->dropColumn(['is_refundable']);
        });
    }
};
