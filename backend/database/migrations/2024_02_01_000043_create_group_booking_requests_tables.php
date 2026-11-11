<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "In flight bookings need group Bookings ... These group bookings only
 * shown to b2b not to b2c users" — the staff-quoted half of the two agreed
 * flows (the other, instant bulk booking, needed no new tables — see
 * FlightBookingController::MAX_PASSENGERS_B2B_BULK). Modelled on the real
 * group-desk workflow every major OTA/GDS uses once a request exceeds the
 * standard 9-passenger single-PNR limit: submit route/dates/pax -> staff
 * negotiates and quotes a fare with a deposit and a fare-hold expiry ->
 * requester pays the deposit to lock it in -> requester submits the final
 * name list by a deadline -> staff finalizes (collecting any balance due)
 * once the airline issues real tickets. See GroupBookingRequest's own
 * docblock for the full state machine and GroupBookingService for the
 * money-moving transitions (deposit/balance both go through WalletService,
 * exactly like every other payment in this app, never a bespoke ledger).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_booking_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by_user_id')->constrained('users')->cascadeOnDelete();

            $table->string('trip_type', 20)->default('round_trip'); // one_way | round_trip
            $table->string('origin', 3);
            $table->string('destination', 3);
            $table->date('depart_date');
            $table->date('return_date')->nullable();
            $table->unsignedInteger('passenger_count');
            $table->string('cabin_class', 20)->default('economy');
            $table->boolean('flexible_dates')->default(false);

            $table->string('contact_name', 150);
            $table->string('contact_email', 191);
            $table->string('contact_phone', 30);
            $table->text('remarks')->nullable();

            // pending_quote -> quoted -> deposit_paid -> name_list_submitted
            // -> finalized, with rejected/cancelled/expired side exits — see
            // GroupBookingRequest::STATUSES and its docblock for the full
            // diagram. VARCHAR from the start (not ENUM) — see the
            // 2024_02_01_000033 migration's docblock for why an ENUM column
            // is the wrong choice for anything that will grow more values.
            $table->string('status', 24)->default('pending_quote');

            $table->decimal('quoted_fare_per_pax', 12, 2)->nullable();
            $table->decimal('quoted_total_fare', 12, 2)->nullable();
            $table->decimal('deposit_amount', 12, 2)->nullable();
            $table->text('quote_remarks')->nullable();
            $table->foreignId('quoted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('quoted_at')->nullable();
            $table->date('fare_hold_expires_at')->nullable();
            $table->date('name_list_deadline')->nullable();

            $table->timestamp('deposit_paid_at')->nullable();
            $table->foreignId('deposit_wallet_ledger_entry_id')->nullable()
                ->constrained('wallet_ledger_entries')->nullOnDelete();

            $table->foreignId('finalized_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('finalized_at')->nullable();
            $table->string('final_booking_reference', 60)->nullable();
            $table->foreignId('balance_wallet_ledger_entry_id')->nullable()
                ->constrained('wallet_ledger_entries')->nullOnDelete();

            $table->text('rejection_reason')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });

        Schema::create('group_booking_passengers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_booking_request_id')->constrained('group_booking_requests')->cascadeOnDelete();

            $table->string('title', 10)->nullable();
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->string('passenger_type', 10)->default('adult'); // adult | child | infant
            $table->date('dob')->nullable();
            $table->string('gender', 15)->nullable();
            $table->string('passport_number', 60)->nullable();
            $table->date('passport_expiry_date')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_booking_passengers');
        Schema::dropIfExists('group_booking_requests');
    }
};
