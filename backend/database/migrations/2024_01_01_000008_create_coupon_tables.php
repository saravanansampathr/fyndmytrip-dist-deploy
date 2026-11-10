<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Database Design Document §7. coupons.tenant_id is nullable by design
 * (NULL = platform-wide coupon usable across all tenants).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('code', 30);
            $table->enum('discount_type', ['flat', 'percentage']);
            $table->decimal('value', 10, 2);
            $table->decimal('min_booking_amount', 12, 2)->default(0);
            $table->json('applicable_modules');
            $table->unsignedInteger('max_uses_total')->nullable();
            $table->unsignedSmallInteger('max_uses_per_user')->default(1);
            $table->dateTime('valid_from');
            $table->dateTime('valid_to');
            $table->boolean('is_stackable_with_wallet')->default(true);
            $table->enum('status', ['draft', 'active', 'paused', 'expired'])->default('draft');
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('coupon_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coupon_id')->constrained('coupons')->cascadeOnDelete();
            $table->foreignId('booking_id')->unique()->constrained('bookings')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->decimal('discount_applied', 12, 2);
            $table->timestamp('redeemed_at')->useCurrent();

            $table->index(['coupon_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupon_redemptions');
        Schema::dropIfExists('coupons');
    }
};
