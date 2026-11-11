<?php

namespace App\Domains\Payments\Models;

use App\Domains\Booking\Models\Booking;
use App\Domains\Tenancy\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Wallet\Models\Wallet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Database Design Document §6.2.
 */
class PaymentTransaction extends Model
{
    use BelongsToTenant;

    public const STATUS_INITIATED = 'initiated';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';
    public const STATUS_REFUNDED = 'refunded';

    protected $fillable = [
        'booking_id',
        'wallet_id',
        'wallet_amount',
        'tenant_id',
        'gateway',
        'gateway_txn_id',
        'amount',
        'currency',
        'charge_currency',
        'charge_amount',
        'fx_rate',
        'status',
        'method',
        'raw_gateway_response',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'wallet_amount' => 'decimal:2',
        'raw_gateway_response' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $txn) {
            if (empty($txn->status)) {
                $txn->status = self::STATUS_INITIATED;
            }

            if (empty($txn->currency)) {
                $txn->currency = 'INR';
            }
        });
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
