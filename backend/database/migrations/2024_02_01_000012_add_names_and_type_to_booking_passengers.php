<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The flight passenger-details step (FlightBookingController::hold) used to
 * take one free-text 'name' field per passenger; real OTA booking forms
 * (Tripodeal/Akbar/MakeMyTrip/EaseMyTrip) all split this into first/last
 * name, and separately tag each passenger adult/child/infant so fares,
 * seat eligibility ("infants don't get a seat"), and the traveller-count
 * summary on the search form all agree with what was actually booked.
 *
 * 'name' is kept (still required, not dropped) rather than replaced — it's
 * the column every other module (hotel/bus/cab/...) sharing this same
 * generic booking_passengers table already reads/writes, and
 * BookingResource exposes it as-is. FlightBookingController now derives it
 * from first_name+last_name on write (no ->change(), so no doctrine/dbal
 * dependency), so existing readers keep working unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_passengers', function (Blueprint $table) {
            $table->string('first_name', 100)->nullable()->after('name');
            $table->string('last_name', 100)->nullable()->after('first_name');
            $table->enum('passenger_type', ['adult', 'child', 'infant'])->default('adult')->after('last_name');
        });
    }

    public function down(): void
    {
        Schema::table('booking_passengers', function (Blueprint $table) {
            $table->dropColumn(['first_name', 'last_name', 'passenger_type']);
        });
    }
};
