<?php

namespace Database\Seeders;

use App\Domains\Coupons\Models\Coupon;
use Illuminate\Database\Seeder;

/**
 * A couple of platform-wide (tenant_id NULL) demo coupons so the "apply a
 * coupon at checkout" step in the flight booking flow has something real
 * to try — see CouponService and FlightBookingController::applyCoupon().
 */
class CouponSeeder extends Seeder
{
    public function run(): void
    {
        Coupon::query()->firstOrCreate(
            ['tenant_id' => null, 'code' => 'FLYFIRST10'],
            [
                'discount_type' => Coupon::DISCOUNT_PERCENTAGE,
                'value' => '10.00',
                'min_booking_amount' => '1000.00',
                'applicable_modules' => ['flight'],
                'max_uses_total' => null,
                'max_uses_per_user' => 3,
                'valid_from' => now()->subYear(),
                'valid_to' => now()->addYear(),
                'is_stackable_with_wallet' => true,
                'status' => Coupon::STATUS_ACTIVE,
            ]
        );

        Coupon::query()->firstOrCreate(
            ['tenant_id' => null, 'code' => 'SAVE500'],
            [
                'discount_type' => Coupon::DISCOUNT_FLAT,
                'value' => '500.00',
                'min_booking_amount' => '3000.00',
                'applicable_modules' => ['flight'],
                'max_uses_total' => null,
                'max_uses_per_user' => 3,
                'valid_from' => now()->subYear(),
                'valid_to' => now()->addYear(),
                'is_stackable_with_wallet' => true,
                'status' => Coupon::STATUS_ACTIVE,
            ]
        );

        $this->command?->info('Seeded demo coupons: FLYFIRST10 (10% off, min ₹1,000), SAVE500 (₹500 off, min ₹3,000).');
    }
}
