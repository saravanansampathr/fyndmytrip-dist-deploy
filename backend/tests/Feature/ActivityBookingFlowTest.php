<?php

namespace Tests\Feature;

use App\Domains\Booking\Models\Booking;
use App\Domains\Inventory\Models\ActivityInventoryItem;
use App\Domains\Suppliers\Adapters\MockActivitySupplierAdapter;
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
 * Phase 3's sixth and final item: Activities' real search -> hold ->
 * confirm flow, against MockActivitySupplierAdapter — the same end-to-end
 * shape CabBookingFlowTest/CruiseBookingFlowTest cover for their modules,
 * with a single-city/single-date activity slot in place of a transit leg,
 * a stay, a ride, a policy or a sailing.
 */
class ActivityBookingFlowTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function seedActivitySupplier(int $tenantId): Supplier
    {
        $supplier = Supplier::create([
            'code' => 'MOCKACTIVITY',
            'name' => 'Mock Activities Supplier',
            'supported_modules' => ['activity'],
            'adapter_class' => MockActivitySupplierAdapter::class,
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
        $this->seedActivitySupplier($master->id);

        $customer = $this->createUserForTenant($master, User::ROLE_CUSTOMER);
        $wallet = $this->walletFor($customer);
        app(WalletService::class)->credit($wallet, '50000.00', WalletLedgerEntry::REF_TOPUP);

        Sanctum::actingAs($customer, ['*']);

        $activityDate = now()->addDays(10)->toDateString();

        $searchResponse = $this->postJson('/api/activities/search', [
            'city' => 'Goa',
            'activity_date' => $activityDate,
            'travellers' => 2,
        ]);

        $searchResponse->assertOk();
        $offers = $searchResponse->json('data.offers');
        $this->assertNotEmpty($offers, 'Mock activities supplier should return at least one offer.');

        $offer = $offers[0];
        $this->assertArrayHasKey('gross_amount', $offer);
        $this->assertArrayHasKey('duration_minutes', $offer);
        $this->assertArrayHasKey('boarding_point', $offer);

        $holdResponse = $this->postJson('/api/activities/bookings', [
            'supplier_code' => $offer['supplier_code'],
            'external_id' => $offer['external_id'],
            'segment' => $offer['segments'][0],
            'activity_date' => $activityDate,
            'passengers' => [
                ['name' => 'Jane Traveller'],
                ['name' => 'John Traveller'],
            ],
        ]);

        $holdResponse->assertCreated();
        $bookingId = $holdResponse->json('data.id');

        $booking = Booking::withoutTenantScope()->findOrFail($bookingId);
        $this->assertSame(Booking::STATUS_HELD, $booking->status);
        $this->assertSame(Booking::MODULE_ACTIVITY, $booking->module);
        $this->assertFalse((bool) $booking->is_international);
        $this->assertNotNull($booking->supplier_booking_ref);
        $this->assertCount(1, $booking->segments);
        $this->assertCount(2, $booking->passengers);
        $this->assertNotNull($booking->metadata['provider_name'] ?? null, 'hold() must populate provider_name for the voucher PDF.');
        $this->assertNotNull($booking->metadata['meeting_point'] ?? null, 'hold() must populate meeting_point for the voucher PDF.');

        $grossAmount = $booking->gross_amount;

        $confirmResponse = $this->postJson("/api/activities/bookings/{$bookingId}/confirm");

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
        $this->seedActivitySupplier($master->id);

        $customer = $this->createUserForTenant($master, User::ROLE_CUSTOMER);
        // Deliberately no wallet top-up.

        Sanctum::actingAs($customer, ['*']);

        $activityDate = now()->addDays(10)->toDateString();

        $offer = $this->postJson('/api/activities/search', [
            'city' => 'Goa',
            'activity_date' => $activityDate,
        ])->json('data.offers.0');

        $bookingId = $this->postJson('/api/activities/bookings', [
            'supplier_code' => $offer['supplier_code'],
            'external_id' => $offer['external_id'],
            'segment' => $offer['segments'][0],
            'activity_date' => $activityDate,
            'passengers' => [['name' => 'Broke Traveller']],
        ])->json('data.id');

        $this->postJson("/api/activities/bookings/{$bookingId}/confirm")->assertStatus(402);

        $booking = Booking::withoutTenantScope()->findOrFail($bookingId);
        $this->assertSame(Booking::STATUS_HELD, $booking->status, 'A failed payment must not advance the booking status.');
    }

    public function test_own_inventory_offer_holds_and_decrements_capacity(): void
    {
        $master = $this->createMasterTenant();
        $this->seedActivitySupplier($master->id);

        $customer = $this->createUserForTenant($master, User::ROLE_CUSTOMER);
        $wallet = $this->walletFor($customer);
        app(WalletService::class)->credit($wallet, '50000.00', WalletLedgerEntry::REF_TOPUP);

        Sanctum::actingAs($customer, ['*']);

        $activityDate = now()->addDays(10)->toDateString();

        $item = ActivityInventoryItem::create([
            'tenant_id' => null,
            'title' => 'Goa Scuba Diving Experience',
            'city' => 'Goa',
            'activity_date' => $activityDate,
            'provider_name' => 'Test Water Sports Co.',
            'category' => 'Water sports',
            'duration_minutes' => 150,
            'meeting_point' => 'Goa — Baga Beach jetty',
            'is_refundable' => true,
            'capacity' => 2,
            'net_price' => '2200.00',
        ]);

        $offers = $this->postJson('/api/activities/search', [
            'city' => 'Goa',
            'activity_date' => $activityDate,
        ])->json('data.offers');

        $ownOffer = collect($offers)->firstWhere('supplier_code', ActivityInventoryItem::SUPPLIER_CODE);
        $this->assertNotNull($ownOffer, 'Own-inventory activity offer should appear in search results.');
        $this->assertSame('inv-'.$item->id, $ownOffer['external_id']);

        $bookingId = $this->postJson('/api/activities/bookings', [
            'supplier_code' => $ownOffer['supplier_code'],
            'external_id' => $ownOffer['external_id'],
            'segment' => $ownOffer['segments'][0],
            'activity_date' => $activityDate,
            'passengers' => [['name' => 'Own Inventory Traveller']],
        ])->json('data.id');

        $booking = Booking::withoutTenantScope()->findOrFail($bookingId);
        $this->assertSame(Booking::STATUS_HELD, $booking->status);
        $this->assertSame(1, $item->fresh()->capacity, 'Holding one activity slot should decrement own-inventory capacity by one.');

        $this->postJson("/api/activities/bookings/{$bookingId}/confirm")->assertOk();
        $this->assertSame(Booking::STATUS_TICKETED, $booking->fresh()->status);
    }
}
