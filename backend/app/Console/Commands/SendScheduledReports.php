<?php

namespace App\Console\Commands;

use App\Domains\Reporting\Models\ReportSchedule;
use App\Domains\Reporting\Services\ReportScheduleMailer;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * "Anything missed... scheduled/emailed reports" — the one piece of the
 * Reports module that was pull-only until now. Scheduled once a day
 * (routes/console.php: ->dailyAt('02:00')) and decides for itself, per
 * row, whether today is that schedule's send day
 * (ReportSchedule::isDueToday()) — so one cPanel cron entry
 * (`* * * * * php artisan schedule:run`) covers daily, weekly AND monthly
 * schedules alike; nothing finer-grained needs wiring up in cPanel.
 *
 * The actual "build the report and email it" work lives in
 * ReportScheduleMailer, shared with the Settings UI's "send a test now"
 * button (ReportScheduleController::sendNow()) — this command is just the
 * loop that decides WHICH schedules are due today and keeps last_sent_at
 * in sync.
 */
class SendScheduledReports extends Command
{
    protected $signature = 'reports:send-scheduled';

    protected $description = 'Generate and email every report schedule that is due today.';

    public function __construct(protected ReportScheduleMailer $mailer)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $today = Carbon::now()->startOfDay();

        $schedules = ReportSchedule::query()->where('is_active', true)->get();

        $sent = 0;
        $skipped = 0;

        foreach ($schedules as $schedule) {
            if (! $schedule->isDueToday($today)) {
                continue;
            }

            // Idempotency: already sent today (this command run twice, or
            // fired manually right after the cron already ran) — skip
            // rather than double-emailing the same period.
            if ($schedule->last_sent_at && $schedule->last_sent_at->isSameDay($today)) {
                $skipped++;

                continue;
            }

            try {
                $this->mailer->send($schedule, $today);
                $schedule->forceFill(['last_sent_at' => $today])->save();
                $sent++;
            } catch (Throwable $e) {
                report($e);
                $this->error("Report schedule #{$schedule->id} failed: {$e->getMessage()}");
            }
        }

        $this->info("Sent {$sent} scheduled report(s), skipped {$skipped} already sent today.");

        return self::SUCCESS;
    }
}
