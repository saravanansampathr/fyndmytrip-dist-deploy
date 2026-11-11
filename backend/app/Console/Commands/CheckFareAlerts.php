<?php

namespace App\Console\Commands;

use App\Domains\Loyalty\Models\FareAlert;
use App\Domains\Loyalty\Services\FareAlertService;
use App\Domains\Settings\Services\SettingsService;
use Illuminate\Console\Command;

class CheckFareAlerts extends Command
{
    protected $signature = 'fares:check-alerts {--limit=300}';

    protected $description = 'Check saved flight routes and email people when the fare drops';

    public function handle(FareAlertService $service, SettingsService $settings): int
    {
        if (! $settings->value('fare_alerts', 'enabled', true)) {
            return self::SUCCESS;
        }
        $sent = 0;
        FareAlert::query()->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('last_checked_at')->orWhere('last_checked_at', '<', now()->subHours(5)))
            ->orderBy('last_checked_at')->limit((int) $this->option('limit'))->get()
            ->each(function (FareAlert $a) use ($service, &$sent) {
                try {
                    $sent += $service->check($a) ? 1 : 0;
                } catch (\Throwable $e) {
                    report($e);
                }
            });
        $this->info("Sent {$sent} fare alerts.");

        return self::SUCCESS;
    }
}
