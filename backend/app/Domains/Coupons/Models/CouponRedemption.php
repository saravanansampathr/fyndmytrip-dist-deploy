<?php

namespace App\Domains\Coupons\Models;

use App\Domains\Booking\Models\Booking;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CouponRedemption extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'coupon_id',
        'booking_id',
        'user_id',
        'discount_applied',
    ];

    public $timestamps = false;

    protected $casts = [
        'discount_applied' => 'decimal:2',
        'redeemed_at' => 'datetime',
    ];

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
