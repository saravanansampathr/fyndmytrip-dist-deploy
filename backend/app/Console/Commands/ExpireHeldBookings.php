<?php

namespace App\Console\Commands;

use App\Domains\Booking\Models\Booking;
use App\Domains\Booking\Services\BookingLifecycle;
use App\Domains\Booking\Services\BookingStatusService;
use App\Domains\Payments\Models\PaymentTransaction;
use App\Domains\Settings\Services\SettingsService;
use App\Domains\Suppliers\Services\BusSeatMapService;
use App\Domains\Suppliers\Services\FlightSeatMapService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Cancels unpaid held bookings after the time set in Admin settings →
 * Platform (hold_expiry_minutes) and frees their seats. Holiday and visa
 * requests are left alone (ops handle them by hand), as is any booking
 * with money taken or a gateway payment still in progress.
 */
class ExpireHeldBookings extends Command
{
    protected $signature = 'bookings:expire-holds';

    protected $description = 'Cancel unpaid held bookings and release their seats';

    public function handle(SettingsService $settings, BookingStatusService $status): int
    {
        $minutes = (int) $settings->value('platform', 'hold_expiry_minutes', 60);
        if ($minutes <= 0) {
            return self::SUCCESS;
        }
        $n = 0;
        Booking::withoutTenantScope()->where('status', Booking::STATUS_HELD)
            ->whereNotIn('module', BookingLifecycle::CONFIRM_ONLY_MODULES)
            ->where('created_at', '<=', now()->subMinutes($minutes))
            ->orderBy('id')->chunkById(100, function ($bookings) use ($status, &$n) {
                foreach ($bookings as $b) {
                    $paying = PaymentTransaction::withoutTenantScope()->where('booking_id', $b->id)
                        ->where('status', PaymentTransaction::STATUS_INITIATED)->where('updated_at', '>', now()->subMinutes(30))->exists();
                    if ($paying || BookingLifecycle::wasPaid($b)) {
                        continue;
                    }
                    try {
                        $status->transition($b, Booking::STATUS_CANCELLED, null, 'Not paid in time; hold released automatically.');
                        app(FlightSeatMapService::class)->releaseForBooking($b->id);
                        app(BusSeatMapService::class)->releaseForBooking($b->id);
                        $n++;
                    } catch (Throwable $e) {
                        report($e);
                    }
                }
            });
        $this->info("Released {$n} unpaid holds.");

        return self::SUCCESS;
    }
}
