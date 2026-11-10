<?php

namespace App\Domains\Coupons\Services;

use App\Domains\Booking\Models\Booking;
use App\Domains\Coupons\Exceptions\InvalidCouponException;
use App\Domains\Coupons\Models\Coupon;
use App\Domains\Coupons\Models\CouponRedemption;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

/**
 * Rule engine per Master Brief §7 / Database Design Document §7: fixed or
 * percentage discounts, min-booking value, module scoping, usage caps,
 * expiry, tenant-specific vs platform-wide coupons.
 */
class CouponService
{
    /**
     * @throws InvalidCouponException
     */
    public function validate(string $code, Tenant $tenant, User $user, string $module, string $bookingAmount): Coupon
    {
        $coupon = Coupon::query()
            ->where('code', $code)
            ->where(fn ($q) => $q->where('tenant_id', $tenant->id)->orWhereNull('tenant_id'))
            ->first();

        if (! $coupon) {
            throw new InvalidCouponException("Coupon '{$code}' does not exist for this tenant.");
        }

        if (! $coupon->isCurrentlyValid()) {
            throw new InvalidCouponException("Coupon '{$code}' is not currently active.");
        }

        if (! in_array($module, $coupon->applicable_modules ?? [], true)) {
            throw new InvalidCouponException("Coupon '{$code}' does not apply to the '{$module}' module.");
        }

        if (BigDecimal::of($bookingAmount)->isLessThan(BigDecimal::of((string) $coupon->min_booking_amount))) {
            throw new InvalidCouponException("Coupon '{$code}' requires a minimum booking amount of {$coupon->min_booking_amount}.");
        }

        if ($coupon->max_uses_total !== null && $coupon->redemptions()->count() >= $coupon->max_uses_total) {
            throw new InvalidCouponException("Coupon '{$code}' has reached its total usage limit.");
        }

        $userRedemptions = $coupon->redemptions()->where('user_id', $user->id)->count();

        if ($userRedemptions >= $coupon->max_uses_per_user) {
            throw new InvalidCouponException("You have already used coupon '{$code}' the maximum number of times allowed.");
        }

        return $coupon;
    }

    public function calculateDiscount(Coupon $coupon, string $bookingAmount): string
    {
        $amount = BigDecimal::of($bookingAmount);

        $discount = $coupon->discount_type === Coupon::DISCOUNT_PERCENTAGE
            ? $amount->multipliedBy((string) $coupon->value)->dividedBy(100, 2, RoundingMode::HALF_UP)
            : BigDecimal::of((string) $coupon->value);

        // A coupon can never discount more than the booking amount itself.
        if ($discount->isGreaterThan($amount)) {
            $discount = $amount;
        }

        return (string) $discount->toScale(2, RoundingMode::HALF_UP);
    }

    /**
     * @throws InvalidCouponException
     */
    public function redeem(string $code, Tenant $tenant, User $user, Booking $booking): CouponRedemption
    {
        return DB::transaction(function () use ($code, $tenant, $user, $booking) {
            $coupon = $this->validate($code, $tenant, $user, $booking->module, (string) $booking->gross_amount);
            $discount = $this->calculateDiscount($coupon, (string) $booking->gross_amount);

            return CouponRedemption::query()->create([
                'coupon_id' => $coupon->id,
                'booking_id' => $booking->id,
                'user_id' => $user->id,
                'discount_applied' => $discount,
                'redeemed_at' => now(),
            ]);
        });
    }

    /**
     * Re-validates the coupon is still usable (limits/expiry haven't
     * changed since the customer applied it at review time) and redeems
     * it for exactly the discount amount already shown to the customer —
     * used at final payment, where the booking's payable amount includes
     * seats/ancillaries added after the coupon was first applied, so we
     * deliberately do not recompute the discount off a different base.
     *
     * @throws InvalidCouponException
     */
    public function redeemWithAmount(string $code, Tenant $tenant, User $user, Booking $booking, string $discountAmount): CouponRedemption
    {
        return DB::transaction(function () use ($code, $tenant, $user, $booking, $discountAmount) {
            $coupon = $this->validate($code, $tenant, $user, $booking->module, (string) $booking->gross_amount);

            return CouponRedemption::query()->create([
                'coupon_id' => $coupon->id,
                'booking_id' => $booking->id,
                'user_id' => $user->id,
                'discount_applied' => $discountAmount,
                'redeemed_at' => now(),
            ]);
        });
    }
}
