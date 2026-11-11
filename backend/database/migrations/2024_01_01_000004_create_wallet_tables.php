<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Database Design Document §5. wallet_ledger_entries is append-only — see
 * database/README.md in this directory for the production DB-grant note
 * (REVOKE UPDATE, DELETE on this table from the application DB user).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->enum('owner_type', ['tenant', 'user']);
            $table->unsignedBigInteger('owner_id');
            $table->decimal('balance', 12, 2)->default(0);
            $table->char('currency', 3)->default('INR');
            $table->enum('status', ['active', 'frozen', 'closed'])->default('active');
            $table->timestamps();

            $table->unique(['tenant_id', 'owner_type', 'owner_id']);
        });

        Schema::create('wallet_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_id')->constrained('wallets')->cascadeOnDelete();
            $table->enum('entry_type', ['credit', 'debit']);
            $table->decimal('amount', 12, 2);
            $table->decimal('running_balance', 12, 2);
            $table->enum('reference_type', ['topup', 'booking_payment', 'refund', 'cashback', 'commission_payout', 'adjustment']);
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('remarks')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['wallet_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_ledger_entries');
        Schema::dropIfExists('wallets');
    }
};
