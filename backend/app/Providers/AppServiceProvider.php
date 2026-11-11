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
        //
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
    }
}
