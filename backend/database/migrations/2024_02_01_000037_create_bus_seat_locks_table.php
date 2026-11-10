<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reserves one seat (supplier_code + external_id + travel_date +
 * seat_number) for one booking passenger, system-wide, so two different
 * bookings can never be assigned the same physical bus seat on the same
 * trip/date — the bus analog of flight_seat_locks (see
 * FlightSeatLock/FlightSeatMapService). `gender` is stored directly on the
 * lock row (rather than requiring a join back to booking_passengers on
 * every seat-map render) so BusSeatMapService can apply the
 * female-adjacent-seat rule — once a seat is taken by a female passenger,
 * its still-empty adjacent seat becomes reservable by another female
 * passenger only — with a single query per render.
 *
 * The unique index is given an explicit short name rather than Laravel's
 * auto-generated one: `bus_seat_locks_supplier_code_external_id_travel_
 * date_seat_number_unique` is 71 characters, over MySQL's 64-character
 * identifier limit (an already-established lesson from this engagement's
 * composite indexes elsewhere).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bus_seat_locks', function (Blueprint $table) {
            $table->id();
            $table->string('supplier_code', 50);
            $table->string('external_id', 100);
            $table->date('travel_date');
            $table->string('seat_number', 10);
            $table->enum('gender', ['male', 'female', 'other', 'unspecified'])->default('unspecified');
            $table->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();
            $table->foreignId('booking_passenger_id')->nullable()->constrained('booking_passengers')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['supplier_code', 'external_id', 'travel_date', 'seat_number'], 'bus_seat_locks_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bus_seat_locks');
    }
};
