<?php

namespace App\Providers;

use App\Domains\Tenancy\Services\TenantContext;
use Illuminate\Support\ServiceProvider;

class TenancyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One TenantContext per request/queue-job lifecycle — set by the
        // ResolveTenant middleware, read by BelongsToTenant's TenantScope.
        $this->app->singleton(TenantContext::class);
    }

    public function boot(): void
    {
        //
    }
}
