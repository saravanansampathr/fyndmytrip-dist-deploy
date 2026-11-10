<?php

namespace Tests\Feature;

use App\Domains\Booking\Models\Booking;
use App\Domains\Suppliers\Adapters\MockActivitySupplierAdapter;
use App\Domains\Suppliers\Adapters\MockCabSupplierAdapter;
use App\Domains\Suppliers\Adapters\MockFlightSupplierAdapter;
use App\Domains\Suppliers\Adapters\MockHotelSupplierAdapter;
use App\Domains\Suppliers\Models\Supplier;
use App\Domains\Suppliers\Models\TenantSupplierConfig;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * The price a customer is charged at checkout must be the price the results
 * page showed them, for the whole party: every room, every passenger, every
 * ticket.
 */
class CheckoutPriceConsistencyTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function supplier(int $tenantId, string $code, string $module, string $adapter): void
    {
        $supplier = Supplier::create(['code' => $code, 'name' => $code, 'supported_modules' => [$module], 'adapter_class' => $adapter]);
        TenantSupplierConfig::create(['tenant_id' => $tenantId, 'supplier_id' => $supplier->id, 'credentials_encrypted' => ['api_key' => 'test'], 'is_active' => true, 'priority_order' => 1]);
    }

    protected function customer(): User
    {
        $master = $this->createMasterTenant();
        $this->supplier($master->id, 'MOCKAIR', 'flight', MockFlightSupplierAdapter::class);
        $this->supplier($master->id, 'MOCKHOTEL', 'hotel', MockHotelSupplierAdapter::class);
        $this->supplier($master->id, 'MOCKACTIVITY', 'activity', MockActivitySupplierAdapter::class);
        $this->supplier($master->id, 'MOCKCAB', 'cab', MockCabSupplierAdapter::class);
        $user = $this->createUserForTenant($master, User::ROLE_CUSTOMER);
        Sanctum::actingAs($user, ['*']);

        return $user;
    }

    protected function total(int|string $bookingId): string
    {
        return (string) Booking::withoutTenantScope()->findOrFail($bookingId)->recalculateTotal();
    }

    public function test_hotel_charges_the_shown_price_for_every_room(): void
    {
        $this->customer();
        $in = now()->addDays(10)->toDateString();
        $out = now()->addDays(12)->toDateString();

        $one = $this->postJson('/api/hotels/search', ['city' => 'Goa', 'check_in_date' => $in, 'check_out_date' => $out, 'rooms' => 1, 'guests' => 2])->json('data.offers.0');
        $three = $this->postJson('/api/hotels/search', ['city' => 'Goa', 'check_in_date' => $in, 'check_out_date' => $out, 'rooms' => 3, 'guests' => 6])->json('data.offers.0');
        $this->assertEqualsWithDelta((float) $one['gross_amount'] * 3, (float) $three['gross_amount'], 0.05);

        $guests = array_map(fn ($i) => ['name' => "Guest {$i}", 'age' => 30], range(1, 6));
        $hold = $this->postJson('/api/hotels/bookings', [
            'supplier_code' => $three['supplier_code'], 'external_id' => $three['external_id'], 'segment' => $three['segments'][0],
            'check_in_date' => $in, 'check_out_date' => $out, 'rooms' => 3, 'guests' => $guests,
        ])->assertCreated();

        $this->assertEqualsWithDelta((float) $three['gross_amount'], (float) $this->total($hold->json('data.id')), 0.01);
    }

    public function test_flight_charges_the_per_adult_fare_for_every_passenger(): void
    {
        $this->customer();
        $date = now()->addDays(10)->toDateString();
        $offer = $this->postJson('/api/flights/search', ['origin' => 'DEL', 'destination' => 'BOM', 'departure_date' => $date, 'adults' => 2, 'infants' => 1])->json('data.legs.0.offers.0');

        $hold = $this->postJson('/api/flights/bookings', [
            'legs' => [['supplier_code' => $offer['supplier_code'], 'external_id' => $offer['external_id'], 'segments' => $offer['segments']]],
            'travel_date' => $date,
            'passengers' => [
                ['first_name' => 'Asha', 'last_name' => 'Rao', 'passenger_type' => 'adult'],
                ['first_name' => 'Ravi', 'last_name' => 'Rao', 'passenger_type' => 'adult'],
                ['first_name' => 'Tara', 'last_name' => 'Rao', 'passenger_type' => 'infant'],
            ],
        ])->assertCreated();

        // No margin rules exist, so the per-adult fare is the net fare.
        $this->assertEqualsWithDelta((float) $offer['gross_amount'] * 2.1, (float) $this->total($hold->json('data.id')), 0.05);
    }

    public function test_activity_charges_the_shown_total_for_every_traveller(): void
    {
        $this->customer();
        $date = now()->addDays(8)->toDateString();
        $one = $this->postJson('/api/activities/search', ['city' => 'Goa', 'activity_date' => $date, 'travellers' => 1])->json('data.offers.0');
        $offer = $this->postJson('/api/activities/search', ['city' => 'Goa', 'activity_date' => $date, 'travellers' => 3])->json('data.offers.0');
        $this->assertEqualsWithDelta((float) $one['gross_amount'] * 3, (float) $offer['gross_amount'], 0.05);

        $hold = $this->postJson('/api/activities/bookings', [
            'supplier_code' => $offer['supplier_code'], 'external_id' => $offer['external_id'], 'segment' => $offer['segments'][0],
            'activity_date' => $date, 'passengers' => [['name' => 'A'], ['name' => 'B'], ['name' => 'C']],
        ])->assertCreated();

        $this->assertEqualsWithDelta((float) $offer['gross_amount'], (float) $this->total($hold->json('data.id')), 0.01);
    }

    public function test_cab_checkout_price_matches_the_results_page(): void
    {
        $this->customer();
        $date = now()->addDays(6)->toDateString();
        $offer = $this->postJson('/api/cabs/search', ['origin' => 'Chennai', 'destination' => 'Pondicherry', 'travel_date' => $date, 'passengers' => 2])->json('data.offers.0');

        $hold = $this->postJson('/api/cabs/bookings', [
            'supplier_code' => $offer['supplier_code'], 'external_id' => $offer['external_id'], 'segment' => $offer['segments'][0],
            'travel_date' => $date, 'passengers' => [['name' => 'A', 'age' => 30], ['name' => 'B', 'age' => 31]],
        ])->assertCreated();

        $this->assertEqualsWithDelta((float) $offer['gross_amount'], (float) $this->total($hold->json('data.id')), 0.01);
    }
}
