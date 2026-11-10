<?php

namespace App\Domains\Booking\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One refund attempt on a booking, start to finish — "Refund issuance,
 * three ways: back through the original payment gateway, a direct bank
 * transfer, or credit to the customer's wallet... staff can initiate any
 * of these on a customer's behalf". A wallet refund is instant (credited
 * the moment it's initiated, since there's nothing external to wait on) so
 * it's created and completed in the same call; a gateway or bank_transfer
 * refund is created PROCESSING and stays that way — reflected on the
 * booking itself as STATUS_REFUND_PROCESSING — until staff confirm the
 * money actually landed (complete()) or that it didn't (fail(), which
 * reverts the booking to previous_booking_status). See
 * RefundService for the actual state machine.
 */
class RefundTransaction extends Model
{
    public const METHOD_GATEWAY = 'gateway';

    public const METHOD_BANK_TRANSFER = 'bank_transfer';

    public const METHOD_WALLET = 'wallet';

    public const METHODS = [self::METHOD_GATEWAY, self::METHOD_BANK_TRANSFER, self::METHOD_WALLET];

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'booking_id',
        'cancellation_policy_id',
        'method',
        'status',
        'requested_amount',
        'currency',
        'previous_booking_status',
        'gateway_reference',
        'bank_account_name',
        'bank_account_number',
        'bank_ifsc',
        'wallet_ledger_entry_id',
        'initiated_by_user_id',
        'completed_by_user_id',
        'notes',
        'failure_reason',
        'completed_at',
    ];

    protected $casts = [
        'requested_amount' => 'decimal:2',
        'completed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $refund) {
            if (empty($refund->status)) {
                $refund->status = self::STATUS_PROCESSING;
            }

            if (empty($refund->currency)) {
                $refund->currency = 'INR';
            }
        });
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function cancellationPolicy(): BelongsTo
    {
        return $this->belongsTo(CancellationPolicy::class);
    }

    public function initiatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by_user_id');
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by_user_id');
    }
}
