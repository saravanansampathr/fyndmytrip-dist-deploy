<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Computed once (RouteClassificationService) when a booking is held and
 * persisted here so confirm() never has to re-derive it — see
 * FlightBookingController::hold()/confirm(). Drives the payment-gateway
 * vs wallet branching: international bookings must never be paid by a
 * direct wallet debit (see PaymentGatewayService).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->boolean('is_international')->default(false)->after('module');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('is_international');
        });
    }
};
