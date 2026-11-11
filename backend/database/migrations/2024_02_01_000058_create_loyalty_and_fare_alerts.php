<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Phase 3: loyalty points, referrals and fare (price-drop) alerts. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('referral_code', 16)->nullable()->unique();
            $table->unsignedBigInteger('referred_by_user_id')->nullable()->index();
        });
        Schema::create('loyalty_points', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('tenant_id');
            $table->integer('points'); // + earned, - used / reversed / expired
            $table->string('reason', 20); // earn, reverse, redeem, referral, expire, adjust
            $table->unsignedBigInteger('booking_id')->nullable()->index();
            $table->string('note', 191)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
        Schema::create('fare_alerts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('email', 191);
            $table->string('origin', 10);
            $table->string('destination', 10);
            $table->date('depart_date');
            $table->unsignedSmallInteger('adults')->default(1);
            $table->string('cabin_class', 20)->default('economy');
            $table->decimal('target_price', 12, 2)->nullable();
            $table->decimal('last_price', 12, 2)->nullable();
            $table->string('currency', 3)->default('INR');
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('notified_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('token', 40)->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fare_alerts');
        Schema::dropIfExists('loyalty_points');
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['referral_code']);
            $table->dropColumn(['referral_code', 'referred_by_user_id']);
        });
    }
};
