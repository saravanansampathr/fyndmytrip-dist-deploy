<?php

namespace App\Domains\Booking\Models;

use App\Domains\Tenancy\Concerns\BelongsToTenant;
use App\Domains\Wallet\Models\WalletLedgerEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The Hotel counterpart to GroupBookingRequest — a B2B user's request for a
 * group-desk negotiated hotel rate (deposit + rate hold + rooming-list
 * deadline), the side-by-side alternative to instant bulk booking
 * (HotelBookingController::MAX_ROOMS_B2B_BULK) for when the requester wants
 * staff to negotiate a block rate rather than book at the listed instant
 * price. See this class's migration for why the "10+ rooms" threshold is
 * grounded in the hotel industry's own group-booking convention, not a copy
 * of the flight one.
 *
 * State machine (see HotelGroupBookingService for every transition):
 *
 *   pending_quote --quote()--> quoted --acceptQuote()--> deposit_paid
 *       |                        |                          |
 *       reject()                 cancel()/markExpired()     submitRoomingList()
 *       v                        v                          v
 *   rejected                 cancelled/expired        rooming_list_submitted
 *                                                             |
 *                                                          finalize()
 *                                                             v
 *                                                         finalized
 *
 * Both money movements (deposit at acceptQuote(), any balance at
 * finalize()) go through WalletService against the REQUESTING USER's own
 * wallet — same model GroupBookingRequest already uses. Post-deposit
 * cancellation/refund is deliberately out of scope for this increment, same
 * posture as its flight counterpart.
 */
class HotelGroupBookingRequest extends Model
{
    use BelongsToTenant;

    public const STATUS_PENDING_QUOTE = 'pending_quote';
    public const STATUS_QUOTED = 'quoted';
    public const STATUS_DEPOSIT_PAID = 'deposit_paid';
    public const STATUS_ROOMING_LIST_SUBMITTED = 'rooming_list_submitted';
    public const STATUS_FINALIZED = 'finalized';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_EXPIRED = 'expired';

    public const STATUSES = [
        self::STATUS_PENDING_QUOTE,
        self::STATUS_QUOTED,
        self::STATUS_DEPOSIT_PAID,
        self::STATUS_ROOMING_LIST_SUBMITTED,
        self::STATUS_FINALIZED,
        self::STATUS_REJECTED,
        self::STATUS_CANCELLED,
        self::STATUS_EXPIRED,
    ];

    /** Universal hotel-industry convention: a block of 10+ rooms is a group booking (below this, instant bulk booking already covers it directly). */
    public const MINIMUM_ROOMS_COUNT = 10;

    protected $fillable = [
        'tenant_id',
        'requested_by_user_id',
        'city',
        'check_in_date',
        'check_out_date',
        'rooms_requested',
        'guest_count',
        'room_type_preference',
        'meal_plan_preference',
        'contact_name',
        'contact_email',
        'contact_phone',
        'remarks',
        'status',
        'quoted_rate_per_room',
        'quoted_total_fare',
        'deposit_amount',
        'quote_remarks',
        'quoted_by_user_id',
        'quoted_at',
        'rate_hold_expires_at',
        'rooming_list_deadline',
        'deposit_paid_at',
        'deposit_wallet_ledger_entry_id',
        'finalized_by_user_id',
        'finalized_at',
        'final_booking_reference',
        'balance_wallet_ledger_entry_id',
        'rejection_reason',
    ];

    protected $casts = [
        'check_in_date' => 'date',
        'check_out_date' => 'date',
        'quoted_rate_per_room' => 'decimal:2',
        'quoted_total_fare' => 'decimal:2',
        'deposit_amount' => 'decimal:2',
        'quoted_at' => 'datetime',
        'rate_hold_expires_at' => 'date',
        'rooming_list_deadline' => 'date',
        'deposit_paid_at' => 'datetime',
        'finalized_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $request) {
            if (empty($request->status)) {
                $request->status = self::STATUS_PENDING_QUOTE;
            }
        });
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function quotedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'quoted_by_user_id');
    }

    public function finalizedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finalized_by_user_id');
    }

    public function depositLedgerEntry(): BelongsTo
    {
        return $this->belongsTo(WalletLedgerEntry::class, 'deposit_wallet_ledger_entry_id');
    }

    public function balanceLedgerEntry(): BelongsTo
    {
        return $this->belongsTo(WalletLedgerEntry::class, 'balance_wallet_ledger_entry_id');
    }

    public function guests(): HasMany
    {
        return $this->hasMany(HotelGroupBookingGuest::class);
    }

    public function isPendingQuote(): bool
    {
        return $this->status === self::STATUS_PENDING_QUOTE;
    }

    public function isQuoted(): bool
    {
        return $this->status === self::STATUS_QUOTED;
    }

    public function isDepositPaid(): bool
    {
        return $this->status === self::STATUS_DEPOSIT_PAID;
    }

    public function isRoomingListSubmitted(): bool
    {
        return $this->status === self::STATUS_ROOMING_LIST_SUBMITTED;
    }

    public function balanceDue(): string
    {
        $total = (string) ($this->quoted_total_fare ?? '0.00');
        $deposit = (string) ($this->deposit_amount ?? '0.00');

        return (string) \Brick\Math\BigDecimal::of($total)->minus(\Brick\Math\BigDecimal::of($deposit))->toScale(2, \Brick\Math\RoundingMode::HALF_UP);
    }
}
