<?php

namespace Database\Seeders;

use App\Domains\Suppliers\Adapters\MockCabSupplierAdapter;
use App\Domains\Suppliers\Models\MarkupRule;
use App\Domains\Suppliers\Models\Supplier;
use App\Domains\Suppliers\Models\TenantSupplierConfig;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Services\TenantContext;
use Illuminate\Database\Seeder;

/**
 * Phase 3's third real-booking-flow vertical, after Bus and Hotel. A small,
 * focused, idempotent (firstOrCreate throughout) seeder rather than adding
 * to the big TenantHierarchySeeder — same reasoning as
 * BusSupplierSeeder/HotelSupplierSeeder: this can be run again safely on an
 * existing deployment (`php artisan db:seed --class=CabSupplierSeeder`)
 * without touching tenants/users/wallets that seeder already set up.
 *
 * Wires up the mock cab supplier (MOCKCAB) for the Master tenant exactly
 * like BusSupplierSeeder/HotelSupplierSeeder do for their own modules,
 * plus a default markup rule for module 'cab' so
 * CabBookingController::search()'s markup calculation has something to
 * apply.
 */
class CabSupplierSeeder extends Seeder
{
    public function run(): void
    {
        $master = Tenant::query()->where('type', Tenant::TYPE_MASTER)->first();

        if (! $master) {
            $this->command?->warn('CabSupplierSeeder: no Master tenant found — run TenantHierarchySeeder first.');

            return;
        }

        $cabSupplier = Supplier::query()->firstOrCreate(
            ['code' => 'MOCKCAB'],
            [
                'name' => 'Mock Cab Supplier',
                'supported_modules' => ['cab'],
                'adapter_class' => MockCabSupplierAdapter::class,
            ]
        );

        app(TenantContext::class)->set($master)->bypass();

        TenantSupplierConfig::query()->firstOrCreate(
            ['tenant_id' => $master->id, 'supplier_id' => $cabSupplier->id],
            ['credentials_encrypted' => ['api_key' => 'demo'], 'is_active' => true, 'priority_order' => 1]
        );

        MarkupRule::query()->firstOrCreate(
            ['tenant_id' => $master->id, 'module' => 'cab', 'applies_to_role' => 'b2c'],
            ['rule_type' => MarkupRule::TYPE_PERCENTAGE, 'config' => ['percentage' => '10.0']]
        );
    }
}
