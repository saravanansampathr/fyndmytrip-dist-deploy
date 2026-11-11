<?php

namespace Database\Seeders;

use App\Domains\Suppliers\Adapters\MockBusSupplierAdapter;
use App\Domains\Suppliers\Models\MarkupRule;
use App\Domains\Suppliers\Models\Supplier;
use App\Domains\Suppliers\Models\TenantSupplierConfig;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Services\TenantContext;
use Illuminate\Database\Seeder;

/**
 * Phase 3 (New Verticals & Real Booking Flows), started with Bus. A small,
 * focused, idempotent (firstOrCreate throughout) seeder rather than adding
 * to the big TenantHierarchySeeder — same reasoning as RolePermissionSeeder
 * being its own re-runnable seeder: this can be run again safely on an
 * existing deployment (`php artisan db:seed --class=BusSupplierSeeder`)
 * without touching tenants/users/wallets that seeder already set up.
 *
 * Wires up the mock bus supplier (MOCKBUS) for the Master tenant exactly
 * like TenantHierarchySeeder does for MOCKAIR, plus a default markup rule
 * for module 'bus' so BusBookingController::search()'s markup calculation
 * has something to apply — without this, MarkupService::applyMarkup()
 * simply falls back to 0% markup (gross = net), which still works but
 * isn't the intended demo state.
 */
class BusSupplierSeeder extends Seeder
{
    public function run(): void
    {
        $master = Tenant::query()->where('type', Tenant::TYPE_MASTER)->first();

        if (! $master) {
            $this->command?->warn('BusSupplierSeeder: no Master tenant found — run TenantHierarchySeeder first.');

            return;
        }

        $busSupplier = Supplier::query()->firstOrCreate(
            ['code' => 'MOCKBUS'],
            [
                'name' => 'Mock Bus Supplier',
                'supported_modules' => ['bus'],
                'adapter_class' => MockBusSupplierAdapter::class,
            ]
        );

        app(TenantContext::class)->set($master)->bypass();

        TenantSupplierConfig::query()->firstOrCreate(
            ['tenant_id' => $master->id, 'supplier_id' => $busSupplier->id],
            ['credentials_encrypted' => ['api_key' => 'demo'], 'is_active' => true, 'priority_order' => 1]
        );

        MarkupRule::query()->firstOrCreate(
            ['tenant_id' => $master->id, 'module' => 'bus', 'applies_to_role' => 'b2c'],
            ['rule_type' => MarkupRule::TYPE_PERCENTAGE, 'config' => ['percentage' => '8.0']]
        );
    }
}
