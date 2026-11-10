<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Database Design Document §6. payment_gateway_configs.tenant_id is
 * nullable by design (NULL = platform-wide default gateway).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_gateway_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->enum('gateway', ['razorpay', 'payu', 'cashfree', 'ccavenue', 'stripe']);
            $table->text('credentials_encrypted');
            $table->boolean('is_active')->default(true);
            $table->unsignedTinyInteger('priority')->default(1);
            $table->timestamps();
        });

        Schema::create('payment_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->nullable()->constrained('bookings')->nullOnDelete();
            $table->foreignId('wallet_id')->nullable()->constrained('wallets')->nullOnDelete();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->enum('gateway', ['razorpay', 'payu', 'cashfree', 'ccavenue', 'stripe']);
            $table->string('gateway_txn_id', 100)->nullable()->unique();
            $table->decimal('amount', 12, 2);
            $table->char('currency', 3)->default('INR');
            $table->enum('status', ['initiated', 'success', 'failed', 'refunded'])->default('initiated');
            $table->enum('method', ['card', 'upi', 'netbanking', 'wallet'])->nullable();
            $table->json('raw_gateway_response')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_transactions');
        Schema::dropIfExists('payment_gateway_configs');
    }
};
