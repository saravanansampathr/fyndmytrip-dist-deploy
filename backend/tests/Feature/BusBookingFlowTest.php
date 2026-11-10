<?php

namespace Tests\Feature;

use App\Domains\Booking\Models\Booking;
use App\Domains\Inventory\Models\BusInventoryItem;
use App\Domains\Suppliers\Adapters\MockBusSupplierAdapter;
use App\Domains\Suppliers\Models\Supplier;
use App\Domains\Suppliers\Models\TenantSupplierConfig;
use App\Domains\Wallet\Models\WalletLedgerEntry;
use App\Domains\Wallet\Services\WalletService;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Phase 3's real Bus booking flow: search -> seatmap -> hold -> confirm,
 * against MockBusSupplierAdapter — the same end-to-end shape
 * FlightBookingFlowTest covers for flights, minus the multi-leg concept a
 * bus route doesn't have. Exercises the seat-picking/boarding-dropping/
 * passenger-title flow added on top of the original search->hold->confirm
 * shape (see BusSeatMapService, BusBookingController::seatMap()/hold()).
 */
class BusBookingFlowTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function seedBusSupplier(int $tenantId): Supplier
    {
        $supplier = Supplier::create([
            'code' => 'MOCKBUS',
            'name' => 'Mock Bus Supplier',
            'supported_modules' => ['bus'],
            'adapter_class' => MockBusSupplierAdapter::class,
        ]);

        TenantSupplierConfig::create([
            'tenant_id' => $tenantId,
            'supplier_id' => $supplier->id,
            'credentials_encrypted' => ['api_key' => 'test'],
            'is_active' => true,
            'priority_order' => 1,
        ]);

        return $supplier;
    }

    /**
     * Fetches the real seat layout for an offer (exactly like
     * BusSeatLayoutGrid does before hold()) and returns the seat numbers of
     * the first N seats that are actually open to book — mirrors a
     * customer clicking N seats on the seat-picker rather than guessing a
     * seat number that might not exist for this bus_type's layout, or
     * might already be one of the deterministic "other passenger" seats.
     *
     * @return array<int, string>
     */
    protected function pickOpenSeats(array $offer, string $departureDate, int $count = 1): array
    {
        $map = $this->postJson('/api/bus/seatmap', [
            'supplier_code' => $offer['supplier_code'],
            'external_id' => $offer['external_id'],
            'travel_date' => $departureDate,
            'bus_type' => $offer['bus_type'],
            'seats_available' => $offer['seats_available'],
        ])->assertOk()->json('data');

        $open = [];
        foreach ($map['rows'] as $row) {
            foreach ($row['seats'] as $seat) {
                // Only fully open seats: several callers hold with a male
                // passenger, which the hold correctly rejects on a
                // female_only seat — that made these tests flaky.
                if ($seat['state'] === 'available') {
                    $open[] = $seat;
                }
                if (count($open) >= $count) {
                    break 2;
                }
            }
        }

        $this->assertGreaterThanOrEqual($count, count($open), 'Seat map should have enough open seats for this test.');

        return array_map(fn ($s) => $s['seat_number'], $open);
    }

    protected function holdBus(array $offer, string $departureDate, array $passengers, array $extra = []): TestResponse
    {
        return $this->postJson('/api/bus/bookings', array_merge([
            'supplier_code' => $offer['supplier_code'],
            'external_id' => $offer['external_id'],
            'segment' => $offer['segments'][0],
            'travel_date' => $departureDate,
            'bus_type' => $offer['bus_type'],
            'seats_available' => $offer['seats_available'],
            'boarding_point' => $offer['boarding_points'][0]['point'] ?? $offer['boarding_point'],
            'dropping_point' => $offer['dropping_points'][0]['point'] ?? $offer['dropping_point'],
            'passengers' => $passengers,
        ], $extra));
    }

    public function test_full_search_hold_confirm_flow_debits_the_wallet_and_tickets_the_booking(): void
    {
        $master = $this->createMasterTenant();
        $this->seedBusSupplier($master->id);

        $customer = $this->createUserForTenant($master, User::ROLE_CUSTOMER);
        $wallet = $this->walletFor($customer);
        app(WalletService::class)->credit($wallet, '5000.00', WalletLedgerEntry::REF_TOPUP);

        Sanctum::actingAs($customer, ['*']);

        $departureDate = now()->addDays(5)->toDateString();

        $searchResponse = $this->postJson('/api/bus/search', [
            'origin' => 'Chennai',
            'destination' => 'Bengaluru',
            'departure_date' => $departureDate,
            // No passengers field sent — search no longer captures a
            // passenger count up front (BusSearchPage dropped that field).
        ]);

        $searchResponse->assertOk();
        $offers = $searchResponse->json('data.offers');
        $this->assertNotEmpty($offers, 'Mock bus supplier should return at least one offer.');

        $offer = $offers[0];
        $this->assertArrayHasKey('gross_amount', $offer);
        $this->assertArrayHasKey('bus_type', $offer);
        $this->assertArrayHasKey('boarding_points', $offer);
        $this->assertArrayHasKey('dropping_points', $offer);
        $this->assertNotEmpty($offer['boarding_points'], 'MockBusSupplierAdapter should populate a multi-stop boarding_points list.');

        [$seatNumber] = $this->pickOpenSeats($offer, $departureDate, 1);
        $chosenBoardingPoint = $offer['boarding_points'][count($offer['boarding_points']) - 1]['point'];

        $holdResponse = $this->holdBus($offer, $departureDate, [
            ['name' => 'Jane Traveller', 'seat_number' => $seatNumber, 'gender' => 'female', 'title' => 'Ms', 'age' => 29],
        ], ['boarding_point' => $chosenBoardingPoint]);

        $holdResponse->assertCreated();
        $bookingId = $holdResponse->json('data.id');

        $booking = Booking::withoutTenantScope()->findOrFail($bookingId);
        $this->assertSame(Booking::STATUS_HELD, $booking->status);
        $this->assertSame(Booking::MODULE_BUS, $booking->module);
        $this->assertFalse((bool) $booking->is_international);
        $this->assertNotNull($booking->supplier_booking_ref);
        $this->assertCount(1, $booking->segments);
        $this->assertSame('Ms', $booking->passengers->first()->title);
        $this->assertSame($seatNumber, $booking->passengers->first()->seat_number);
        // The chosen boarding point (not just the offer's own default) is
        // what should have been persisted onto the booking and the segment.
        $this->assertSame($chosenBoardingPoint, $booking->metadata['boarding_point'] ?? null);
        $this->assertSame($chosenBoardingPoint, $booking->segments->first()->raw_supplier_payload['boarding_point'] ?? null);

        $grossAmount = $booking->gross_amount;

        $confirmResponse = $this->confirmViaWallet("/api/bus/bookings/{$bookingId}/confirm", $bookingId);

        $confirmResponse->assertOk()->assertJsonPath('data.status', Booking::STATUS_TICKETED);

        $booking->refresh();
        $this->assertSame(Booking::STATUS_TICKETED, $booking->status);
        $this->assertNotEmpty($booking->metadata['ticket_numbers'] ?? []);

        $expectedBalance = (string) BigDecimal::of('5000.00')->minus(BigDecimal::of((string) $grossAmount))->toScale(2);
        $this->assertSame($expectedBalance, $wallet->fresh()->balance);
    }

    public function test_hold_rejects_more_than_six_passengers(): void
    {
        $master = $this->createMasterTenant();
        $this->seedBusSupplier($master->id);

        $customer = $this->createUserForTenant($master, User::ROLE_CUSTOMER);
        Sanctum::actingAs($customer, ['*']);

        $departureDate = now()->addDays(5)->toDateString();

        $offer = $this->postJson('/api/bus/search', [
            'origin' => 'Chennai',
            'destination' => 'Bengaluru',
            'departure_date' => $departureDate,
        ])->json('data.offers.0');

        // Field-level validation ('max:6') rejects this before any seat is
        // actually looked up, so the seat numbers below don't need to be
        // real/open ones — just distinct, well-formed strings.
        $passengers = array_map(
            fn ($i) => ['name' => "Traveller {$i}", 'seat_number' => "S{$i}", 'gender' => 'male', 'title' => 'Mr', 'age' => 30],
            range(1, 7),
        );

        // A bus booking can hold at most 6 seats in one go — mirrors
        // BusSeatLayoutGrid's own cap on the frontend.
        $this->holdBus($offer, $departureDate, $passengers)->assertStatus(422);
    }

    public function test_hold_rejects_a_male_titled_passenger_on_a_female_only_seat(): void
    {
        $master = $this->createMasterTenant();
        $this->seedBusSupplier($master->id);

        $customer = $this->createUserForTenant($master, User::ROLE_CUSTOMER);
        Sanctum::actingAs($customer, ['*']);

        $departureDate = now()->addDays(5)->toDateString();

        $offer = $this->postJson('/api/bus/search', [
            'origin' => 'Chennai',
            'destination' => 'Bengaluru',
            'departure_date' => $departureDate,
        ])->json('data.offers.0');

        $map = $this->postJson('/api/bus/seatmap', [
            'supplier_code' => $offer['supplier_code'],
            'external_id' => $offer['external_id'],
            'travel_date' => $departureDate,
            'bus_type' => $offer['bus_type'],
            'seats_available' => $offer['seats_available'],
        ])->json('data');

        $femaleOnlySeat = null;
        foreach ($map['rows'] as $row) {
            foreach ($row['seats'] as $seat) {
                if ($seat['state'] === 'female_only') {
                    $femaleOnlySeat = $seat['seat_number'];
                    break 2;
                }
            }
        }

        if ($femaleOnlySeat === null) {
            $this->markTestSkipped('This deterministic seed produced no female_only seat to exercise the rule against.');
        }

        $this->holdBus($offer, $departureDate, [
            ['name' => 'Should Fail', 'seat_number' => $femaleOnlySeat, 'gender' => 'male', 'title' => 'Mr', 'age' => 40],
        ])->assertStatus(409);
    }

    public function test_confirm_fails_with_402_when_wallet_balance_is_insufficient(): void
    {
        $master = $this->createMasterTenant();
        $this->seedBusSupplier($master->id);

        $customer = $this->createUserForTenant($master, User::ROLE_CUSTOMER);
        // Deliberately no wallet top-up.

        Sanctum::actingAs($customer, ['*']);

        $departureDate = now()->addDays(5)->toDateString();

        $offer = $this->postJson('/api/bus/search', [
            'origin' => 'Chennai',
            'destination' => 'Bengaluru',
            'departure_date' => $departureDate,
        ])->json('data.offers.0');

        [$seatNumber] = $this->pickOpenSeats($offer, $departureDate, 1);

        $bookingId = $this->holdBus($offer, $departureDate, [
            ['name' => 'Broke Traveller', 'seat_number' => $seatNumber, 'gender' => 'male', 'title' => 'Mr', 'age' => 35],
        ])->json('data.id');

        $this->confirmViaWallet("/api/bus/bookings/{$bookingId}/confirm", $bookingId)->assertStatus(402);

        $booking = Booking::withoutTenantScope()->findOrFail($bookingId);
        $this->assertSame(Booking::STATUS_HELD, $booking->status, 'A failed payment must not advance the booking status.');
    }

    public function test_own_inventory_offer_holds_and_decrements_capacity(): void
    {
        $master = $this->createMasterTenant();
        $this->seedBusSupplier($master->id);

        $customer = $this->createUserForTenant($master, User::ROLE_CUSTOMER);
        $wallet = $this->walletFor($customer);
        app(WalletService::class)->credit($wallet, '5000.00', WalletLedgerEntry::REF_TOPUP);

        Sanctum::actingAs($customer, ['*']);

        $departureDate = now()->addDays(5)->toDateString();

        $item = BusInventoryItem::create([
            'tenant_id' => null,
            'title' => 'Chennai-Bengaluru AC Sleeper',
            'origin' => 'Chennai',
            'destination' => 'Bengaluru',
            'travel_date' => $departureDate,
            'departure_time' => '22:00',
            'operator_name' => 'Test Operator',
            'bus_type' => 'AC Sleeper',
            'boarding_point' => 'Central Bus Stand',
            'dropping_point' => 'Main Bus Depot',
            'duration_minutes' => 360,
            'is_refundable' => true,
            'capacity' => 2,
            'net_price' => '900.00',
        ]);

        $offers = $this->postJson('/api/bus/search', [
            'origin' => 'Chennai',
            'destination' => 'Bengaluru',
            'departure_date' => $departureDate,
        ])->json('data.offers');

        $ownOffer = collect($offers)->firstWhere('supplier_code', BusInventoryItem::SUPPLIER_CODE);
        $this->assertNotNull($ownOffer, 'Own-inventory bus offer should appear in search results.');
        $this->assertSame('inv-'.$item->id, $ownOffer['external_id']);
        $this->assertSame(['Central Bus Stand'], array_column($ownOffer['boarding_points'], 'point'));

        [$seatNumber] = $this->pickOpenSeats($ownOffer, $departureDate, 1);

        $bookingId = $this->holdBus($ownOffer, $departureDate, [
            ['name' => 'Own Inventory Traveller', 'seat_number' => $seatNumber, 'gender' => 'male', 'title' => 'Mr', 'age' => 45],
        ])->json('data.id');

        $booking = Booking::withoutTenantScope()->findOrFail($bookingId);
        $this->assertSame(Booking::STATUS_HELD, $booking->status);
        $this->assertSame(1, $item->fresh()->capacity, 'Holding one seat should decrement own-inventory capacity by one.');

        $this->confirmViaWallet("/api/bus/bookings/{$bookingId}/confirm", $bookingId)->assertOk();
        $this->assertSame(Booking::STATUS_TICKETED, $booking->fresh()->status);
    }
}
