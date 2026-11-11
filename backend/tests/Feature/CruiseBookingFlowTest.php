<?php

namespace Tests\Feature;

use App\Domains\Booking\Models\Booking;
use App\Domains\Inventory\Models\CruiseInventoryItem;
use App\Domains\Suppliers\Adapters\MockCruiseSupplierAdapter;
use App\Domains\Suppliers\Models\Supplier;
use App\Domains\Suppliers\Models\TenantSupplierConfig;
use App\Domains\Wallet\Models\WalletLedgerEntry;
use App\Domains\Wallet\Services\WalletService;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Phase 3's fifth and final real-booking-flow vertical: Cruise's real
 * search -> hold -> confirm flow, against MockCruiseSupplierAdapter — the
 * same end-to-end shape BusBookingFlowTest/HotelBookingFlowTest/
 * CabBookingFlowTest/InsuranceBookingFlowTest cover for their modules, with
 * a single point-to-point sailing (route + specific sail date) in place of
 * a transit leg, a stay, a ride or a date-agnostic policy.
 */
class CruiseBookingFlowTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function seedCruiseSupplier(int $tenantId): Supplier
    {
        $supplier = Supplier::create([
            'code' => 'MOCKCRUISE',
            'name' => 'Mock Cruise Supplier',
            'supported_modules' => ['cruise'],
            'adapter_class' => MockCruiseSupplierAdapter::class,
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

    public function test_full_search_hold_confirm_flow_debits_the_wallet_and_confirms_the_booking(): void
    {
        $master = $this->createMasterTenant();
        $this->seedCruiseSupplier($master->id);

        $customer = $this->createUserForTenant($master, User::ROLE_CUSTOMER);
        $wallet = $this->walletFor($customer);
        app(WalletService::class)->credit($wallet, '200000.00', WalletLedgerEntry::REF_TOPUP);

        Sanctum::actingAs($customer, ['*']);

        $sailDate = now()->addDays(20)->toDateString();

        $searchResponse = $this->postJson('/api/cruises/search', [
            'departure_port' => 'Chennai',
            'arrival_port' => 'Colombo',
            'sail_date' => $sailDate,
            'travellers' => 2,
        ]);

        $searchResponse->assertOk();
        $offers = $searchResponse->json('data.offers');
        $this->assertNotEmpty($offers, 'Mock cruise supplier should return at least one offer.');

        $offer = $offers[0];
        $this->assertArrayHasKey('gross_amount', $offer);
        $this->assertArrayHasKey('ship_name', $offer);
        $this->assertArrayHasKey('nights', $offer);

        $holdResponse = $this->postJson('/api/cruises/bookings', [
            'supplier_code' => $offer['supplier_code'],
            'external_id' => $offer['external_id'],
            'segment' => $offer['segments'][0],
            'sail_date' => $sailDate,
            'passengers' => [
                ['name' => 'Jane Traveller'],
                ['name' => 'John Traveller'],
            ],
        ]);

        $holdResponse->assertCreated();
        $bookingId = $holdResponse->json('data.id');

        $booking = Booking::withoutTenantScope()->findOrFail($bookingId);
        $this->assertSame(Booking::STATUS_HELD, $booking->status);
        $this->assertSame(Booking::MODULE_CRUISE, $booking->module);
        $this->assertFalse((bool) $booking->is_international);
        $this->assertNotNull($booking->supplier_booking_ref);
        $this->assertCount(1, $booking->segments);
        $this->assertCount(2, $booking->passengers);
        $this->assertNotNull($booking->metadata['cruise_line'] ?? null, 'hold() must populate cruise_line for the ticket PDF.');
        $this->assertNotNull($booking->metadata['ship_name'] ?? null, 'hold() must populate ship_name for the ticket PDF.');
        $this->assertNotNull($booking->travel_date_end, 'hold() must compute travel_date_end from the sailing nights.');

        $grossAmount = $booking->gross_amount;

        $confirmResponse = $this->confirmViaWallet("/api/cruises/bookings/{$bookingId}/confirm", $bookingId);

        $confirmResponse->assertOk()->assertJsonPath('data.status', Booking::STATUS_TICKETED);

        $booking->refresh();
        $this->assertSame(Booking::STATUS_TICKETED, $booking->status);
        $this->assertNotEmpty($booking->metadata['ticket_numbers'] ?? []);

        $expectedBalance = (string) BigDecimal::of('200000.00')->minus(BigDecimal::of((string) $grossAmount))->toScale(2);
        $this->assertSame($expectedBalance, $wallet->fresh()->balance);
    }

    public function test_confirm_fails_with_402_when_wallet_balance_is_insufficient(): void
    {
        $master = $this->createMasterTenant();
        $this->seedCruiseSupplier($master->id);

        $customer = $this->createUserForTenant($master, User::ROLE_CUSTOMER);
        // Deliberately no wallet top-up.

        Sanctum::actingAs($customer, ['*']);

        $sailDate = now()->addDays(20)->toDateString();

        $offer = $this->postJson('/api/cruises/search', [
            'departure_port' => 'Chennai',
            'arrival_port' => 'Colombo',
            'sail_date' => $sailDate,
        ])->json('data.offers.0');

        $bookingId = $this->postJson('/api/cruises/bookings', [
            'supplier_code' => $offer['supplier_code'],
            'external_id' => $offer['external_id'],
            'segment' => $offer['segments'][0],
            'sail_date' => $sailDate,
            'passengers' => [['name' => 'Broke Traveller']],
        ])->json('data.id');

        $this->confirmViaWallet("/api/cruises/bookings/{$bookingId}/confirm", $bookingId)->assertStatus(402);

        $booking = Booking::withoutTenantScope()->findOrFail($bookingId);
        $this->assertSame(Booking::STATUS_HELD, $booking->status, 'A failed payment must not advance the booking status.');
    }

    public function test_own_inventory_offer_holds_and_decrements_capacity(): void
    {
        $master = $this->createMasterTenant();
        $this->seedCruiseSupplier($master->id);

        $customer = $this->createUserForTenant($master, User::ROLE_CUSTOMER);
        $wallet = $this->walletFor($customer);
        app(WalletService::class)->credit($wallet, '200000.00', WalletLedgerEntry::REF_TOPUP);

        Sanctum::actingAs($customer, ['*']);

        $sailDate = now()->addDays(20)->toDateString();

        $item = CruiseInventoryItem::create([
            'tenant_id' => null,
            'title' => 'Chennai-Colombo Getaway',
            'cruise_line' => 'Test Cruise Line',
            'ship_name' => 'Test Voyager',
            'departure_port' => 'Chennai',
            'arrival_port' => 'Colombo',
            'departure_date' => $sailDate,
            'nights' => 4,
            'cabin_type' => 'Ocean View',
            'is_refundable' => true,
            'capacity' => 2,
            'net_price' => '18000.00',
        ]);

        $offers = $this->postJson('/api/cruises/search', [
            'departure_port' => 'Chennai',
            'arrival_port' => 'Colombo',
            'sail_date' => $sailDate,
        ])->json('data.offers');

        $ownOffer = collect($offers)->firstWhere('supplier_code', CruiseInventoryItem::SUPPLIER_CODE);
        $this->assertNotNull($ownOffer, 'Own-inventory cruise offer should appear in search results.');
        $this->assertSame('inv-'.$item->id, $ownOffer['external_id']);

        $bookingId = $this->postJson('/api/cruises/bookings', [
            'supplier_code' => $ownOffer['supplier_code'],
            'external_id' => $ownOffer['external_id'],
            'segment' => $ownOffer['segments'][0],
            'sail_date' => $sailDate,
            'passengers' => [['name' => 'Own Inventory Traveller']],
        ])->json('data.id');

        $booking = Booking::withoutTenantScope()->findOrFail($bookingId);
        $this->assertSame(Booking::STATUS_HELD, $booking->status);
        $this->assertSame(1, $item->fresh()->capacity, 'Holding one cabin should decrement own-inventory capacity by one.');

        $this->confirmViaWallet("/api/cruises/bookings/{$bookingId}/confirm", $bookingId)->assertOk();
        $this->assertSame(Booking::STATUS_TICKETED, $booking->fresh()->status);
    }
}
