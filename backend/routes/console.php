<?php

use App\Console\Commands\SendScheduledReports;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// "Anything missed... scheduled/emailed reports" — see
// SendScheduledReports's own docblock for why a single daily run (rather
// than separate daily/weekly/monthly cron timings) is enough to cover
// every frequency. This requires ONE cron entry on the server itself:
//   * * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
// — Laravel's scheduler checks every minute whether anything registered
// here is due, and dailyAt('02:00') only actually fires once a day.
Schedule::command(SendScheduledReports::class)->dailyAt('02:00');

// Phase 1 (launch blockers). All need the same single cron entry above.
Schedule::call(fn () => \Illuminate\Support\Facades\Cache::forever(\App\Domains\Settings\Services\SystemStatus::HEARTBEAT_KEY, now()->toIso8601String()))
    ->everyMinute()->name('scheduler-heartbeat');
Schedule::command(\App\Console\Commands\ReconcilePayments::class)->everyTenMinutes()->withoutOverlapping();
Schedule::command(\App\Console\Commands\BackupDatabase::class)->dailyAt('01:30')->withoutOverlapping();

// Phase 2 jobs (same single cron entry).
Schedule::command(\App\Console\Commands\ExpireHeldBookings::class)->everyFiveMinutes()->withoutOverlapping();
Schedule::command(\App\Console\Commands\TicketingDeadlineAlerts::class)->hourly()->withoutOverlapping();
Schedule::call(fn () => app(\App\Domains\Webhooks\Services\WebhookDispatcher::class)->retryDue())
    ->everyMinute()->name('webhooks-retry')->withoutOverlapping();
