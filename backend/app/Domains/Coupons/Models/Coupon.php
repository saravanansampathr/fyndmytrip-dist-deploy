<?php

namespace App\Domains\Coupons\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Database Design Document §7.1. tenant_id NULL = platform-wide coupon
 * usable across all tenants, so — like PaymentGatewayConfig — this model
 * does not use BelongsToTenant's automatic scope; CouponService queries it
 * explicitly for (tenant_id = X OR tenant_id IS NULL).
 */
class Coupon extends Model
{
    public const DISCOUNT_FLAT = 'flat';
    public const DISCOUNT_PERCENTAGE = 'percentage';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_PAUSED = 'paused';
    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'tenant_id',
        'code',
        'discount_type',
        'value',
        'min_booking_amount',
        'applicable_modules',
        'max_uses_total',
        'max_uses_per_user',
        'valid_from',
        'valid_to',
        'is_stackable_with_wallet',
        'status',
    ];

    protected $casts = [
        'value' => 'decimal:2',
        'min_booking_amount' => 'decimal:2',
        'applicable_modules' => 'array',
        'valid_from' => 'datetime',
        'valid_to' => 'datetime',
        'is_stackable_with_wallet' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $coupon) {
            if (empty($coupon->status)) {
                $coupon->status = self::STATUS_DRAFT;
            }

            if (empty($coupon->max_uses_per_user)) {
                $coupon->max_uses_per_user = 1;
            }

            if ($coupon->min_booking_amount === null) {
                $coupon->min_booking_amount = '0.00';
            }
        });
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(CouponRedemption::class);
    }

    public function isCurrentlyValid(): bool
    {
        return $this->status === self::STATUS_ACTIVE
            && now()->betweenIncluded($this->valid_from, $this->valid_to);
    }
}
