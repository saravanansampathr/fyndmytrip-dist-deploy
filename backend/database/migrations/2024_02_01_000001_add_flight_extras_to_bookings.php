<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the pieces needed for a full flight booking flow on top of the
 * generic bookings schema: contact details, ancillary/insurance totals,
 * and a pending (not-yet-redeemed) coupon so the discount can be shown
 * to the user before payment is captured at confirm() time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->string('contact_email', 191)->nullable()->after('passenger_count');
            $table->string('contact_phone', 30)->nullable()->after('contact_email');
            $table->decimal('extras_amount', 12, 2)->default(0)->after('markup_amount');
            $table->string('coupon_code', 30)->nullable()->after('commission_amount');
            $table->decimal('discount_amount', 12, 2)->default(0)->after('coupon_code');
            $table->decimal('total_amount', 12, 2)->default(0)->after('discount_amount');
        });

        Schema::table('booking_passengers', function (Blueprint $table) {
            $table->string('title', 10)->nullable()->after('name');
            $table->string('seat_number', 5)->nullable()->after('document_number');
        });

        Schema::create('booking_ancillaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();
            $table->foreignId('booking_passenger_id')->nullable()->constrained('booking_passengers')->cascadeOnDelete();
            $table->enum('category', ['meal', 'baggage', 'insurance', 'other']);
            $table->string('code', 40);
            $table->string('label', 150);
            $table->decimal('amount', 10, 2)->default(0);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['booking_id', 'category']);
        });

        Schema::create('flight_seat_locks', function (Blueprint $table) {
            $table->id();
            $table->string('flight_number', 20);
            $table->date('travel_date');
            $table->string('seat_number', 5);
            $table->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();
            $table->foreignId('booking_passenger_id')->constrained('booking_passengers')->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['flight_number', 'travel_date', 'seat_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flight_seat_locks');
        Schema::dropIfExists('booking_ancillaries');

        Schema::table('booking_passengers', function (Blueprint $table) {
            $table->dropColumn(['title', 'seat_number']);
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn([
                'contact_email', 'contact_phone', 'extras_amount',
                'coupon_code', 'discount_amount', 'total_amount',
            ]);
        });
    }
};
