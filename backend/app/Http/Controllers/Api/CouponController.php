<?php

namespace App\Http\Controllers\Api;

use App\Domains\Coupons\Exceptions\InvalidCouponException;
use App\Domains\Coupons\Models\Coupon;
use App\Domains\Coupons\Services\CouponService;
use App\Domains\Tenancy\Services\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class CouponController extends Controller
{
    public function __construct(protected CouponService $couponService) {}

    /**
     * Public storefront's "Offers & promos" homepage section — real,
     * currently-redeemable coupons only (never fabricated placeholder
     * content), so a guest sees exactly what applying a code at checkout
     * would actually honour. No auth:sanctum (see routes/api.php), same
     * tenant-resolution pattern as the guest search/checkout endpoints.
     * Deliberately exposes only what's safe to advertise publicly — never
     * max_uses_total/max_uses_per_user (redemption counts aren't anyone
     * else's business) — and never the coupon actually being "activated"
     * or consumed; that still only happens via validateCoupon() below, at
     * checkout.
     */
    public function active(Request $request)
    {
        $tenant = $request->attributes->get('resolved_tenant') ?? app(TenantContext::class)->get();

        $coupons = Coupon::query()
            ->where('status', Coupon::STATUS_ACTIVE)
            ->where(fn ($q) => $q->whereNull('tenant_id')->orWhere('tenant_id', $tenant?->id))
            ->where('valid_from', '<=', now())
            ->where('valid_to', '>=', now())
            ->orderByDesc('value')
            ->limit(12)
            ->get();

        return response()->json([
            'data' => $coupons->map(fn (Coupon $c) => [
                'code' => $c->code,
                'discount_type' => $c->discount_type,
                'value' => $c->value,
                'min_booking_amount' => $c->min_booking_amount,
                'applicable_modules' => $c->applicable_modules,
                'valid_to' => $c->valid_to,
            ]),
        ]);
    }

    public function validateCoupon(Request $request)
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:30'],
            'module' => ['required', 'in:flight,hotel,bus,cab,holiday,insurance,visa,cruise'],
            'booking_amount' => ['required', 'numeric', 'gt:0'],
        ]);

        $user = $request->user();

        try {
            $coupon = $this->couponService->validate($data['code'], $user->tenant, $user, $data['module'], (string) $data['booking_amount']);
        } catch (InvalidCouponException $e) {
            return response()->json(['valid' => false, 'message' => $e->getMessage()], 422);
        }

        $discount = $this->couponService->calculateDiscount($coupon, (string) $data['booking_amount']);

        return response()->json([
            'valid' => true,
            'discount_amount' => $discount,
            'is_stackable_with_wallet' => $coupon->is_stackable_with_wallet,
        ]);
    }
}
