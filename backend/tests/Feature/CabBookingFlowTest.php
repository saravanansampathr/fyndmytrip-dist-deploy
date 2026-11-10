<?php

namespace Tests\Feature;

use App\Domains\Booking\Models\Booking;
use App\Domains\Inventory\Models\CabInventoryItem;
use App\Domains\Suppliers\Adapters\MockCabSupplierAdapter;
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
 * Phase 3's real Cab booking flow: search -> hold -> confirm, against
 * MockCabSupplierAdapter — the same end-to-end shape BusBookingFlowTest/
 * HotelBookingFlowTest cover for their modules, with a point-to-point ride
 * in place of a transit leg or a stay.
 */
class CabBookingFlowTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function seedCabSupplier(int $tenantId): Supplier
    {
        $supplier = Supplier::create([
            'code' => 'MOCKCAB',
            'name' => 'Mock Cab Supplier',
            'supported_modules' => ['cab'],
            'adapter_class' => MockCabSupplierAdapter::class,
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
        $this->seedCabSupplier($master->id);

        $customer = $this->createUserForTenant($master, User::ROLE_CUSTOMER);
        $wallet = $this->walletFor($customer);
        app(WalletService::class)->credit($wallet, '50000.00', WalletLedgerEntry::REF_TOPUP);

        Sanctum::actingAs($customer, ['*']);

        $travelDate = now()->addDays(5)->toDateString();

        $searchResponse = $this->postJson('/api/cabs/search', [
            'origin' => 'Chennai',
            'destination' => 'Pondicherry',
            'travel_date' => $travelDate,
            'passengers' => 2,
        ]);

        $searchResponse->assertOk();
        $offers = $searchResponse->json('data.offers');
        $this->assertNotEmpty($offers, 'Mock cab supplier should return at least one offer.');

        $offer = $offers[0];
        $this->assertArrayHasKey('gross_amount', $offer);
        $this->assertArrayHasKey('car_type', $offer);
        $this->assertArrayHasKey('included_km', $offer);

        $holdResponse = $this->postJson('/api/cabs/bookings', [
            'supplier_code' => $offer['supplier_code'],
            'external_id' => $offer['external_id'],
            'segment' => $offer['segments'][0],
            'travel_date' => $travelDate,
            'passengers' => [
                ['name' => 'Jane Traveller', 'age' => 29],
                ['name' => 'John Traveller', 'age' => 31],
            ],
        ]);

        $holdResponse->assertCreated();
        $bookingId = $holdResponse->json('data.id');

        $booking = Booking::withoutTenantScope()->findOrFail($bookingId);
        $this->assertSame(Booking::STATUS_HELD, $booking->status);
        $this->assertSame(Booking::MODULE_CAB, $booking->module);
        $this->assertFalse((bool) $booking->is_international);
        $this->assertNotNull($booking->supplier_booking_ref);
        $this->assertCount(1, $booking->segments);
        $this->assertCount(2, $booking->passengers);
        $this->assertNotNull($booking->metadata['provider_name'] ?? null, 'hold() must populate provider_name for the voucher PDF.');
        $this->assertNotNull($booking->metadata['car_type'] ?? null, 'hold() must populate car_type for the voucher PDF.');

        $grossAmount = $booking->gross_amount;

        $confirmResponse = $this->confirmViaWallet("/api/cabs/bookings/{$bookingId}/confirm", $bookingId);

        $confirmResponse->assertOk()->assertJsonPath('data.status', Booking::STATUS_TICKETED);

        $booking->refresh();
        $this->assertSame(Booking::STATUS_TICKETED, $booking->status);
        $this->assertNotEmpty($booking->metadata['ticket_numbers'] ?? []);

        $expectedBalance = (string) BigDecimal::of('50000.00')->minus(BigDecimal::of((string) $grossAmount))->toScale(2);
        $this->assertSame($expectedBalance, $wallet->fresh()->balance);
    }

    public function test_confirm_fails_with_402_when_wallet_balance_is_insufficient(): void
    {
        $master = $this->createMasterTenant();
        $this->seedCabSupplier($master->id);

        $customer = $this->createUserForTenant($master, User::ROLE_CUSTOMER);
        // Deliberately no wallet top-up.

        Sanctum::actingAs($customer, ['*']);

        $travelDate = now()->addDays(5)->toDateString();

        $offer = $this->postJson('/api/cabs/search', [
            'origin' => 'Chennai',
            'destination' => 'Pondicherry',
            'travel_date' => $travelDate,
        ])->json('data.offers.0');

        $bookingId = $this->postJson('/api/cabs/bookings', [
            'supplier_code' => $offer['supplier_code'],
            'external_id' => $offer['external_id'],
            'segment' => $offer['segments'][0],
            'travel_date' => $travelDate,
            'passengers' => [['name' => 'Broke Traveller', 'age' => 35]],
        ])->json('data.id');

        $this->confirmViaWallet("/api/cabs/bookings/{$bookingId}/confirm", $bookingId)->assertStatus(402);

        $booking = Booking::withoutTenantScope()->findOrFail($bookingId);
        $this->assertSame(Booking::STATUS_HELD, $booking->status, 'A failed payment must not advance the booking status.');
    }

    public function test_own_inventory_offer_holds_and_decrements_capacity(): void
    {
        $master = $this->createMasterTenant();
        $this->seedCabSupplier($master->id);

        $customer = $this->createUserForTenant($master, User::ROLE_CUSTOMER);
        $wallet = $this->walletFor($customer);
        app(WalletService::class)->credit($wallet, '50000.00', WalletLedgerEntry::REF_TOPUP);

        Sanctum::actingAs($customer, ['*']);

        $travelDate = now()->addDays(5)->toDateString();

        $item = CabInventoryItem::create([
            'tenant_id' => null,
            'title' => 'Chennai-Pondicherry Sedan',
            'origin' => 'Chennai',
            'destination' => 'Pondicherry',
            'travel_date' => $travelDate,
            'pickup_time' => '08:00',
            'provider_name' => 'Test Cabs Co.',
            'car_type' => 'Sedan',
            'included_km' => 150,
            'extra_km_fare' => '13.00',
            'duration_minutes' => 180,
            'is_refundable' => true,
            'capacity' => 2,
            'net_price' => '2500.00',
        ]);

        $offers = $this->postJson('/api/cabs/search', [
            'origin' => 'Chennai',
            'destination' => 'Pondicherry',
            'travel_date' => $travelDate,
        ])->json('data.offers');

        $ownOffer = collect($offers)->firstWhere('supplier_code', CabInventoryItem::SUPPLIER_CODE);
        $this->assertNotNull($ownOffer, 'Own-inventory cab offer should appear in search results.');
        $this->assertSame('inv-'.$item->id, $ownOffer['external_id']);

        $bookingId = $this->postJson('/api/cabs/bookings', [
            'supplier_code' => $ownOffer['supplier_code'],
            'external_id' => $ownOffer['external_id'],
            'segment' => $ownOffer['segments'][0],
            'travel_date' => $travelDate,
            'passengers' => [['name' => 'Own Inventory Traveller', 'age' => 40]],
        ])->json('data.id');

        $booking = Booking::withoutTenantScope()->findOrFail($bookingId);
        $this->assertSame(Booking::STATUS_HELD, $booking->status);
        $this->assertSame(1, $item->fresh()->capacity, 'Holding one cab should decrement own-inventory capacity by one.');

        $this->confirmViaWallet("/api/cabs/bookings/{$bookingId}/confirm", $bookingId)->assertOk();
        $this->assertSame(Booking::STATUS_TICKETED, $booking->fresh()->status);
    }
}
