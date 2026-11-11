<?php

namespace App\Console\Commands;

use App\Domains\Booking\Models\Booking;
use App\Domains\Booking\Services\BookingLifecycle;
use App\Domains\Notifications\Services\NotificationService;
use App\Domains\Settings\Services\SettingsService;
use Illuminate\Console\Command;

/**
 * Emails the alert list about paid bookings still not ticketed close to
 * departure / check-in (Admin settings → Platform → ticketing alert hours).
 * Each booking is reported once.
 */
class TicketingDeadlineAlerts extends Command
{
    protected $signature = 'bookings:ticketing-alerts';

    protected $description = 'Alert staff about confirmed bookings not yet ticketed close to travel';

    public function handle(SettingsService $settings, NotificationService $notify): int
    {
        $hours = (int) $settings->value('platform', 'ticketing_alert_hours', 24);
        $n = 0;
        Booking::withoutTenantScope()->where('status', Booking::STATUS_CONFIRMED)
            ->whereNotIn('module', BookingLifecycle::CONFIRM_ONLY_MODULES)
            ->whereNotNull('travel_date_start')->where('travel_date_start', '<=', now()->addHours($hours))
            ->where('travel_date_start', '>=', now()->subDay()->startOfDay())
            ->get()->each(function (Booking $b) use ($notify, &$n) {
                $meta = $b->metadata ?? [];
                if (! empty($meta['ticketing_alerted_at'])) {
                    return;
                }
                $notify->alertStaff('staff_booking_attention', $notify->bookingVars($b, [
                    'reason' => 'Paid but still not ticketed, and travel is on '.$b->travel_date_start->toDateString().'. Issue it (Failed bookings → Issue by hand) or refund.',
                ]), $b->id);
                $meta['ticketing_alerted_at'] = now()->toIso8601String();
                $b->forceFill(['metadata' => $meta])->saveQuietly();
                $n++;
            });
        $this->info("Alerted {$n} bookings.");

        return self::SUCCESS;
    }
}
