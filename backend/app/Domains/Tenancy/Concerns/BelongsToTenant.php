<?php

namespace App\Domains\Tenancy\Concerns;

use App\Domains\Tenancy\Scopes\TenantScope;
use App\Domains\Tenancy\Services\TenantContext;

/**
 * Applied to every tenant-scoped Eloquent model (anything with a tenant_id
 * column). Registers the global TenantScope AND auto-fills tenant_id on
 * creation from the bound TenantContext, so application code never has to
 * remember to set it manually on every insert.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function ($model) {
            if (empty($model->tenant_id)) {
                $context = app(TenantContext::class);

                if ($context->hasTenant()) {
                    $model->tenant_id = $context->id();
                }
            }
        });
    }

    /**
     * Explicitly query across all tenants, bypassing the scope for this
     * one query — used by Super Admin / Staff cross-tenant reporting.
     */
    public static function withoutTenantScope()
    {
        return static::withoutGlobalScope(TenantScope::class);
    }
}
