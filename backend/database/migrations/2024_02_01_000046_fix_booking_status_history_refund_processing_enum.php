<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A real, pre-existing bug this migration fixes: when
 * 2024_02_01_000025_add_refund_processing_status_and_refund_transactions
 * added Booking::STATUS_REFUND_PROCESSING and widened bookings.status from
 * ENUM to VARCHAR(20) to hold it, it never touched the ENUM on
 * booking_status_history.from_status/to_status — which still only lists
 * cart/held/confirmed/ticketed/cancelled/failed/refunded. Every real
 * transition into OR out of refund_processing (RefundService's entire job)
 * has been trying to insert a value the ENUM doesn't contain ever since —
 * depending on the server's sql_mode, that either throws and makes
 * refunds impossible to initiate or complete at all, or MySQL silently
 * coerces it to an empty string and corrupts the history row. Discovered
 * while building demo:seed-bookings, which exercises every transition
 * BookingStatusService allows, including this one.
 *
 * Same fix, same reasoning as that earlier migration: ENUM -> VARCHAR(20),
 * so the next new status never needs a schema change again — the allowed-
 * value list already lives in BookingStatusService::ALLOWED_TRANSITIONS,
 * not the column definition.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            // SQLite has no real ENUM (Laravel renders it as a CHECK
            // constraint) and this project's sqlite path elsewhere
            // (2024_02_01_000025) only ever needed to touch bookings.status,
            // not this table — nothing to migrate here for that driver.
            return;
        }

        DB::statement("ALTER TABLE booking_status_history MODIFY from_status VARCHAR(20) NULL");
        DB::statement("ALTER TABLE booking_status_history MODIFY to_status VARCHAR(20) NOT NULL");
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        DB::statement("ALTER TABLE booking_status_history MODIFY from_status ENUM('cart','held','confirmed','ticketed','cancelled','failed','refunded') NULL");
        DB::statement("ALTER TABLE booking_status_history MODIFY to_status ENUM('cart','held','confirmed','ticketed','cancelled','failed','refunded') NOT NULL");
    }
};
