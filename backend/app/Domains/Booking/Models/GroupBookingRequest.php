<?php

namespace App\Domains\Booking\Models;

use App\Domains\Tenancy\Concerns\BelongsToTenant;
use App\Domains\Wallet\Models\WalletLedgerEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * "In flight bookings need group Bookings ... These group bookings only
 * shown to b2b not to b2c users" — a B2B user's request for a fare-desk
 * negotiated group fare (deposit + fare hold + name-list deadline), the
 * side-by-side alternative to instant bulk booking
 * (FlightBookingController::MAX_PASSENGERS_B2B_BULK) for when the requester
 * wants staff to negotiate rather than book at the listed instant fare.
 *
 * State machine (see GroupBookingService for every transition):
 *
 *   pending_quote --quote()--> quoted --acceptQuote()--> deposit_paid
 *       |                        |                          |
 *       reject()                 cancel()/markExpired()     submitNameList()
 *       v                        v                          v
 *   rejected                 cancelled/expired        name_list_submitted
 *                                                             |
 *                                                          finalize()
 *                                                             v
 *                                                         finalized
 *
 * Both money movements (deposit at acceptQuote(), any balance at
 * finalize()) go through WalletService against the REQUESTING USER's own
 * wallet — same "credit is per-user, debits draw on the booker's own
 * balance+credit" model the whole app already uses, never a bespoke ledger
 * or a tenant-shared pool. Post-deposit cancellation/refund is deliberately
 * out of scope for this increment (same "manual, staff-judgement" posture
 * already taken for credit terms) — a requester can only self-cancel before
 * paying the deposit; after that, contact staff.
 */
class GroupBookingRequest extends Model
{
    use BelongsToTenant;

    public const TRIP_ONE_WAY = 'one_way';
    public const TRIP_ROUND_TRIP = 'round_trip';

    public const TRIP_TYPES = [self::TRIP_ONE_WAY, self::TRIP_ROUND_TRIP];

    public const CABIN_CLASSES = ['economy', 'premium_economy', 'business', 'first'];

    public const STATUS_PENDING_QUOTE = 'pending_quote';
    public const STATUS_QUOTED = 'quoted';
    public const STATUS_DEPOSIT_PAID = 'deposit_paid';
    public const STATUS_NAME_LIST_SUBMITTED = 'name_list_submitted';
    public const STATUS_FINALIZED = 'finalized';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_EXPIRED = 'expired';

    public const STATUSES = [
        self::STATUS_PENDING_QUOTE,
        self::STATUS_QUOTED,
        self::STATUS_DEPOSIT_PAID,
        self::STATUS_NAME_LIST_SUBMITTED,
        self::STATUS_FINALIZED,
        self::STATUS_REJECTED,
        self::STATUS_CANCELLED,
        self::STATUS_EXPIRED,
    ];

    /** Real-world group-desk convention (below this, instant bulk booking already covers it directly). */
    public const MINIMUM_PASSENGER_COUNT = 10;

    protected $fillable = [
        'tenant_id',
        'requested_by_user_id',
        'trip_type',
        'origin',
        'destination',
        'depart_date',
        'return_date',
        'passenger_count',
        'cabin_class',
        'flexible_dates',
        'contact_name',
        'contact_email',
        'contact_phone',
        'remarks',
        'status',
        'quoted_fare_per_pax',
        'quoted_total_fare',
        'deposit_amount',
        'quote_remarks',
        'quoted_by_user_id',
        'quoted_at',
        'fare_hold_expires_at',
        'name_list_deadline',
        'deposit_paid_at',
        'deposit_wallet_ledger_entry_id',
        'finalized_by_user_id',
        'finalized_at',
        'final_booking_reference',
        'balance_wallet_ledger_entry_id',
        'rejection_reason',
    ];

    protected $casts = [
        'depart_date' => 'date',
        'return_date' => 'date',
        'flexible_dates' => 'boolean',
        'quoted_fare_per_pax' => 'decimal:2',
        'quoted_total_fare' => 'decimal:2',
        'deposit_amount' => 'decimal:2',
        'quoted_at' => 'datetime',
        'fare_hold_expires_at' => 'date',
        'name_list_deadline' => 'date',
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

    public function passengers(): HasMany
    {
        return $this->hasMany(GroupBookingPassenger::class);
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

    public function isNameListSubmitted(): bool
    {
        return $this->status === self::STATUS_NAME_LIST_SUBMITTED;
    }

    public function balanceDue(): string
    {
        $total = (string) ($this->quoted_total_fare ?? '0.00');
        $deposit = (string) ($this->deposit_amount ?? '0.00');

        return (string) \Brick\Math\BigDecimal::of($total)->minus(\Brick\Math\BigDecimal::of($deposit))->toScale(2, \Brick\Math\RoundingMode::HALF_UP);
    }
}
