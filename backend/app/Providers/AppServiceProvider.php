<?php

namespace App\Providers;

use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Wallet\Models\Wallet;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\URL;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Caches each account's inventory switches for one request.
        $this->app->scoped(\App\Domains\Tenancy\Services\InventoryAccessService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Behind AWS ALB/CloudFront in every deployed environment (see the
        // Security, Compliance & DevOps Plan) — force HTTPS URL generation
        // so Sanctum cookies and generated links are never emitted as http://.
        if ($this->app->environment('production', 'staging')) {
            URL::forceScheme('https');
        }

        // Wallet::owner() is the app's only polymorphic relation, and
        // wallets.owner_type stores Wallet::OWNER_TENANT/OWNER_USER's plain
        // 'tenant'/'user' strings — not a fully-qualified class name.
        // Without this map, Eloquent tries to instantiate a class literally
        // named "tenant" or "user" and fatals; nothing exercised
        // $wallet->owner until this batch's credit/offline-payment
        // notifications and Credit admin screen started reading it, which
        // is what surfaced that this was always missing.
        Relation::enforceMorphMap([
            Wallet::OWNER_TENANT => Tenant::class,
            Wallet::OWNER_USER => User::class,
        ]);

        // Who changed which business setting (Admin settings → Change history).
        foreach (array_keys(\App\Domains\Audit\Services\AuditTrail::WATCHED) as $class) {
            foreach (['created', 'updated', 'deleted'] as $event) {
                $class::$event(fn ($model) => app(\App\Domains\Audit\Services\AuditTrail::class)->record($model, $event));
            }
        }

        // Server settings that admins now change in Admin settings → Platform
        // (they used to be .env values) override the config defaults.
        app(\App\Domains\Settings\Services\SettingsService::class)->applyPlatformConfig();
    }
}
