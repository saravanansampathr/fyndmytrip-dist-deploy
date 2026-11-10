<?php

namespace App\Domains\Tenancy\Scopes;

use App\Domains\Tenancy\Services\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Row-level multi-tenancy scope — see the System Architecture Document §"Multi-tenancy"
 * and Database Design Document §3: a single shared MySQL database with tenant_id
 * row-level scoping, rather than one database per tenant.
 *
 * Applied via the BelongsToTenant trait to every model that carries a tenant_id
 * column (users, bookings, wallets, coupons, tenant_supplier_configs, etc.).
 */
class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        /** @var TenantContext $context */
        $context = app(TenantContext::class);

        if ($context->isBypassed() || ! $context->hasTenant()) {
            return;
        }

        $builder->where($model->qualifyColumn('tenant_id'), $context->id());
    }
}
