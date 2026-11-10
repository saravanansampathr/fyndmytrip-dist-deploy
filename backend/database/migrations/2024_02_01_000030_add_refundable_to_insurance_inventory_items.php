<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3's fourth real-booking-flow vertical — Insurance. Adds the same
 * is_refundable flag Bus/Hotel/Cab already carry, so InsuranceInventoryItem
 * can report it through FareRuleResult exactly like every other own-
 * inventory model does. No trip-date columns are added here: unlike Hotel/
 * Bus/Cab (each own-inventory row IS one fixed date/route), an insurance
 * plan is date-agnostic — the customer supplies their own trip start/end
 * dates at search time and the plan's own policy_term_days is checked
 * against the trip length instead (see InsuranceBookingController::
 * ownInventoryOffers()).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('insurance_inventory_items', function (Blueprint $table) {
            $table->boolean('is_refundable')->default(true)->after('policy_term_days');
        });
    }

    public function down(): void
    {
        Schema::table('insurance_inventory_items', function (Blueprint $table) {
            $table->dropColumn(['is_refundable']);
        });
    }
};
