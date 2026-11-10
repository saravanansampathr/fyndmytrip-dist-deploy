<?php

namespace Database\Seeders;

use App\Domains\Suppliers\Adapters\MockCruiseSupplierAdapter;
use App\Domains\Suppliers\Models\MarkupRule;
use App\Domains\Suppliers\Models\Supplier;
use App\Domains\Suppliers\Models\TenantSupplierConfig;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Services\TenantContext;
use Illuminate\Database\Seeder;

/**
 * Phase 3's fifth and final real-booking-flow vertical, after Bus, Hotel,
 * Cab and Insurance. A small, focused, idempotent (firstOrCreate
 * throughout) seeder rather than adding to the big TenantHierarchySeeder —
 * same reasoning as BusSupplierSeeder/HotelSupplierSeeder/
 * CabSupplierSeeder/InsuranceSupplierSeeder: this can be run again safely
 * on an existing deployment (`php artisan db:seed --class=CruiseSupplierSeeder`)
 * without touching tenants/users/wallets that seeder already set up.
 *
 * Wires up the mock cruise supplier (MOCKCRUISE) for the Master tenant
 * exactly like the other four seeders do for their own modules, plus a
 * default markup rule for module 'cruise' so
 * CruiseBookingController::search()'s markup calculation has something to
 * apply.
 */
class CruiseSupplierSeeder extends Seeder
{
    public function run(): void
    {
        $master = Tenant::query()->where('type', Tenant::TYPE_MASTER)->first();

        if (! $master) {
            $this->command?->warn('CruiseSupplierSeeder: no Master tenant found — run TenantHierarchySeeder first.');

            return;
        }

        $cruiseSupplier = Supplier::query()->firstOrCreate(
            ['code' => 'MOCKCRUISE'],
            [
                'name' => 'Mock Cruise Supplier',
                'supported_modules' => ['cruise'],
                'adapter_class' => MockCruiseSupplierAdapter::class,
            ]
        );

        app(TenantContext::class)->set($master)->bypass();

        TenantSupplierConfig::query()->firstOrCreate(
            ['tenant_id' => $master->id, 'supplier_id' => $cruiseSupplier->id],
            ['credentials_encrypted' => ['api_key' => 'demo'], 'is_active' => true, 'priority_order' => 1]
        );

        MarkupRule::query()->firstOrCreate(
            ['tenant_id' => $master->id, 'module' => 'cruise', 'applies_to_role' => 'b2c'],
            ['rule_type' => MarkupRule::TYPE_PERCENTAGE, 'config' => ['percentage' => '11.0']]
        );
    }
}
