<?php

namespace Database\Seeders;

use App\Domains\Suppliers\Adapters\MockHotelSupplierAdapter;
use App\Domains\Suppliers\Models\MarkupRule;
use App\Domains\Suppliers\Models\Supplier;
use App\Domains\Suppliers\Models\TenantSupplierConfig;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Services\TenantContext;
use Illuminate\Database\Seeder;

/**
 * Phase 3's second real-booking-flow vertical, after Bus. A small, focused,
 * idempotent (firstOrCreate throughout) seeder rather than adding to the
 * big TenantHierarchySeeder — same reasoning as BusSupplierSeeder: this can
 * be run again safely on an existing deployment
 * (`php artisan db:seed --class=HotelSupplierSeeder`) without touching
 * tenants/users/wallets that seeder already set up.
 *
 * Wires up the mock hotel supplier (MOCKHOTEL) for the Master tenant
 * exactly like BusSupplierSeeder does for MOCKBUS, plus a default markup
 * rule for module 'hotel' so HotelBookingController::search()'s markup
 * calculation has something to apply.
 */
class HotelSupplierSeeder extends Seeder
{
    public function run(): void
    {
        $master = Tenant::query()->where('type', Tenant::TYPE_MASTER)->first();

        if (! $master) {
            $this->command?->warn('HotelSupplierSeeder: no Master tenant found — run TenantHierarchySeeder first.');

            return;
        }

        $hotelSupplier = Supplier::query()->firstOrCreate(
            ['code' => 'MOCKHOTEL'],
            [
                'name' => 'Mock Hotel Supplier',
                'supported_modules' => ['hotel'],
                'adapter_class' => MockHotelSupplierAdapter::class,
            ]
        );

        app(TenantContext::class)->set($master)->bypass();

        TenantSupplierConfig::query()->firstOrCreate(
            ['tenant_id' => $master->id, 'supplier_id' => $hotelSupplier->id],
            ['credentials_encrypted' => ['api_key' => 'demo'], 'is_active' => true, 'priority_order' => 1]
        );

        MarkupRule::query()->firstOrCreate(
            ['tenant_id' => $master->id, 'module' => 'hotel', 'applies_to_role' => 'b2c'],
            ['rule_type' => MarkupRule::TYPE_PERCENTAGE, 'config' => ['percentage' => '12.0']]
        );
    }
}
