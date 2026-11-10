<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Same fix as 2024_02_01_000033 (module columns): wallet_ledger_entries.
 * reference_type was created as a hard-coded MySQL ENUM
 * (2024_01_01_000004), so adding WalletLedgerEntry::REF_OFFLINE_PAYMENT to
 * the PHP constant list does nothing to the live column — the first
 * OfflinePaymentService::approve() call would fail with "Data truncated
 * for column 'reference_type'". Widen to VARCHAR once, the same way, so no
 * future reference type ever needs a schema migration again.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            Schema::table('wallet_ledger_entries', function (Blueprint $table) {
                $table->string('reference_type_new', 20)->nullable();
            });
            DB::statement('UPDATE wallet_ledger_entries SET reference_type_new = reference_type');
            Schema::table('wallet_ledger_entries', function (Blueprint $table) {
                $table->dropColumn('reference_type');
            });
            Schema::table('wallet_ledger_entries', function (Blueprint $table) {
                $table->renameColumn('reference_type_new', 'reference_type');
            });
        } else {
            DB::statement('ALTER TABLE wallet_ledger_entries MODIFY reference_type VARCHAR(20) NOT NULL');
        }
    }

    public function down(): void
    {
        // No-op — see 2024_02_01_000033's identical rationale.
    }
};
