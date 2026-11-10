<?php

namespace App\Domains\Booking\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One reschedule (date change) or reissue (new PNR/ticket number from the
 * supplier) on a TICKETED booking — Phase 2's "Reschedule / date change /
 * reissue as first-class actions, not just cancel: same policy-driven fee
 * calculation, applied when changing dates or reissuing a ticket." See
 * BookingAmendmentService for how the fee is computed and collected.
 */
class BookingAmendment extends Model
{
    public const TYPE_RESCHEDULE = 'reschedule';

    public const TYPE_REISSUE = 'reissue';

    public const TYPES = [self::TYPE_RESCHEDULE, self::TYPE_REISSUE];

    public const FEE_WALLET = 'wallet';

    public const FEE_WAIVED = 'waived';

    public const FEE_COLLECTIONS = [self::FEE_WALLET, self::FEE_WAIVED];

    protected $fillable = [
        'booking_id',
        'cancellation_policy_id',
        'type',
        'previous_travel_date_start',
        'previous_travel_date_end',
        'new_travel_date_start',
        'new_travel_date_end',
        'previous_supplier_booking_ref',
        'new_supplier_booking_ref',
        'previous_ticket_numbers',
        'new_ticket_numbers',
        'fee_amount',
        'currency',
        'fee_collection',
        'wallet_ledger_entry_id',
        'initiated_by_user_id',
        'notes',
    ];

    protected $casts = [
        'previous_travel_date_start' => 'date',
        'previous_travel_date_end' => 'date',
        'new_travel_date_start' => 'date',
        'new_travel_date_end' => 'date',
        'previous_ticket_numbers' => 'array',
        'new_ticket_numbers' => 'array',
        'fee_amount' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $amendment) {
            if (empty($amendment->currency)) {
                $amendment->currency = 'INR';
            }

            if (empty($amendment->fee_amount)) {
                $amendment->fee_amount = '0.00';
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
}
