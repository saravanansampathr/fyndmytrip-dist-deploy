<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The backlog gap: "while international ticket booking travel date to min
 * 6 month need passport validity, not on the booking date, on the
 * travel/journey date" — booking_passengers had a passport_number but no
 * issue/expiry dates at all, so that rule had nothing to check against.
 * See FlightBookingController::hold()'s international-passport validation,
 * added alongside this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_passengers', function (Blueprint $table) {
            $table->date('passport_issue_date')->nullable()->after('passport_number');
            $table->date('passport_expiry_date')->nullable()->after('passport_issue_date');
            $table->string('passport_country', 2)->nullable()->after('passport_expiry_date');
        });
    }

    public function down(): void
    {
        Schema::table('booking_passengers', function (Blueprint $table) {
            $table->dropColumn(['passport_issue_date', 'passport_expiry_date', 'passport_country']);
        });
    }
};
