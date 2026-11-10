<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Offline payment capture: a partner/agent/sub-agent/API-partner/white-
 * label user who has transferred funds outside the platform (NEFT/RTGS/
 * IMPS/UPI/cash deposit/netbanking/other) submits the transaction details
 * here; it sits 'pending' until staff verify and approve (which credits
 * their wallet, same REF_* pattern as every other WalletService::credit()
 * call site) or reject it (with a reason, no money movement). See
 * OfflinePaymentService and OfflinePaymentController.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offline_payment_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('wallet_id')->constrained('wallets')->cascadeOnDelete();
            $table->foreignId('requested_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('payment_mode', 20);
            $table->decimal('amount', 12, 2);
            $table->date('transaction_date');
            $table->string('transaction_ref_number', 100);
            $table->text('remarks')->nullable();
            $table->string('status', 10)->default('pending');
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('rejection_reason', 255)->nullable();
            $table->foreignId('wallet_ledger_entry_id')->nullable()->constrained('wallet_ledger_entries')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'status', 'created_at']);
            $table->index(['requested_by_user_id', 'created_at']);
        });

        Schema::create('offline_payment_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('offline_payment_request_id')->constrained('offline_payment_requests')->cascadeOnDelete();
            $table->string('disk_path', 255);
            $table->string('original_filename', 255);
            $table->string('mime_type', 100);
            $table->unsignedInteger('size_bytes');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offline_payment_attachments');
        Schema::dropIfExists('offline_payment_requests');
    }
};
