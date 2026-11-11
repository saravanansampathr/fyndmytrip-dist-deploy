<?php

namespace App\Domains\Loyalty\Services;

use App\Domains\Loyalty\Models\FareAlert;
use App\Domains\Notifications\Services\NotificationService;
use App\Support\TenantMailBranding;
use App\Domains\Settings\Services\SettingsService;
use App\Domains\Suppliers\DTOs\SearchRequest;
use App\Domains\Suppliers\Services\MarkupService;
use App\Domains\Suppliers\Services\SupplierOrchestrator;
use App\Domains\Tenancy\Models\Tenant;

/**
 * Checks saved flight routes and emails the person when the lowest fare
 * drops by at least min_drop_percent, or reaches their target price.
 */
class FareAlertService
{
    public function lowestFare(FareAlert $alert, Tenant $tenant): ?float
    {
        $offers = app(SupplierOrchestrator::class)->search($tenant, new SearchRequest(
            module: 'flight', origin: $alert->origin, destination: $alert->destination,
            departureDate: $alert->depart_date->toDateString(), passengerCount: 1,
            extra: ['cabin_class' => $alert->cabin_class, 'fare_type' => 'regular'],
        ));
        if (! $offers) {
            return null;
        }
        $net = min(array_map(fn ($o) => (float) $o['net_amount'], $offers));
        $priced = app(MarkupService::class)->applyMarkup($tenant, 'flight', 'b2c', number_format($net, 2, '.', ''), [
            'passenger_count' => 1, 'origin' => $alert->origin, 'destination' => $alert->destination,
        ]);

        return (float) ($priced['gross_amount'] ?? $net);
    }

    /** @return bool whether the person was emailed */
    public function check(FareAlert $alert): bool
    {
        if ($alert->depart_date->isPast() && ! $alert->depart_date->isToday()) {
            $alert->update(['is_active' => false]);

            return false;
        }
        $tenant = Tenant::query()->find($alert->tenant_id);
        if (! $tenant) {
            return false;
        }
        $price = $this->lowestFare($alert, $tenant);
        $before = $alert->last_price !== null ? (float) $alert->last_price : null;
        $alert->forceFill(['last_checked_at' => now()])->save();
        if ($price === null) {
            return false;
        }
        $minDrop = (float) app(SettingsService::class)->value('fare_alerts', 'min_drop_percent', 5);
        $hitTarget = $alert->target_price !== null && $price <= (float) $alert->target_price && ($before === null || $price < $before);
        $dropped = $before !== null && $price <= $before * (1 - $minDrop / 100);
        $alert->forceFill(['last_price' => $price])->save();
        if (! $hitTarget && ! $dropped) {
            return false;
        }

        $brand = TenantMailBranding::resolve($tenant);
        $site = rtrim((string) ($tenant->custom_domain ? 'https://'.$tenant->custom_domain : config('app.frontend_url', 'https://demo.fyndmytrip.in')), '/');
        app(NotificationService::class)->send('fare_drop', 'email', $alert->email, [
            'customer_name' => optional(\App\Models\User::query()->find($alert->user_id))->name ?: 'Traveller',
            'trip' => $alert->origin.' → '.$alert->destination,
            'travel_date' => $alert->depart_date->format('d M Y'),
            'amount' => $alert->currency.' '.number_format($price, 0),
            'old_amount' => $before !== null ? $alert->currency.' '.number_format($before, 0) : 'not known',
            'manage_url' => $site.'/#/?s=flight&from='.$alert->origin.'&to='.$alert->destination.'&date='.$alert->depart_date->toDateString(),
            'unsubscribe_url' => rtrim((string) config('app.url'), '/').'/api/fare-alerts/stop/'.$alert->token,
            'brand_name' => $brand['brand_name'], 'support_email' => $brand['support_email'], 'support_phone' => $brand['support_phone'] ?? '',
        ], $tenant);
        $alert->forceFill(['notified_at' => now()])->save();

        return true;
    }
}
