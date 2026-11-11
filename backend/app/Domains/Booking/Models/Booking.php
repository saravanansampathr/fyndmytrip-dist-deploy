<?php

namespace App\Domains\Booking\Models;

use App\Domains\Tenancy\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A single generic booking schema serves all eight Product Modules — see
 * Database Design Document §4 and the System Architecture Document's
 * Booking Orchestration Layer. Module-specific detail lives in the module
 * ENUM plus the metadata JSON column and booking_segments, not per-module
 * table forks.
 *
 * @property int $id
 * @property string $booking_reference
 * @property string|null $booking_code
 * @property string|null $channel
 * @property int $tenant_id
 * @property int|null $agent_tenant_id
 * @property int $created_by_user_id
 * @property string $module
 * @property string $supplier_code
 * @property string|null $supplier_booking_ref
 * @property string $status
 * @property string $gross_amount
 * @property string $supplier_net_amount
 * @property string $markup_amount
 * @property string $commission_amount
 */
class Booking extends Model
{
    use BelongsToTenant, HasFactory, SoftDeletes;

    public const MODULE_FLIGHT = 'flight';
    public const MODULE_HOTEL = 'hotel';
    public const MODULE_BUS = 'bus';
    public const MODULE_CAB = 'cab';
    public const MODULE_HOLIDAY = 'holiday';
    public const MODULE_INSURANCE = 'insurance';
    public const MODULE_VISA = 'visa';
    public const MODULE_CRUISE = 'cruise';
    public const MODULE_ACTIVITY = 'activity';

    /**
     * Explicit two-letter code per module for booking_code (below), rather
     * than strtoupper(substr($module, 0, 2)) — that would give 'hotel' and
     * 'holiday' the same "HO" code, silently colliding. Every other module's
     * code here matches what substr() would already produce, so this only
     * changes 'holiday' bookings going forward; existing booking_code values
     * are immutable (set once, never regenerated) so nothing already issued
     * changes.
     */
    public const MODULE_CODES = [
        self::MODULE_FLIGHT => 'FL',
        self::MODULE_HOTEL => 'HO',
        self::MODULE_BUS => 'BU',
        self::MODULE_CAB => 'CA',
        self::MODULE_HOLIDAY => 'HD',
        self::MODULE_INSURANCE => 'IN',
        self::MODULE_VISA => 'VI',
        self::MODULE_CRUISE => 'CR',
        self::MODULE_ACTIVITY => 'AC',
    ];

    public const STATUS_CART = 'cart';
    public const STATUS_HELD = 'held';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_TICKETED = 'ticketed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_FAILED = 'failed';
    public const STATUS_REFUNDED = 'refunded';
    /**
     * "A real refund can take days to actually land (supplier confirms
     * cancellation, then gateway processes it)" — the booking sits here,
     * distinct from looking simply cancelled or stuck, between staff
     * clicking "Initiate refund" and RefundService confirming the money
     * actually moved. See RefundService and RefundTransaction.
     */
    public const STATUS_REFUND_PROCESSING = 'refund_processing';

    /**
     * Who this booking belongs to for reporting/comms purposes — "need to
     * differentiate the Customers like direct or agent or Whitelabel
     * users." Snapshotted from the booking tenant's Tenant::type at
     * creation time rather than joined out later, so it stays accurate
     * even if that tenant is later re-typed or removed.
     */
    public const CHANNEL_DIRECT = 'direct';
    public const CHANNEL_AGENT = 'agent';
    public const CHANNEL_SUB_AGENT = 'sub_agent';
    public const CHANNEL_WHITE_LABEL = 'white_label';
    public const CHANNEL_API_PARTNER = 'api_partner';

    public const CHANNELS = [
        self::CHANNEL_DIRECT,
        self::CHANNEL_AGENT,
        self::CHANNEL_SUB_AGENT,
        self::CHANNEL_WHITE_LABEL,
        self::CHANNEL_API_PARTNER,
    ];

    protected $fillable = [
        'tenant_id',
        'agent_tenant_id',
        'booking_code',
        'channel',
        'created_by_user_id',
        'module',
        'is_international',
        'supplier_code',
        'supplier_booking_ref',
        'status',
        'gross_amount',
        'supplier_net_amount',
        'markup_amount',
        'commission_amount',
        'currency',
        'travel_date_start',
        'travel_date_end',
        'passenger_count',
        'contact_email',
        'contact_phone',
        'extras_amount',
        'coupon_code',
        'discount_amount',
        'total_amount',
        'metadata',
    ];

