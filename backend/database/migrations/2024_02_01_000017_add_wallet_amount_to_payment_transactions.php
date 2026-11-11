<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Need option to adjust the wallet amount and pay balance through payment
 * gateway, or full pay through payment gateway by unchecking the wallet."
 * payment_transactions already had a `wallet_id` column (Database Design
 * Document §6.2 anticipated a wallet leg) but nothing recorded *how much*
 * of a split payment came from the wallet versus the gateway — `amount`
 * already means "what the gateway settles," so this adds the sibling
 * figure. Both legs are captured on the one transaction row rather than
 * two, so FlightBookingController::payGateway() can debit the wallet and
 * settle the gateway atomically in a single DB transaction — see its
 * docblock.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->decimal('wallet_amount', 12, 2)->default(0)->after('wallet_id');
        });
    }

    public function down(): void
    {
        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->dropColumn('wallet_amount');
        });
    }
};
