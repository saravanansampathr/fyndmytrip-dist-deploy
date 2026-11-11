<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform roadmap Phase 6 (Markup & Fare Configuration): "Markup should be
 * in like Domestic & International, In that all airlines or Difference in
 * specific airlines like that, Same to goes to Hotels, bus, Holidays." Two
 * new scope dimensions on top of the existing tenant/module/role/
 * destination scoping — mirroring the domestic/international/both split
 * CashbackRule and HolidayPackage already use (booking_scope), plus a
 * supplier_code column so a rule can be narrowed to one specific
 * airline/operator/supplier rather than every supplier under a module.
 * Both are nullable-by-default-meaning-"applies to everything" in the same
 * spirit as origin/destination already work: a rule that sets neither is
 * unchanged from before this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('markup_rules', function (Blueprint $table) {
            $table->string('booking_scope', 20)->default('both')->after('applies_to_role');
            $table->string('supplier_code', 40)->nullable()->after('destination');
        });
    }

    public function down(): void
    {
        Schema::table('markup_rules', function (Blueprint $table) {
            $table->dropColumn(['booking_scope', 'supplier_code']);
        });
    }
};
