<?php

namespace App\Domains\Booking\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One entry in a booking's audit timeline — everything that isn't a status
 * change (those live in BookingStatusHistory instead; see that model's
 * docblock). event_type is a free-form short code rather than an enum so
 * new sources (a supplier webhook, a payment gateway callback, a future
 * refund service) can start logging without a migration: 'created',
 * 'payment_captured', 'payment_failed', 'supplier_confirmed',
 * 'supplier_error', 'manual_note', 'amended', 'refund_issued', etc.
 */
class BookingEvent extends Model
{
    const UPDATED_AT = null;

    public const TYPE_CREATED = 'created';

    public const TYPE_MANUAL_NOTE = 'manual_note';

    public const TYPE_PAYMENT_CAPTURED = 'payment_captured';

    public const TYPE_PAYMENT_FAILED = 'payment_failed';

    public const TYPE_SUPPLIER_CONFIRMED = 'supplier_confirmed';

    public const TYPE_SUPPLIER_ERROR = 'supplier_error';

    public const TYPE_REFUND_INITIATED = 'refund_initiated';

    public const TYPE_REFUND_COMPLETED = 'refund_completed';

    public const TYPE_REFUND_FAILED = 'refund_failed';

    public const TYPE_RESCHEDULED = 'rescheduled';

    public const TYPE_REISSUED = 'reissued';

    protected $fillable = [
        'booking_id',
        'event_type',
        'message',
        'meta',
        'actor_user_id',
    ];

    protected $casts = [
        'meta' => 'array',
        'created_at' => 'datetime',
    ];

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    /**
     * Convenience logger — the one call-site every future integration
     * (payment webhook, supplier callback, manual staff action) should use
     * rather than instantiating this model directly, so the shape stays
     * consistent.
     */
    public static function log(int $bookingId, string $eventType, string $message, ?User $actor = null, array $meta = []): self
    {
        return self::create([
            'booking_id' => $bookingId,
            'event_type' => $eventType,
            'message' => $message,
            'meta' => $meta ?: null,
            'actor_user_id' => $actor?->id,
        ]);
    }
}
