<?php

namespace App\Domains\Tenancy\Services;

use App\Domains\Tenancy\Models\Tenant;

/**
 * Request-scoped holder for "which tenant is this request acting as".
 *
 * Bound as a singleton in TenancyServiceProvider so it is shared by the
 * ResolveTenant middleware (which sets it) and BelongsToTenant's global
 * scope (which reads it) for the lifetime of a single HTTP request/queue job.
 */
class TenantContext
{
    protected ?Tenant $tenant = null;

    /**
     * When true, BelongsToTenant must NOT apply its automatic tenant_id
     * filter — used for Super Admin / platform Staff endpoints that
     * deliberately operate across every tenant (e.g. the Master Admin
     * Console's cross-tenant GMV dashboard).
     */
    protected bool $bypassed = false;

    public function set(Tenant $tenant): static
    {
        $this->tenant = $tenant;
        $this->bypassed = false;

        return $this;
    }

    public function get(): ?Tenant
    {
        return $this->tenant;
    }

    public function id(): ?int
    {
        return $this->tenant?->id;
    }

    public function hasTenant(): bool
    {
        return $this->tenant !== null;
    }

    public function bypass(): static
    {
        $this->bypassed = true;

        return $this;
    }

    public function isBypassed(): bool
    {
        return $this->bypassed;
    }

    public function clear(): static
    {
        $this->tenant = null;
        $this->bypassed = false;

        return $this;
    }
}
