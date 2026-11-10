<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cashback rule engine, deliberately mirroring the coupon_tables migration
 * (see create_coupon_tables) so the two rule engines stay easy to reason
 * about side by side: tenant_id NULL = platform-wide rule, flat-or-
 * percentage value, module + validity-window scoping. CashbackService
 * additionally matches on airline, route country pair, domestic/
 * international scope, and trip duration — the "airline or date or
 * location or duration or product" configurability requested for the
 * international-booking payment-gateway feature.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cashback_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 191);

            // Product: null/empty applicable_modules = applies to every module.
            $table->json('applicable_modules')->nullable();

            // Location: any of these left null matches anything.
            $table->string('airline_code', 100)->nullable();
            $table->char('origin_country', 2)->nullable();
            $table->char('destination_country', 2)->nullable();
            $table->enum('booking_scope', ['domestic', 'international', 'both'])->default('both');

            // Date: the booking's travel_date_start must fall in this window
            // when set; either side null = open-ended.
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();

            // Duration: only enforced for bookings that have a return date
            // (travel_date_end); one-way bookings always pass this check.
            $table->unsignedSmallInteger('min_duration_days')->nullable();
            $table->unsignedSmallInteger('max_duration_days')->nullable();

            $table->enum('calc_type', ['flat', 'percentage']);
            $table->decimal('value', 12, 2);
            $table->decimal('max_cashback_amount', 12, 2)->nullable();

            $table->unsignedTinyInteger('priority')->default(1);
            $table->enum('status', ['draft', 'active', 'paused', 'expired'])->default('draft');

            $table->timestamps();

            $table->index(['status', 'priority']);
        });

        Schema::create('cashback_credits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();
            $table->foreignId('cashback_rule_id')->nullable()->constrained('cashback_rules')->nullOnDelete();
            $table->foreignId('wallet_ledger_entry_id')->nullable()->constrained('wallet_ledger_entries')->nullOnDelete();
            $table->decimal('amount', 12, 2);
            $table->timestamp('created_at')->useCurrent();

            // One cashback credit per booking — also the idempotency guard
            // CashbackService relies on so a booking is never double-paid.
            $table->unique('booking_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cashback_credits');
        Schema::dropIfExists('cashback_rules');
    }
};
