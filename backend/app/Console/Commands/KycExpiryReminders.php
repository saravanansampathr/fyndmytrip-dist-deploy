<?php

namespace App\Console\Commands;

use App\Domains\Kyc\Models\KycDocument;
use App\Domains\Notifications\Services\NotificationService;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Emails an agent when one of their KYC documents (for example an IATA
 * certificate) expires within 30 days, and tells staff. Each document is
 * reminded at most once every 14 days.
 */
class KycExpiryReminders extends Command
{
    protected $signature = 'kyc:expiry-reminders {--days=30}';

    protected $description = 'Remind agents and staff about KYC documents that expire soon';

    public function handle(NotificationService $notify): int
    {
        $n = 0;
        KycDocument::query()->whereNotNull('tenant_id')->whereNotNull('expires_on')
            ->where('status', 'verified')
            ->whereDate('expires_on', '<=', now()->addDays((int) $this->option('days')))
            ->where(fn ($q) => $q->whereNull('reminded_at')->orWhere('reminded_at', '<', now()->subDays(14)))
            ->get()->each(function (KycDocument $doc) use ($notify, &$n) {
                $tenant = Tenant::query()->find($doc->tenant_id);
                if (! $tenant) {
                    return;
                }
                $label = KycDocument::TYPES[$doc->type] ?? $doc->type;
                $when = $doc->expires_on->isPast() ? 'expired on '.$doc->expires_on->toDateString() : 'expires on '.$doc->expires_on->toDateString();
                if ($tenant->support_email) {
                    try {
                        Mail::raw("Hello,\n\nThe {$label} on file for {$tenant->name} {$when}. Please send us the renewed document so your account stays active.\n\nThank you.",
                            fn ($m) => $m->to($tenant->support_email)->subject("Your {$label} {$when}"));
                    } catch (\Throwable $e) {
                        report($e);
                    }
                }
                try {
                    $notify->alertStaff('staff_alert', ['booking_code' => 'Agent KYC', 'reason' => "{$tenant->name}: {$label} {$when}."]);
                } catch (\Throwable) {
                }
                $doc->forceFill(['reminded_at' => now()])->save();
                $n++;
            });
        $this->info("Reminded {$n} documents.");

        return self::SUCCESS;
    }
}
