<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2's second slice: "a real refund can take days to actually land
 * (supplier confirms cancellation, then gateway processes it), the booking
 * shows a distinct in-process state rather than looking simply cancelled or
 * stuck" and "refund issuance, three ways: back through the original
 * payment gateway, a direct bank transfer, or credit to the customer's
 * wallet". RefundTransaction tracks one refund attempt end to end
 * (processing -> completed|failed); Booking::STATUS_REFUND_PROCESSING is
 * the new "in process" status BookingStatusService now requires between
 * "staff clicked Initiate refund" and "money has actually moved" — see
 * RefundService for the state machine this feeds.
 *
 * bookings.status: ENUM -> VARCHAR(20), same reasoning and same
 * sqlite/mysql branching as markup_rules.rule_type in
 * 2024_02_01_000015_extend_markup_rules_and_bookings.php — adding
 * refund_processing (and any future status) never again needs a column
 * migration; the allowed-value list is enforced by
 * BookingStatusService::ALLOWED_TRANSITIONS instead of the DB column.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            // Laravel 11 rebuilds the SQLite table for ->change(), keeping
            // the (tenant_id, status, created_at) index intact — a manual
            // drop/rename of `status` fails because that index references it.
            Schema::table('bookings', function (Blueprint $table) {
                $table->string('status', 20)->default('cart')->change();
            });
        } else {
            DB::statement("ALTER TABLE bookings MODIFY status VARCHAR(20) NOT NULL DEFAULT 'cart'");
        }

        Schema::create('refund_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();
            // Which policy/band this refund was quoted from — null when the
            // conservative platform default (no policy configured) applied.
            $table->foreignId('cancellation_policy_id')->nullable()->constrained('cancellation_policies')->nullOnDelete();
            $table->string('method', 20);
            $table->string('status', 20)->default('processing');
            $table->decimal('requested_amount', 12, 2);
            $table->string('currency', 3)->default('INR');
            // What to revert the booking to if this refund fails — ticketed
            // or cancelled, whichever it actually was before "Initiate
            // refund" was clicked.
            $table->string('previous_booking_status', 20);
            $table->string('gateway_reference', 191)->nullable();
            $table->string('bank_account_name', 191)->nullable();
            $table->string('bank_account_number', 40)->nullable();
            $table->string('bank_ifsc', 20)->nullable();
            $table->foreignId('wallet_ledger_entry_id')->nullable()->constrained('wallet_ledger_entries')->nullOnDelete();
            $table->foreignId('initiated_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('completed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->string('failure_reason', 500)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['booking_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refund_transactions');

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE bookings MODIFY status ENUM('cart','held','confirmed','ticketed','cancelled','failed','refunded') NOT NULL DEFAULT 'cart'");
        }
    }
};
