<?php

namespace App\Domains\Booking\Services;

use App\Domains\Booking\Models\Booking;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The only permitted path to change bookings.status — see Database Design
 * Document §4.4/§12. Writes the booking_status_history row first, then
 * updates the parent, inside one transaction, and rejects any transition
 * not in the allowed state-machine map below.
 */
class BookingStatusService
{
    /**
     * @var array<string, array<int, string>>
     */
    protected const ALLOWED_TRANSITIONS = [
        Booking::STATUS_CART => [Booking::STATUS_HELD, Booking::STATUS_FAILED],
        Booking::STATUS_HELD => [Booking::STATUS_CONFIRMED, Booking::STATUS_FAILED, Booking::STATUS_CANCELLED],
        Booking::STATUS_CONFIRMED => [Booking::STATUS_TICKETED, Booking::STATUS_CANCELLED, Booking::STATUS_FAILED],
        Booking::STATUS_TICKETED => [Booking::STATUS_CANCELLED, Booking::STATUS_REFUND_PROCESSING],
        Booking::STATUS_CANCELLED => [Booking::STATUS_REFUND_PROCESSING],
        // A refund in flight either lands (-> REFUNDED, terminal) or fails
        // and reverts to whichever status it was initiated from — see
        // RefundService::fail(), which is the only caller that transitions
        // back to TICKETED/CANCELLED from here.
        Booking::STATUS_REFUND_PROCESSING => [Booking::STATUS_REFUNDED, Booking::STATUS_TICKETED, Booking::STATUS_CANCELLED],
        Booking::STATUS_FAILED => [],
        Booking::STATUS_REFUNDED => [],
    ];

    public function transition(Booking $booking, string $toStatus, ?User $changedBy = null, ?string $reason = null): Booking
    {
        $allowed = self::ALLOWED_TRANSITIONS[$booking->status] ?? [];

        if (! in_array($toStatus, $allowed, true)) {
            throw new RuntimeException("Cannot transition booking #{$booking->id} from '{$booking->status}' to '{$toStatus}'.");
        }

        return DB::transaction(function () use ($booking, $toStatus, $changedBy, $reason) {
            $booking->statusHistory()->create([
                'from_status' => $booking->status,
                'to_status' => $toStatus,
                'changed_by' => $changedBy?->id,
                'reason' => $reason,
                'created_at' => now(),
            ]);

            $booking->update(['status' => $toStatus]);

            return $booking->fresh();
        });
    }
}
