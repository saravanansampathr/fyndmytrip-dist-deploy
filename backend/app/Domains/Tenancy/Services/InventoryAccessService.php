<?php

namespace App\Domains\Tenancy\Services;

use App\Domains\Settings\Services\SettingsService;
use App\Domains\Suppliers\Models\Supplier;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Models\TenantInventoryAccess;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

/**
 * FyndMyTrip is the master account; every other account (white-label
 * partner, agent, sub-agent, API partner) hangs below it. The master's
 * inventories are shared down the tree only where they are switched on:
 *
 *  - An inventory is a supplier connection (Akbar, TBO, TripJack, the
 *    demo suppliers, and any supplier added later) or 'own_inventory':
 *    the hotels, buses, flights, cabs and other stock that the master
 *    (or another account sharing with the network) loads by hand.
 *  - Each account has an on/off per inventory. With nothing set it
 *    follows the platform default ('all' or 'none').
 *  - An account gets an inventory only if it is on for the account AND
 *    for every account above it (a white-label partner's agents never see
 *    more than the partner has).
 *  - An account's own supplier contracts (its own API keys under Supplier
 *    connections) are always its own and are not affected.
 */
class InventoryAccessService
{
    public const OWN = 'own_inventory';

    /** @var array<int, array<string, bool>> explicit rows per tenant, per request */
    protected array $explicit = [];

    protected ?string $default = null;

    public function __construct(protected SettingsService $settings) {}

    /**
     * Every inventory an admin can assign.
     *
     * @return array<int, array{key: string, name: string, modules: array, kind: string}>
     */
    public function catalog(): array
    {
        $rows = Supplier::query()->orderBy('name')->get()->map(fn (Supplier $s) => [
            'key' => $s->code,
            'name' => $s->name,
            'modules' => array_values((array) $s->supported_modules),
            'kind' => 'supplier',
        ])->all();

        $rows[] = [
            'key' => self::OWN,
            'name' => 'Own inventory (hotels, buses, flights, cabs and more loaded by hand)',
            'modules' => ['flight', 'hotel', 'bus', 'cab', 'insurance', 'cruise', 'activity'],
            'kind' => 'own',
        ];

        return $rows;
    }

    public function defaultAccess(): string
    {
        return $this->default ??= ($this->settings->value('inventory', 'default_access', 'all') === 'none' ? 'none' : 'all');
    }

    /** @return array<string, bool> */
    public function explicitFor(int $tenantId): array
    {
        if (! array_key_exists($tenantId, $this->explicit)) {
            $this->explicit[$tenantId] = Schema::hasTable('tenant_inventory_access')
                ? TenantInventoryAccess::query()->where('tenant_id', $tenantId)->pluck('is_enabled', 'inventory_key')->map(fn ($v) => (bool) $v)->all()
                : [];
        }

        return $this->explicit[$tenantId];
    }

    /** On for this one account, ignoring the accounts above it. */
    public function ownSetting(Tenant $tenant, string $key): bool
    {
        if ($tenant->type === Tenant::TYPE_MASTER) {
            return true;
        }

        return $this->explicitFor($tenant->id)[$key] ?? ($this->defaultAccess() === 'all');
    }

    /** May this account sell from this inventory (checked up the tree)? */
    public function allows(?Tenant $tenant, string $key): bool
    {
        $seen = 0;
        for ($t = $tenant; $t && $seen < 12; $seen++) {
            if ($t->type === Tenant::TYPE_MASTER) {
                return true;
            }
            if (! $this->ownSetting($t, $key)) {
                return false;
            }
            $t = $t->parent_tenant_id ? $this->tenant($t->parent_tenant_id) : null;
        }

        return true;
    }

