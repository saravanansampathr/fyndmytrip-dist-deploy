<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_transactions', function (Blueprint $table) {
            // What the gateway actually charged the customer, when they paid
            // in their own currency rather than INR.
            $table->string('charge_currency', 3)->nullable()->after('currency');
            $table->decimal('charge_amount', 14, 3)->nullable()->after('charge_currency');
            $table->decimal('fx_rate', 18, 8)->nullable()->after('charge_amount');
        });
    }

    public function down(): void
    {
        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->dropColumn(['charge_currency', 'charge_amount', 'fx_rate']);
        });
    }
};
