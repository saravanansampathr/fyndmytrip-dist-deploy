<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1 of the booking-ops roadmap ("Booking Ops Foundation") — a general
 * audit trail alongside the existing booking_status_history table.
 * booking_status_history already covers status transitions specifically
 * (see BookingStatusService); this table is for everything else that
 * happens to a booking and isn't a status change — payment events,
 * supplier responses/errors, and manual notes staff leave while
 * investigating something — so a booking's full timeline can be read by
 * merging the two, ordered by created_at, without duplicating the status
 * rows into a second table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();
            $table->string('event_type', 40);
            $table->text('message');
            $table->json('meta')->nullable();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['booking_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_events');
    }
};
