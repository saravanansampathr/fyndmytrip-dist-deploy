<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Which payment gateway handles Credit card, Debit card, UPI, Netbanking,
 * Wallets, International cards" per the feature request — a second,
 * finer-grained routing layer on top of PaymentGatewayConfig (which only
 * says which gateway(s) a tenant has credentials for at all). A tenant may
 * have multiple active gateways configured and want, say, UPI routed to
 * Razorpay while international cards go to Stripe; this table is what
 * PaymentGatewayService::resolveGatewayForMethod() reads to decide.
 *
 * tenant_id NULL = platform-wide default route, same convention as
 * PaymentGatewayConfig/Coupon/CashbackRule. Only resolved once the
 * customer actually picks a payment method at the gateway step (see
 * FlightBookingController::payGateway()) — at confirm() time, before that
 * choice is made, payment_transactions.gateway/method are still NULL,
 * which is why those two columns are widened to nullable free-text here
 * rather than kept as the original fixed enums.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_method_routes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->enum('payment_method', [
                'credit_card', 'debit_card', 'upi', 'netbanking', 'wallet', 'international_card',
            ]);
            $table->enum('gateway', ['razorpay', 'payu', 'cashfree', 'ccavenue', 'stripe']);
            $table->boolean('is_active')->default(true);
            $table->unsignedTinyInteger('priority')->default(1);
            $table->timestamps();

            $table->index(['payment_method', 'is_active', 'priority']);
        });

        // MySQL-specific raw ALTERs (this codebase has no doctrine/dbal
        // dependency, so Blueprint::change() isn't available) — the
        // gateway/method for a payment_transactions row are only known
        // once the customer picks a method at the payment-gateway step,
        // so both must accept NULL between initiate() and settle().
        if (DB::getDriverName() === 'sqlite') {
            // SQLite (test suite) has no MODIFY — Laravel 11 rebuilds the
            // table natively for ->change(), so use that path there.
            Schema::table('payment_transactions', function (Blueprint $table) {
                $table->string('gateway', 30)->nullable()->change();
                $table->string('method', 30)->nullable()->change();
            });

            return;
        }

        DB::statement('ALTER TABLE payment_transactions MODIFY gateway VARCHAR(30) NULL');
        DB::statement('ALTER TABLE payment_transactions MODIFY method VARCHAR(30) NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_method_routes');

        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        DB::statement("ALTER TABLE payment_transactions MODIFY gateway ENUM('razorpay','payu','cashfree','ccavenue','stripe') NOT NULL");
        DB::statement("ALTER TABLE payment_transactions MODIFY method ENUM('card','upi','netbanking','wallet') NULL");
    }
};
