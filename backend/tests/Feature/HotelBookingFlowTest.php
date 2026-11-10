<?php

namespace Tests\Feature;

use App\Domains\Booking\Models\Booking;
use App\Domains\Inventory\Models\HotelInventoryItem;
use App\Domains\Suppliers\Adapters\MockHotelSupplierAdapter;
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
 * Phase 3's real Hotel booking flow: search -> hold -> confirm, against
 * MockHotelSupplierAdapter — the same end-to-end shape
 * BusBookingFlowTest covers for bus, with a stay (check-in/check-out) in
 * place of a transit leg.
 */
class HotelBookingFlowTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function seedHotelSupplier(int $tenantId): Supplier
    {
        $supplier = Supplier::create([
            'code' => 'MOCKHOTEL',
            'name' => 'Mock Hotel Supplier',
            'supported_modules' => ['hotel'],
            'adapter_class' => MockHotelSupplierAdapter::class,
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
        $this->seedHotelSupplier($master->id);

        $customer = $this->createUserForTenant($master, User::ROLE_CUSTOMER);
        $wallet = $this->walletFor($customer);
        app(WalletService::class)->credit($wallet, '50000.00', WalletLedgerEntry::REF_TOPUP);

        Sanctum::actingAs($customer, ['*']);

        $checkIn = now()->addDays(10)->toDateString();
        $checkOut = now()->addDays(13)->toDateString();

        $searchResponse = $this->postJson('/api/hotels/search', [
            'city' => 'Goa',
            'check_in_date' => $checkIn,
            'check_out_date' => $checkOut,
            'rooms' => 1,
            'guests' => 2,
        ]);

        $searchResponse->assertOk();
        $offers = $searchResponse->json('data.offers');
        $this->assertNotEmpty($offers, 'Mock hotel supplier should return at least one offer.');

        $offer = $offers[0];
        $this->assertArrayHasKey('gross_amount', $offer);
        $this->assertArrayHasKey('star_rating', $offer);
        $this->assertSame(3, $searchResponse->json('data.nights'));

        $holdResponse = $this->postJson('/api/hotels/bookings', [
            'supplier_code' => $offer['supplier_code'],
            'external_id' => $offer['external_id'],
            'segment' => $offer['segments'][0],
            'check_in_date' => $checkIn,
            'check_out_date' => $checkOut,
            'rooms' => 1,
            'guests' => [
                ['name' => 'Jane Traveller', 'age' => 29],
                ['name' => 'John Traveller', 'age' => 31],
            ],
        ]);

        $holdResponse->assertCreated();
        $bookingId = $holdResponse->json('data.id');

        $booking = Booking::withoutTenantScope()->findOrFail($bookingId);
        $this->assertSame(Booking::STATUS_HELD, $booking->status);
        $this->assertSame(Booking::MODULE_HOTEL, $booking->module);
        $this->assertFalse((bool) $booking->is_international);
        $this->assertNotNull($booking->supplier_booking_ref);
        $this->assertCount(1, $booking->segments);
        $this->assertCount(2, $booking->passengers);

        $grossAmount = $booking->gross_amount;

        $confirmResponse = $this->confirmViaWallet("/api/hotels/bookings/{$bookingId}/confirm", $bookingId);

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
        $this->seedHotelSupplier($master->id);

        $customer = $this->createUserForTenant($master, User::ROLE_CUSTOMER);
        // Deliberately no wallet top-up.

        Sanctum::actingAs($customer, ['*']);

        $checkIn = now()->addDays(10)->toDateString();
        $checkOut = now()->addDays(13)->toDateString();

        $offer = $this->postJson('/api/hotels/search', [
            'city' => 'Goa',
            'check_in_date' => $checkIn,
            'check_out_date' => $checkOut,
        ])->json('data.offers.0');

        $bookingId = $this->postJson('/api/hotels/bookings', [
            'supplier_code' => $offer['supplier_code'],
            'external_id' => $offer['external_id'],
            'segment' => $offer['segments'][0],
            'check_in_date' => $checkIn,
            'check_out_date' => $checkOut,
            'guests' => [['name' => 'Broke Traveller', 'age' => 35]],
        ])->json('data.id');

        $this->confirmViaWallet("/api/hotels/bookings/{$bookingId}/confirm", $bookingId)->assertStatus(402);

        $booking = Booking::withoutTenantScope()->findOrFail($bookingId);
        $this->assertSame(Booking::STATUS_HELD, $booking->status, 'A failed payment must not advance the booking status.');
    }

    public function test_own_inventory_offer_holds_and_decrements_capacity(): void
    {
        $master = $this->createMasterTenant();
        $this->seedHotelSupplier($master->id);

        $customer = $this->createUserForTenant($master, User::ROLE_CUSTOMER);
        $wallet = $this->walletFor($customer);
        app(WalletService::class)->credit($wallet, '50000.00', WalletLedgerEntry::REF_TOPUP);

        Sanctum::actingAs($customer, ['*']);

        $checkIn = now()->addDays(10)->toDateString();
        $checkOut = now()->addDays(13)->toDateString();

        $item = HotelInventoryItem::create([
            'tenant_id' => null,
            'title' => 'Goa Beachfront Villa',
            'city' => 'Goa',
            'hotel_name' => 'Test Resort',
            'check_in_date' => $checkIn,
            'check_out_date' => $checkOut,
            'room_type' => 'Deluxe Room',
            'star_rating' => 4,
            'meal_plan' => 'Breakfast Included',
            'is_refundable' => true,
            'capacity' => 2,
            'net_price' => '9000.00',
        ]);

        $offers = $this->postJson('/api/hotels/search', [
            'city' => 'Goa',
            'check_in_date' => $checkIn,
            'check_out_date' => $checkOut,
        ])->json('data.offers');

        $ownOffer = collect($offers)->firstWhere('supplier_code', HotelInventoryItem::SUPPLIER_CODE);
        $this->assertNotNull($ownOffer, 'Own-inventory hotel offer should appear in search results.');
        $this->assertSame('inv-'.$item->id, $ownOffer['external_id']);

        $bookingId = $this->postJson('/api/hotels/bookings', [
            'supplier_code' => $ownOffer['supplier_code'],
            'external_id' => $ownOffer['external_id'],
            'segment' => $ownOffer['segments'][0],
            'check_in_date' => $checkIn,
            'check_out_date' => $checkOut,
            'guests' => [['name' => 'Own Inventory Traveller', 'age' => 40]],
        ])->json('data.id');

        $booking = Booking::withoutTenantScope()->findOrFail($bookingId);
        $this->assertSame(Booking::STATUS_HELD, $booking->status);
        $this->assertSame(1, $item->fresh()->capacity, 'Holding one room should decrement own-inventory capacity by one.');

        $this->confirmViaWallet("/api/hotels/bookings/{$bookingId}/confirm", $bookingId)->assertOk();
        $this->assertSame(Booking::STATUS_TICKETED, $booking->fresh()->status);
    }
}
