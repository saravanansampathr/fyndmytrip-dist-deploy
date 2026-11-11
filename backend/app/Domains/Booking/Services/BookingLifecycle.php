<?php

namespace App\Domains\Booking\Services;

use App\Domains\Booking\Models\Booking;
use App\Domains\Booking\Models\RefundTransaction;
use App\Domains\Notifications\Services\NotificationService;
use App\Domains\Payments\Models\PaymentTransaction;
use App\Domains\Settings\Services\TaxService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * What happens after a booking changes status: GST invoice when it's paid
 * and confirmed, credit note when money is refunded, and the customer
 * (and, for problems, staff) messages. Called by BookingStatusService for
 * every status change; it never makes the status change itself fail.
 */
class BookingLifecycle
{
    /** Modules whose paid bookings end at 'confirmed' (fulfilled by ops), not 'ticketed'. */
    public const CONFIRM_ONLY_MODULES = [Booking::MODULE_HOLIDAY, Booking::MODULE_VISA];

    public function handle(Booking $booking, string $from, string $to, ?string $reason = null): void
    {
        try {
            $this->documents($booking, $to);
        } catch (Throwable $e) {
            Log::error("Invoice/credit note for booking {$booking->id} failed: ".$e->getMessage());
        }

        $send = function () use ($booking, $from, $to, $reason) {
            $fresh = $booking->fresh(['tenant', 'createdBy']) ?? $booking;
            try {
                $this->messages($fresh, $from, $to, $reason);
            } catch (Throwable $e) {
                Log::warning("Booking {$booking->id} messages failed: ".$e->getMessage());
            }
            // Partner webhooks (signed POST to the partner's own URL).
            try {
                app(\App\Domains\Webhooks\Services\WebhookDispatcher::class)->bookingEvent($fresh, $to);
            } catch (Throwable $e) {
                Log::warning("Booking {$booking->id} webhooks failed: ".$e->getMessage());
            }
        };

        // On the website, send after the page has answered so a slow mail
        // or SMS provider never slows a booking down.
        if (app()->runningInConsole()) {
            $send();
        } else {
            app()->terminating($send);
        }
    }

    protected function documents(Booking $booking, string $to): void
    {
        $tax = app(TaxService::class);

        if ($to === Booking::STATUS_TICKETED || ($to === Booking::STATUS_CONFIRMED && in_array($booking->module, self::CONFIRM_ONLY_MODULES, true))) {
            $tax->invoiceFor($booking);
        }

        if ($to === Booking::STATUS_REFUNDED) {
            $refund = RefundTransaction::query()->where('booking_id', $booking->id)->latest('id')->first();
            $tax->creditNoteFor($booking, (float) ($refund?->requested_amount ?? $booking->total_amount), 'cancellation refund');
        }
    }

    protected function messages(Booking $booking, string $from, string $to, ?string $reason): void
    {
        $notify = app(NotificationService::class);

        if ($to === Booking::STATUS_TICKETED || ($to === Booking::STATUS_CONFIRMED && in_array($booking->module, self::CONFIRM_ONLY_MODULES, true))) {
            $notify->sendForBooking('booking_confirmed', $booking);
        } elseif ($to === Booking::STATUS_CANCELLED) {
            $notify->sendForBooking('booking_cancelled', $booking, ['reason' => (string) $reason]);
        } elseif ($to === Booking::STATUS_REFUNDED) {
            $refund = RefundTransaction::query()->where('booking_id', $booking->id)->latest('id')->first();
            $notify->sendForBooking('refund_completed', $booking, [
                'refund_amount' => $booking->currency.' '.number_format((float) ($refund?->requested_amount ?? $booking->total_amount), 2),
            ]);
        } elseif ($to === Booking::STATUS_FAILED && self::wasPaid($booking)) {
            // Money was taken but the supplier didn't confirm: tell the
            // customer we're on it, and alert staff (Failed bookings page).
            $notify->sendForBooking('booking_failed', $booking, ['reason' => (string) $reason]);
            $notify->alertStaff('staff_booking_attention', $notify->bookingVars($booking, ['reason' => (string) $reason]), $booking->id);
        }
    }

    public static function wasPaid(Booking $booking): bool
    {
        return PaymentTransaction::withoutTenantScope()->where('booking_id', $booking->id)->where('status', PaymentTransaction::STATUS_SUCCESS)->exists()
            || \App\Domains\Wallet\Models\WalletLedgerEntry::query()
                ->where('reference_type', \App\Domains\Wallet\Models\WalletLedgerEntry::REF_BOOKING_PAYMENT)
                ->where('reference_id', $booking->id)->exists();
    }
}
