<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2's last bullet: "Reschedule / date change / reissue as
 * first-class actions, not just cancel: same policy-driven fee
 * calculation, applied when changing dates or reissuing a ticket." One row
 * per amendment (a date change or a reissued ticket/PNR), recording what
 * changed and what fee applied — see BookingAmendment/BookingAmendmentService.
 *
 * The fee reuses CancellationPolicyService::quote()'s existing band
 * calculation rather than a second policy engine: the same "how much would
 * be forfeited cancelling right now" number is what's charged for changing
 * the booking instead of cancelling it — same bands, same specificity
 * matching, one calculation. Collection is deliberately just wallet-debit
 * or staff-waived for this first cut, not the full three-way gateway/bank/
 * wallet split refunds get — a change fee is usually small and immediate,
 * and adding gateway/bank collection here can follow later without
 * changing this table's shape.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_amendments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();
            $table->foreignId('cancellation_policy_id')->nullable()->constrained('cancellation_policies')->nullOnDelete();
            $table->string('type', 20); // reschedule | reissue
            $table->date('previous_travel_date_start')->nullable();
            $table->date('previous_travel_date_end')->nullable();
            $table->date('new_travel_date_start')->nullable();
            $table->date('new_travel_date_end')->nullable();
            $table->string('previous_supplier_booking_ref', 100)->nullable();
            $table->string('new_supplier_booking_ref', 100)->nullable();
            $table->json('previous_ticket_numbers')->nullable();
            $table->json('new_ticket_numbers')->nullable();
            $table->decimal('fee_amount', 12, 2)->default(0);
            $table->string('currency', 3)->default('INR');
            $table->string('fee_collection', 20); // wallet | waived
            $table->foreignId('wallet_ledger_entry_id')->nullable()->constrained('wallet_ledger_entries')->nullOnDelete();
            $table->foreignId('initiated_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['booking_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_amendments');
    }
};
