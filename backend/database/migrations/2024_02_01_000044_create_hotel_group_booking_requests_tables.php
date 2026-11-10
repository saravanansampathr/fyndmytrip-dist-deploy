<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Hotel counterpart to 2024_02_01_000043's Flight Group Bookings — same
 * "instant bulk booking below the threshold, staff-quoted group desk above
 * it" split (see HotelBookingController::MAX_ROOMS_B2B_BULK), but grounded
 * in the hotel industry's own real convention rather than a copy of the
 * flight one: a block of 10 or more rooms is universally treated as a
 * "group booking" by hotels, handled by a dedicated group-sales desk with
 * negotiated rates rather than the public booking engine — see
 * HotelGroupBookingRequest::MINIMUM_ROOMS_COUNT.
 *
 * The state machine and money-movement shape are identical to
 * GroupBookingRequest/GroupBookingService (pending_quote -> quoted ->
 * deposit_paid -> rooming_list_submitted -> finalized, deposit/balance both
 * through WalletService) — only the domain fields differ: city/dates/rooms
 * instead of origin/destination/passengers, and a "rooming list" (the real
 * hotel-industry term for the guest-per-room name list a group organizer
 * submits) instead of a passenger name list.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Idempotent against a prior partial run of THIS migration: MySQL
        // DDL auto-commits per statement, so the first attempt's CREATE
        // TABLE for hotel_group_booking_requests committed before an
        // identifier-too-long error on one of its foreign keys aborted the
        // migration — leaving the table present but the migration
        // unrecorded, so a retry would otherwise fail with "table already
        // exists". Safe to run on a clean environment too, where neither
        // table exists yet.
        Schema::dropIfExists('hotel_group_booking_guests');
        Schema::dropIfExists('hotel_group_booking_requests');

        Schema::create('hotel_group_booking_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by_user_id')->constrained('users')->cascadeOnDelete();

            $table->string('city', 100);
            $table->date('check_in_date');
            $table->date('check_out_date');
            $table->unsignedInteger('rooms_requested');
            $table->unsignedInteger('guest_count');
            $table->string('room_type_preference', 100)->nullable();
            $table->string('meal_plan_preference', 50)->nullable();

            $table->string('contact_name', 150);
            $table->string('contact_email', 191);
            $table->string('contact_phone', 30);
            $table->text('remarks')->nullable();

            // pending_quote -> quoted -> deposit_paid -> rooming_list_submitted
            // -> finalized, with rejected/cancelled/expired side exits — same
            // diagram as GroupBookingRequest, see
            // HotelGroupBookingRequest::STATUSES. VARCHAR from the start, per
            // this app's established "never ENUM a status column" convention
            // (2024_02_01_000033's docblock) — sized 30 (rather than
            // group_booking_requests' 24) to comfortably fit
            // 'rooming_list_submitted' (23 chars).
            $table->string('status', 30)->default('pending_quote');

            $table->decimal('quoted_rate_per_room', 12, 2)->nullable();
            $table->decimal('quoted_total_fare', 12, 2)->nullable();
            $table->decimal('deposit_amount', 12, 2)->nullable();
            $table->text('quote_remarks')->nullable();
            $table->foreignId('quoted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('quoted_at')->nullable();
            $table->date('rate_hold_expires_at')->nullable();
            $table->date('rooming_list_deadline')->nullable();

            $table->timestamp('deposit_paid_at')->nullable();
            // Explicit short constraint names below: the auto-generated
            // "hotel_group_booking_requests_..._foreign" name for these two
            // columns exceeds MySQL's 64-character identifier limit (long
            // table name + long column name) — this table's the first one
            // in this app to hit that ceiling, since every prior
            // wallet_ledger_entries FK (e.g. group_booking_requests') sat on
            // a shorter table name.
            $table->foreignId('deposit_wallet_ledger_entry_id')->nullable()
                ->constrained('wallet_ledger_entries', 'id', 'hgbr_deposit_wallet_entry_fk')->nullOnDelete();

            $table->foreignId('finalized_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('finalized_at')->nullable();
            $table->string('final_booking_reference', 60)->nullable();
            $table->foreignId('balance_wallet_ledger_entry_id')->nullable()
                ->constrained('wallet_ledger_entries', 'id', 'hgbr_balance_wallet_entry_fk')->nullOnDelete();

            $table->text('rejection_reason')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });

        Schema::create('hotel_group_booking_guests', function (Blueprint $table) {
            $table->id();
            // Same identifier-length fix as above.
            $table->foreignId('hotel_group_booking_request_id')
                ->constrained('hotel_group_booking_requests', 'id', 'hgb_guests_request_fk')->cascadeOnDelete();

            // Which room in the block this guest is assigned to — a real
            // rooming-list field (hotels allocate named guests to specific
            // rooms ahead of a group's arrival), nullable since the
            // organizer may submit names before room allocation is settled.
            $table->unsignedInteger('room_number')->nullable();

            $table->string('title', 10)->nullable();
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->string('guest_type', 10)->default('adult'); // adult | child
            $table->date('dob')->nullable();
            $table->string('gender', 15)->nullable();
            // Domestic-only convention already established for hotels
            // (HotelBookingController's docblock — no country field on
            // inventory yet), so guests carry a govt ID proof at check-in
            // rather than a passport: Aadhaar/Driving License/Voter ID/
            // Passport, whichever the organizer records.
            $table->string('id_proof_type', 30)->nullable();
            $table->string('id_proof_number', 60)->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hotel_group_booking_guests');
        Schema::dropIfExists('hotel_group_booking_requests');
    }
};
