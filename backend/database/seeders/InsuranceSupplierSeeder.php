<?php

namespace Database\Seeders;

use App\Domains\Suppliers\Adapters\MockInsuranceSupplierAdapter;
use App\Domains\Suppliers\Models\MarkupRule;
use App\Domains\Suppliers\Models\Supplier;
use App\Domains\Suppliers\Models\TenantSupplierConfig;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Services\TenantContext;
use Illuminate\Database\Seeder;

/**
 * Phase 3's fourth real-booking-flow vertical, after Bus, Hotel and Cab. A
 * small, focused, idempotent (firstOrCreate throughout) seeder rather than
 * adding to the big TenantHierarchySeeder — same reasoning as
 * BusSupplierSeeder/HotelSupplierSeeder/CabSupplierSeeder: this can be run
 * again safely on an existing deployment
 * (`php artisan db:seed --class=InsuranceSupplierSeeder`) without touching
 * tenants/users/wallets that seeder already set up.
 *
 * Wires up the mock insurance supplier (MOCKINSURANCE) for the Master
 * tenant exactly like BusSupplierSeeder/HotelSupplierSeeder/
 * CabSupplierSeeder do for their own modules, plus a default markup rule
 * for module 'insurance' so InsuranceBookingController::search()'s markup
 * calculation has something to apply.
 */
class InsuranceSupplierSeeder extends Seeder
{
    public function run(): void
    {
        $master = Tenant::query()->where('type', Tenant::TYPE_MASTER)->first();

        if (! $master) {
            $this->command?->warn('InsuranceSupplierSeeder: no Master tenant found — run TenantHierarchySeeder first.');

            return;
        }

        $insuranceSupplier = Supplier::query()->firstOrCreate(
            ['code' => 'MOCKINSURANCE'],
            [
                'name' => 'Mock Insurance Supplier',
                'supported_modules' => ['insurance'],
                'adapter_class' => MockInsuranceSupplierAdapter::class,
            ]
        );

        app(TenantContext::class)->set($master)->bypass();

        TenantSupplierConfig::query()->firstOrCreate(
            ['tenant_id' => $master->id, 'supplier_id' => $insuranceSupplier->id],
            ['credentials_encrypted' => ['api_key' => 'demo'], 'is_active' => true, 'priority_order' => 1]
        );

        MarkupRule::query()->firstOrCreate(
            ['tenant_id' => $master->id, 'module' => 'insurance', 'applies_to_role' => 'b2c'],
            ['rule_type' => MarkupRule::TYPE_PERCENTAGE, 'config' => ['percentage' => '15.0']]
        );
    }
}
