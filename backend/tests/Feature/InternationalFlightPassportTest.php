<?php

namespace Tests\Feature;

use App\Domains\Suppliers\Adapters\MockFlightSupplierAdapter;
use App\Domains\Suppliers\Models\Supplier;
use App\Domains\Suppliers\Models\TenantSupplierConfig;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/** International flights can't be held without each traveller's passport. */
class InternationalFlightPassportTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    public function test_international_hold_requires_passport_details(): void
    {
        $master = $this->createMasterTenant();
        $s = Supplier::create(['code' => 'MOCKAIR', 'name' => 'MOCKAIR', 'supported_modules' => ['flight'], 'adapter_class' => MockFlightSupplierAdapter::class]);
        TenantSupplierConfig::create(['tenant_id' => $master->id, 'supplier_id' => $s->id, 'credentials_encrypted' => ['api_key' => 'x'], 'is_active' => true, 'priority_order' => 1]);
        Sanctum::actingAs($this->createUserForTenant($master, User::ROLE_CUSTOMER), ['*']);

        $date = now()->addDays(20)->toDateString();
        $offer = $this->postJson('/api/flights/search', ['origin' => 'DEL', 'destination' => 'DXB', 'departure_date' => $date, 'adults' => 1])->assertOk()->json('data.legs.0.offers.0');
        $body = fn (array $pax) => [
            'legs' => [['supplier_code' => $offer['supplier_code'], 'external_id' => $offer['external_id'], 'segments' => $offer['segments']]],
            'travel_date' => $date,
            'passengers' => [array_merge(['first_name' => 'Asha', 'last_name' => 'Rao', 'passenger_type' => 'adult'], $pax)],
        ];

        $this->postJson('/api/flights/bookings', $body([]))->assertStatus(422)->assertJsonValidationErrors(['passengers.0.document_number', 'passengers.0.passport_expiry_date', 'passengers.0.dob']);
        $this->postJson('/api/flights/bookings', $body(['document_type' => 'passport', 'document_number' => 'Z1234567', 'dob' => '1990-01-01', 'passport_expiry_date' => now()->addDays(10)->toDateString()]))
            ->assertStatus(422)->assertJsonValidationErrors(['passengers.0.passport_expiry_date']);
        $this->postJson('/api/flights/bookings', $body(['document_type' => 'passport', 'document_number' => 'Z1234567', 'dob' => '1990-01-01', 'passport_expiry_date' => now()->addYears(3)->toDateString()]))
            ->assertCreated();
    }
}
