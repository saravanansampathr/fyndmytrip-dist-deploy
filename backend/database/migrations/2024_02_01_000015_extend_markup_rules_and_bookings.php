<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Feature request: "Where is the option to keep the markup for each Agents
 * and Whitelabel API, Either fixed per PNR or PAX or Destination or
 * percentage wise" — markup_rules already existed (tenant + module +
 * ancestor-ceiling walk in MarkupService) but only 'percentage' was
 * actually implemented and there was no CRM to manage rules at all. This
 * widens rule_type to the four real types the request asked for and adds
 * optional origin/destination columns for the "per destination" type
 * (querying two plain columns beats matching against JSON for every
 * pricing call). rule_type/applies_to_role move from ENUM to VARCHAR:
 * portable across the MySQL production DB and the SQLite test DB without
 * a doctrine/dbal dependency, with the allowed-value list enforced in
 * MarkupRuleController's validation instead.
 *
 * Also: bookings.channel snapshots how a booking entered the platform
 * (direct / agent / sub_agent / white_label / api_partner) so behaviour
 * and reporting don't have to join out to tenants.type after the fact —
 * see Booking::CHANNEL_* and where FlightBookingController::hold() /
 * HolidayBookingController::store() set it. bookings.booking_code is the
 * human-facing booking ID ("FMTFL00001234") the feature request asked
 * for, distinct from the internal booking_reference UUID and from the
 * supplier's own PNR (supplier_booking_ref).
 */
return new class extends Migration
{
    public function up(): void
    {
        // markup_rules.rule_type / applies_to_role: ENUM -> VARCHAR.
        // Changing an ENUM's allowed values with plain Schema::table()
        // isn't portable without doctrine/dbal, so recreate the columns
        // directly with raw SQL on MySQL; SQLite (tests) never enforced
        // the enum at the column level so a plain rename is enough there.
        if (DB::getDriverName() === 'sqlite') {
            Schema::table('markup_rules', function (Blueprint $table) {
                $table->string('rule_type_new', 30)->nullable();
                $table->string('applies_to_role_new', 20)->nullable();
            });
            DB::statement('UPDATE markup_rules SET rule_type_new = rule_type, applies_to_role_new = applies_to_role');
            Schema::table('markup_rules', function (Blueprint $table) {
                $table->dropColumn(['rule_type', 'applies_to_role']);
            });
            Schema::table('markup_rules', function (Blueprint $table) {
                $table->renameColumn('rule_type_new', 'rule_type');
                $table->renameColumn('applies_to_role_new', 'applies_to_role');
            });
        } else {
            DB::statement("ALTER TABLE markup_rules MODIFY rule_type VARCHAR(30) NOT NULL");
            DB::statement("ALTER TABLE markup_rules MODIFY applies_to_role VARCHAR(20) NOT NULL");
            // Old 'flat'/'slab' rows (never had a CRM to create them from,
            // but be defensive) become the closest new equivalent.
            DB::statement("UPDATE markup_rules SET rule_type = 'flat_per_pnr' WHERE rule_type = 'flat'");
            DB::statement("UPDATE markup_rules SET rule_type = 'percentage' WHERE rule_type = 'slab'");
        }

        Schema::table('markup_rules', function (Blueprint $table) {
            $table->string('name', 191)->nullable()->after('tenant_id');
            $table->string('origin', 10)->nullable()->after('config');
            $table->string('destination', 10)->nullable()->after('origin');
            $table->boolean('is_active')->default(true)->after('applies_to_role');
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->string('booking_code', 40)->nullable()->unique()->after('booking_reference');
            $table->string('channel', 20)->nullable()->after('agent_tenant_id');

            $table->index('channel');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropIndex(['channel']);
            $table->dropColumn(['booking_code', 'channel']);
        });

        Schema::table('markup_rules', function (Blueprint $table) {
            $table->dropColumn(['name', 'origin', 'destination', 'is_active']);
        });
    }
};