    /**
     * Status of every inventory for one account: 'on', 'off', or
     * 'off_above' (on here but switched off for an account above), and
     * whether it was set for this account or follows the default.
     *
     * @return array<string, array{allowed: bool, state: string, source: string}>
     */
    public function statusFor(Tenant $tenant, ?array $catalog = null): array
    {
        $explicit = $this->explicitFor($tenant->id);
        $out = [];
        foreach ($catalog ?? $this->catalog() as $inv) {
            $k = $inv['key'];
            $own = $this->ownSetting($tenant, $k);
            $allowed = $this->allows($tenant, $k);
            $out[$k] = [
                'allowed' => $allowed,
                'state' => $allowed ? 'on' : ($own ? 'off_above' : 'off'),
                'source' => array_key_exists($k, $explicit) ? 'set' : 'default',
            ];
        }

        return $out;
    }

    /**
     * Switch inventories on (true), off (false) or back to the default
     * (null) for one account.
     *
     * @param  array<string, bool|null>  $changes
     */
    public function set(Tenant $tenant, array $changes, ?User $by = null): void
    {
        foreach ($changes as $key => $value) {
            if ($value === null) {
                TenantInventoryAccess::query()->where('tenant_id', $tenant->id)->where('inventory_key', $key)->get()->each->delete();

                continue;
            }
            $row = TenantInventoryAccess::query()->firstOrNew(['tenant_id' => $tenant->id, 'inventory_key' => $key]);
            if (! $row->exists || $row->is_enabled !== (bool) $value) {
                $row->fill(['is_enabled' => (bool) $value, 'assigned_by_user_id' => $by?->id])->save();
            }
        }
        unset($this->explicit[$tenant->id]);
        $this->bumpVersion();
    }

    /** Changes with every assignment, so cached supplier searches are not reused. */
    public function version(): int
    {
        return (int) cache()->get('fyndmytrip:inventory_access:version', 0);
    }

    public function bumpVersion(): void
    {
        cache()->forever('fyndmytrip:inventory_access:version', $this->version() + 1);
        $this->default = null;
    }

    /**
     * Narrow an own-inventory query (rows with tenant_id) to what the
     * account may see: its own rows always; the master's rows and rows
     * other accounts share with the network only when 'own_inventory' is
     * on for it.
     */
    public function scopeOwnInventory($query, ?int $tenantId): void
    {
        $tenant = $tenantId ? $this->tenant($tenantId) : null;

        if ($tenant && ! $this->allows($tenant, self::OWN)) {
            $query->where('tenant_id', $tenantId);

            return;
        }

        $query->where(function ($q) use ($tenantId) {
            $q->whereNull('tenant_id')
                ->orWhere('tenant_id', $tenantId)
                ->orWhere('share_with_network', true)
                ->when(is_null($tenantId), fn ($q2) => $q2->orWhere('share_on_platform_site', true));
        });
    }

    /**
     * Can this user assign inventories to this account? Platform staff:
     * any account below the master. A partner admin: accounts below their
     * own, and only inventories their own account has.
     */
    public function canManage(User $user, Tenant $tenant): bool
    {
        if ($tenant->type === Tenant::TYPE_MASTER || ! $user->hasPermission('inventory.assign')) {
            return false;
        }
        if ($user->isPlatformStaff()) {
            return true;
        }

        return $tenant->id !== $user->tenant_id && $this->isBelow($tenant, (int) $user->tenant_id);
    }

    public function isBelow(Tenant $tenant, int $ancestorId): bool
    {
        $seen = 0;
        for ($t = $tenant->parent_tenant_id ? $this->tenant($tenant->parent_tenant_id) : null; $t && $seen < 12; $seen++) {
            if ($t->id === $ancestorId) {
                return true;
            }
            $t = $t->parent_tenant_id ? $this->tenant($t->parent_tenant_id) : null;
        }

        return false;
    }

    /** @var array<int, Tenant|null> */
    protected array $tenants = [];

    protected function tenant(int $id): ?Tenant
    {
        return $this->tenants[$id] ??= Tenant::query()->find($id);
    }
}
