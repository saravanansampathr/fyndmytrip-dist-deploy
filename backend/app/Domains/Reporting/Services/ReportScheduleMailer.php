<?php

namespace App\Domains\Reporting\Services;

use App\Domains\Reporting\Models\ReportSchedule;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Services\TenantService;
use App\Support\PdfBranding;
use App\Support\TenantMailBranding;
use App\Support\XlsxWriter;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

/**
 * The actual "generate + email one report schedule" action, extracted out
 * of the console command so the Settings UI's "send a test now" button
 * (ReportScheduleController::sendNow()) can trigger exactly the same send
 * a user would otherwise wait for the 2am cron to do, rather than
 * reimplementing it or only being able to fire the WHOLE due-today batch
 * via the command.
 */
class ReportScheduleMailer
{
    public function __construct(
        protected ReportSummaryService $reportSummaryService,
        protected TenantService $tenantService,
    ) {}

    /**
     * Generates and emails the report for whatever period this schedule's
     * frequency currently covers (ReportSchedule::periodFor()) — $today
     * defaults to right now, but the daily command passes its own clock
     * reading so every schedule processed in one run agrees on "today".
     *
     * @throws RuntimeException when there's nothing sendable (no
     *                           recipients, or the schedule's tenant no
     *                           longer exists) — the caller decides what
     *                           that means (the command disables the
     *                           schedule; the "send test" endpoint just
     *                           reports the error back to the user).
     */
    public function send(ReportSchedule $schedule, ?Carbon $today = null): void
    {
        $today ??= Carbon::now()->startOfDay();
        [$from, $to] = $schedule->periodFor($today);

        $tenantIds = null;

        if ($schedule->tenant_id !== null) {
            $tenant = Tenant::query()->find($schedule->tenant_id);

            if (! $tenant) {
                throw new RuntimeException('That tenant no longer exists.');
            }

            $tenantIds = $this->tenantService->getDescendantIdsRobust($tenant);
        }

        $recipients = array_values(array_filter((array) $schedule->recipients));

        if (empty($recipients)) {
            throw new RuntimeException('This schedule has no recipients configured.');
        }

        $data = $this->reportSummaryService->summarize($tenantIds, $from, $to);

        [$bytes, $filename, $mime] = $schedule->format === ReportSchedule::FORMAT_XLSX
            ? $this->buildXlsx($data)
            : $this->buildPdf($data);

        $branding = TenantMailBranding::resolveForTenantId($schedule->tenant_id);
        $frequencyLabel = ucfirst($schedule->frequency);
        $subject = "{$branding['brand_name']} — {$frequencyLabel} report, {$data['range']['from']} to {$data['range']['to']}";

        $body = "Attached is your {$frequencyLabel} Overview report for {$data['range']['from']} to {$data['range']['to']}.\n\n"
            .'Bookings: '.number_format($data['totals']['bookings_count'])."\n"
            .'Total collected: '.$data['totals']['total_amount']."\n"
            .'Net profit: '.$data['totals']['net_profit_amount']."\n\n"
            .'This is an automated report. Replies to this email are not monitored.';

        // Same Mail::raw + closure idiom as OfflinePaymentService/
        // GroupBookingService::notify() — never queued, from/reply-to
        // resolved per-tenant via TenantMailBranding exactly as those do.
        Mail::raw($body, function ($message) use ($recipients, $subject, $branding, $bytes, $filename, $mime) {
            $message->to($recipients)->subject($subject);

            if ($branding['from_address']) {
                $message->from($branding['from_address'], $branding['from_name']);
            }
            if ($branding['support_email']) {
                $message->replyTo($branding['support_email']);
            }

            $message->attachData($bytes, $filename, ['mime' => $mime]);
        });
    }

    /** @return array{0: string, 1: string, 2: string} */
    protected function buildPdf(array $data): array
    {
        $pdf = Pdf::loadView('pdf.reports-summary', [
            'data' => $data,
            'logo' => PdfBranding::logoDataUri(),
            'generatedAt' => now(),
        ])->setPaper('a4', 'portrait');

        $filename = 'report-summary-'.$data['range']['from'].'-to-'.$data['range']['to'].'.pdf';

        return [$pdf->output(), $filename, 'application/pdf'];
    }

    /** @return array{0: string, 1: string, 2: string} */
    protected function buildXlsx(array $data): array
    {
        $path = XlsxWriter::build($this->reportSummaryService->xlsxSheets($data));
        $bytes = file_get_contents($path);
        @unlink($path);

        $filename = 'report-summary-'.$data['range']['from'].'-to-'.$data['range']['to'].'.xlsx';

        return [$bytes, $filename, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
    }
}
