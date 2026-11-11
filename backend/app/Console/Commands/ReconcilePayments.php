<?php

namespace App\Console\Commands;

use App\Domains\Booking\Models\Booking;
use App\Domains\Booking\Services\BookingLifecycle;
use App\Domains\Notifications\Services\NotificationService;
use App\Domains\Payments\Models\PaymentTransaction;
use Illuminate\Console\Command;

/**
 * Every 10 minutes: alert staff (once) about money taken without a
 * finished booking — the gateway confirmed payment but the customer never
 * came back, or a paid booking has sat unticketed for 30 minutes. They
 * appear on Admin settings → Failed bookings.
 */
class ReconcilePayments extends Command
{
    protected $signature = 'payments:reconcile';

    protected $description = 'Alert staff about paid bookings that did not complete';

    public function handle(NotificationService $notify): int
    {
        $alerted = 0;

        $abandoned = PaymentTransaction::withoutTenantScope()->where('status', PaymentTransaction::STATUS_INITIATED)
            ->whereNotNull('gateway_payment_id')->where('updated_at', '<=', now()->subMinutes(15))->get();
        foreach ($abandoned as $txn) {
            $raw = $txn->raw_gateway_response ?? [];
            $booking = Booking::withoutTenantScope()->find($txn->booking_id);
            if (! empty($raw['alerted_at']) || ! $booking || $booking->status !== Booking::STATUS_HELD) {
                continue;
            }
            $notify->alertStaff('staff_booking_attention', $notify->bookingVars($booking, ['reason' => "The {$txn->gateway} payment ({$txn->gateway_payment_id}) arrived but the customer did not finish the booking."]), $booking->id);
            $raw['alerted_at'] = now()->toIso8601String();
            $txn->update(['raw_gateway_response' => $raw]);
            $alerted++;
        }

        $stuck = Booking::withoutTenantScope()->where('status', Booking::STATUS_CONFIRMED)
            ->whereNotIn('module', BookingLifecycle::CONFIRM_ONLY_MODULES)
            ->where('updated_at', '<=', now()->subMinutes(30))->where('updated_at', '>=', now()->subDays(3))->get();
        foreach ($stuck as $booking) {
            if (! empty($booking->metadata['stuck_alerted_at'])) {
                continue;
            }
            $notify->alertStaff('staff_booking_attention', $notify->bookingVars($booking, ['reason' => 'Paid and confirmed 30+ minutes ago but still not ticketed by the supplier.']), $booking->id);
            $booking->forceFill(['metadata' => array_merge($booking->metadata ?? [], ['stuck_alerted_at' => now()->toIso8601String()])])->saveQuietly();
            $alerted++;
        }

        $this->info("Alerts sent: {$alerted}");

        return self::SUCCESS;
    }
}
