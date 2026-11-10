<?php

namespace Tests\Feature;

use App\Domains\Booking\Models\Booking;
use App\Domains\Inventory\Models\InsuranceInventoryItem;
use App\Domains\Suppliers\Adapters\MockInsuranceSupplierAdapter;
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
 * Phase 3's real Insurance booking flow: search -> hold -> confirm,
 * against MockInsuranceSupplierAdapter — the same end-to-end shape
 * BusBookingFlowTest/HotelBookingFlowTest/CabBookingFlowTest cover for
 * their modules, with a policy purchase over the customer's own trip
 * window in place of a transit leg, a stay or a ride.
 */
class InsuranceBookingFlowTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function seedInsuranceSupplier(int $tenantId): Supplier
    {
        $supplier = Supplier::create([
            'code' => 'MOCKINSURANCE',
            'name' => 'Mock Insurance Supplier',
            'supported_modules' => ['insurance'],
            'adapter_class' => MockInsuranceSupplierAdapter::class,
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
        $this->seedInsuranceSupplier($master->id);

        $customer = $this->createUserForTenant($master, User::ROLE_CUSTOMER);
        $wallet = $this->walletFor($customer);
        app(WalletService::class)->credit($wallet, '50000.00', WalletLedgerEntry::REF_TOPUP);

        Sanctum::actingAs($customer, ['*']);

        $tripStart = now()->addDays(10)->toDateString();
        $tripEnd = now()->addDays(17)->toDateString();

        $searchResponse = $this->postJson('/api/insurance/search', [
            'destination' => 'Thailand',
            'trip_start_date' => $tripStart,
            'trip_end_date' => $tripEnd,
            'travellers' => 2,
        ]);

        $searchResponse->assertOk();
        $offers = $searchResponse->json('data.offers');
        $this->assertNotEmpty($offers, 'Mock insurance supplier should return at least one offer.');
        $this->assertSame(8, $searchResponse->json('data.trip_days'));

        $offer = $offers[0];
        $this->assertArrayHasKey('gross_amount', $offer);
        $this->assertArrayHasKey('coverage_amount', $offer);
        $this->assertArrayHasKey('policy_term_days', $offer);

        $holdResponse = $this->postJson('/api/insurance/bookings', [
            'supplier_code' => $offer['supplier_code'],
            'external_id' => $offer['external_id'],
            'segment' => $offer['segments'][0],
            'destination' => 'Thailand',
            'trip_start_date' => $tripStart,
            'trip_end_date' => $tripEnd,
            'passengers' => [
                ['name' => 'Jane Traveller'],
                ['name' => 'John Traveller'],
            ],
        ]);

        $holdResponse->assertCreated();
        $bookingId = $holdResponse->json('data.id');

        $booking = Booking::withoutTenantScope()->findOrFail($bookingId);
        $this->assertSame(Booking::STATUS_HELD, $booking->status);
        $this->assertSame(Booking::MODULE_INSURANCE, $booking->module);
        $this->assertFalse((bool) $booking->is_international);
        $this->assertNotNull($booking->supplier_booking_ref);
        $this->assertCount(1, $booking->segments);
        $this->assertCount(2, $booking->passengers);
        $this->assertNotNull($booking->metadata['provider_name'] ?? null, 'hold() must populate provider_name for the policy PDF.');
        $this->assertNotNull($booking->metadata['coverage_amount'] ?? null, 'hold() must populate coverage_amount for the policy PDF.');

        $grossAmount = $booking->gross_amount;

        $confirmResponse = $this->postJson("/api/insurance/bookings/{$bookingId}/confirm");

        $confirmResponse->assertOk()->assertJsonPath('status', Booking::STATUS_TICKETED);

        $booking->refresh();
        $this->assertSame(Booking::STATUS_TICKETED, $booking->status);
        $this->assertNotEmpty($booking->metadata['ticket_numbers'] ?? []);

        $expectedBalance = (string) BigDecimal::of('50000.00')->minus(BigDecimal::of((string) $grossAmount))->toScale(2);
        $this->assertSame($expectedBalance, $wallet->fresh()->balance);
    }

    public function test_confirm_fails_with_402_when_wallet_balance_is_insufficient(): void
    {
        $master = $this->createMasterTenant();
        $this->seedInsuranceSupplier($master->id);

        $customer = $this->createUserForTenant($master, User::ROLE_CUSTOMER);
        // Deliberately no wallet top-up.

        Sanctum::actingAs($customer, ['*']);

        $tripStart = now()->addDays(10)->toDateString();
        $tripEnd = now()->addDays(17)->toDateString();

        $offer = $this->postJson('/api/insurance/search', [
            'destination' => 'Thailand',
            'trip_start_date' => $tripStart,
            'trip_end_date' => $tripEnd,
        ])->json('data.offers.0');

        $bookingId = $this->postJson('/api/insurance/bookings', [
            'supplier_code' => $offer['supplier_code'],
            'external_id' => $offer['external_id'],
            'segment' => $offer['segments'][0],
            'destination' => 'Thailand',
            'trip_start_date' => $tripStart,
            'trip_end_date' => $tripEnd,
            'passengers' => [['name' => 'Broke Traveller']],
        ])->json('data.id');

        $this->postJson("/api/insurance/bookings/{$bookingId}/confirm")->assertStatus(402);

        $booking = Booking::withoutTenantScope()->findOrFail($bookingId);
        $this->assertSame(Booking::STATUS_HELD, $booking->status, 'A failed payment must not advance the booking status.');
    }

    public function test_own_inventory_offer_holds_and_decrements_capacity(): void
    {
        $master = $this->createMasterTenant();
        $this->seedInsuranceSupplier($master->id);

        $customer = $this->createUserForTenant($master, User::ROLE_CUSTOMER);
        $wallet = $this->walletFor($customer);
        app(WalletService::class)->credit($wallet, '50000.00', WalletLedgerEntry::REF_TOPUP);

        Sanctum::actingAs($customer, ['*']);

        $tripStart = now()->addDays(10)->toDateString();
        $tripEnd = now()->addDays(17)->toDateString();

        $item = InsuranceInventoryItem::create([
            'tenant_id' => null,
            'title' => 'Thailand Travel Cover',
            'plan_name' => 'Standard',
            'provider_name' => 'Test Insurance Co.',
            'coverage_amount' => '1500000.00',
            'policy_term_days' => 15,
            'is_refundable' => true,
            'capacity' => 2,
            'net_price' => '1200.00',
        ]);

        $offers = $this->postJson('/api/insurance/search', [
            'destination' => 'Thailand',
            'trip_start_date' => $tripStart,
            'trip_end_date' => $tripEnd,
        ])->json('data.offers');

        $ownOffer = collect($offers)->firstWhere('supplier_code', InsuranceInventoryItem::SUPPLIER_CODE);
        $this->assertNotNull($ownOffer, 'Own-inventory insurance offer should appear in search results.');
        $this->assertSame('inv-'.$item->id, $ownOffer['external_id']);

        $bookingId = $this->postJson('/api/insurance/bookings', [
            'supplier_code' => $ownOffer['supplier_code'],
            'external_id' => $ownOffer['external_id'],
            'segment' => $ownOffer['segments'][0],
            'destination' => 'Thailand',
            'trip_start_date' => $tripStart,
            'trip_end_date' => $tripEnd,
            'passengers' => [['name' => 'Own Inventory Traveller']],
        ])->json('data.id');

        $booking = Booking::withoutTenantScope()->findOrFail($bookingId);
        $this->assertSame(Booking::STATUS_HELD, $booking->status);
        $this->assertSame(1, $item->fresh()->capacity, 'Holding one policy should decrement own-inventory capacity by one.');

        $this->postJson("/api/insurance/bookings/{$bookingId}/confirm")->assertOk();
        $this->assertSame(Booking::STATUS_TICKETED, $booking->fresh()->status);
    }
}