    protected $casts = [
        'is_international' => 'boolean',
        'gross_amount' => 'decimal:2',
        'supplier_net_amount' => 'decimal:2',
        'markup_amount' => 'decimal:2',
        'commission_amount' => 'decimal:2',
        'extras_amount' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'travel_date_start' => 'date',
        'travel_date_end' => 'date',
        'metadata' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $booking) {
            if (empty($booking->booking_reference)) {
                $booking->booking_reference = (string) \Illuminate\Support\Str::uuid();
            }

            if (empty($booking->status)) {
                $booking->status = self::STATUS_CART;
            }

            if (empty($booking->currency)) {
                $booking->currency = 'INR';
            }

            if (empty($booking->markup_amount)) {
                $booking->markup_amount = '0.00';
            }

            if (empty($booking->commission_amount)) {
                $booking->commission_amount = '0.00';
            }

            if (empty($booking->passenger_count)) {
                $booking->passenger_count = 1;
            }

            if (empty($booking->extras_amount)) {
                $booking->extras_amount = '0.00';
            }

            if (empty($booking->discount_amount)) {
                $booking->discount_amount = '0.00';
            }

            if (empty($booking->total_amount)) {
                $booking->total_amount = $booking->gross_amount;
            }
        });

        // booking_code needs the auto-increment id, so it's assigned right
        // after insert rather than in creating() — "need proper booking ID
        // for each product along with PNR or ticket num", distinct from
        // both the internal booking_reference UUID and the supplier's PNR.
        //
        // Format: FMT + product code + YYMMDD (the date this booking was
        // made) + a per-product sequence that resets every day and starts
        // over at 1 — "FMTFL26092201 then 02... with same sequence on same
        // day". Padded to a minimum of 2 digits (01, 02, ...) but never
        // truncated as the count grows — "goes 100 then show 100 ... don't
        // add the zero before that": str_pad only pads UP TO a width, it
        // never cuts anything longer, so the 100th booking that day reads
        // "100", not "00100", and it keeps growing digits from there with
        // no upper cap ("1 to infinity").
        //
        // The count is derived from existing rows (this module, today,
        // id <= this booking's own id) rather than a separate counter
        // table — every insert gets its own strictly-increasing id from
        // the database itself, so two bookings created at the same instant
        // still land on different, gap-free sequence numbers. withTrashed()
        // because a booking's position in the day shouldn't shift if an
        // earlier one is later cancelled/soft-deleted.
        static::created(function (self $booking) {
            if (empty($booking->booking_code)) {
                $moduleCode = self::MODULE_CODES[$booking->module] ?? strtoupper(substr($booking->module, 0, 2));
                $datePart = now()->format('ymd');

                $sequence = self::withoutTenantScope()
                    ->withTrashed()
                    ->where('module', $booking->module)
                    ->whereDate('created_at', now())
                    ->where('id', '<=', $booking->id)
                    ->count();

                $sequencePart = str_pad((string) $sequence, 2, '0', STR_PAD_LEFT);

                $booking->updateQuietly(['booking_code' => "FMT{$moduleCode}{$datePart}{$sequencePart}"]);
            }
        });
    }

    /** Booking::CHANNEL_* for a booking made under this tenant — see the CHANNEL_* constants' docblock. */
    public static function channelForTenant(?Tenant $tenant): string
    {
        return match ($tenant?->type) {
            Tenant::TYPE_AGENT => self::CHANNEL_AGENT,
            Tenant::TYPE_SUB_AGENT => self::CHANNEL_SUB_AGENT,
            Tenant::TYPE_WHITE_LABEL => self::CHANNEL_WHITE_LABEL,
            Tenant::TYPE_API_PARTNER => self::CHANNEL_API_PARTNER,
            default => self::CHANNEL_DIRECT,
        };
    }

    /**
     * What the customer actually owes right now: base fare + markup,
     * plus seats/meals/baggage/insurance, minus any applied coupon. This
     * is recalculated whenever extras or a coupon change (see
     * FlightBookingController::extras()/applyCoupon()) and is what
     * confirm() debits from the wallet.
     */
    public function recalculateTotal(): string
    {
        $total = \Brick\Math\BigDecimal::of((string) $this->gross_amount)
            ->plus((string) $this->extras_amount)
            ->minus((string) $this->discount_amount)
            // TCS collected on overseas tour packages (see TaxService::tcsFor()).
            ->plus((string) ($this->metadata['tcs_amount'] ?? '0'));

        return (string) $total->toScale(2, \Brick\Math\RoundingMode::HALF_UP);
    }

    public function ancillaries(): HasMany
    {
        return $this->hasMany(BookingAncillary::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function agentTenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'agent_tenant_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function passengers(): HasMany
    {
        return $this->hasMany(BookingPassenger::class);
    }

    public function segments(): HasMany
    {
        return $this->hasMany(BookingSegment::class)->orderBy('sequence');
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(BookingStatusHistory::class)->orderBy('created_at');
    }

    public function events(): HasMany
    {
        return $this->hasMany(BookingEvent::class)->orderBy('created_at');
    }

    public function refundTransactions(): HasMany
    {
        return $this->hasMany(RefundTransaction::class)->orderBy('created_at');
    }

    public function amendments(): HasMany
    {
        return $this->hasMany(BookingAmendment::class)->orderBy('created_at');
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [self::STATUS_CANCELLED, self::STATUS_FAILED, self::STATUS_REFUNDED], true);
    }

    /**
     * Not paid for yet (or never will be): no ticket, voucher or policy
     * document may be issued for it.
     */
    public function isAwaitingPayment(): bool
    {
        return in_array($this->status, [self::STATUS_CART, self::STATUS_HELD, self::STATUS_FAILED], true);
    }
}
