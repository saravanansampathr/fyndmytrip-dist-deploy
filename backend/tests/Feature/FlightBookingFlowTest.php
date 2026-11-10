<?php

namespace Tests\Feature;

use App\Domains\Booking\Models\Booking;
use App\Domains\Suppliers\Models\Supplier;
use App\Domains\Suppliers\Models\TenantSupplierConfig;
use App\Domains\Wallet\Services\WalletService;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * End-to-end mock booking pipeline: search -> hold -> confirm, against
 * MockFlightSupplierAdapter, asserting the booking status machine, the
 * markup calculation, and the wallet debit all agree with each other.
 */
class FlightBookingFlowTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    public function test_full_search_hold_confirm_flow_debits_the_wallet_and_ticket_the_booking(): void
    {
        $master = $this->createMasterTenant();

        $supplier = Supplier::create([
            'code' => 'MOCKAIR',
            'name' => 'Mock Flight Supplier',
            'supported_modules' => ['flight'],
            'adapter_class' => \App\Domains\Suppliers\Adapters\MockFlightSupplierAdapter::class,
        ]);

        TenantSupplierConfig::create([
            'tenant_id' => $master->id,
            'supplier_id' => $supplier->id,
            'credentials_encrypted' => ['api_key' => 'test'],
            'is_active' => true,
            'priority_order' => 1,
        ]);

        $customer = $this->createUserForTenant($master, User::ROLE_CUSTOMER);
        $wallet = $this->walletFor($customer);
        app(WalletService::class)->credit($wallet, '20000.00', \App\Domains\Wallet\Models\WalletLedgerEntry::REF_TOPUP);

        Sanctum::actingAs($customer, ['*']);

        $departureDate = now()->addDays(10)->toDateString();

        $searchResponse = $this->postJson('/api/flights/search', [
            'origin' => 'DEL',
            'destination' => 'BOM',
            'departure_date' => $departureDate,
        ]);

        $searchResponse->assertOk();
        $offers = $searchResponse->json('data');
        $this->assertNotEmpty($offers, 'Mock supplier should return at least one flight offer.');

        $offer = $offers[0];
        $this->assertArrayHasKey('gross_amount', $offer);
        $this->assertArrayHasKey('markup_amount', $offer);

        $holdResponse = $this->postJson('/api/flights/bookings', [
            'supplier_code' => $offer['supplier_code'],
            'external_id' => $offer['external_id'],
            'travel_date' => $departureDate,
            'segments' => $offer['segments'],
            'passengers' => [
                ['name' => 'Jane Traveller', 'gender' => 'female'],
            ],
        ]);

        $holdResponse->assertCreated();
        $bookingId = $holdResponse->json('data.id');

        $booking = Booking::withoutTenantScope()->findOrFail($bookingId);
        $this->assertSame(Booking::STATUS_HELD, $booking->status);
        $this->assertNotNull($booking->supplier_booking_ref);

        $grossAmount = $booking->gross_amount;

        $confirmResponse = $this->postJson("/api/flights/bookings/{$bookingId}/confirm");

        $confirmResponse->assertOk()->assertJsonPath('status', Booking::STATUS_TICKETED);

        $booking->refresh();
        $this->assertSame(Booking::STATUS_TICKETED, $booking->status);
        $this->assertNotEmpty($booking->metadata['ticket_numbers'] ?? []);

        $expectedBalance = (string) BigDecimal::of('20000.00')->minus(BigDecimal::of((string) $grossAmount))->toScale(2);
        $this->assertSame($expectedBalance, $wallet->fresh()->balance);
    }

    public function test_confirm_fails_with_402_when_wallet_balance_is_insufficient(): void
    {
        $master = $this->createMasterTenant();

        $supplier = Supplier::create([
            'code' => 'MOCKAIR',
            'name' => 'Mock Flight Supplier',
            'supported_modules' => ['flight'],
            'adapter_class' => \App\Domains\Suppliers\Adapters\MockFlightSupplierAdapter::class,
        ]);

        TenantSupplierConfig::create([
            'tenant_id' => $master->id,
            'supplier_id' => $supplier->id,
            'credentials_encrypted' => ['api_key' => 'test'],
            'is_active' => true,
            'priority_order' => 1,
        ]);

        $customer = $this->createUserForTenant($master, User::ROLE_CUSTOMER);
        // Deliberately no wallet top-up — balance stays at 0.00.

        Sanctum::actingAs($customer, ['*']);

        $departureDate = now()->addDays(10)->toDateString();

        $offer = $this->postJson('/api/flights/search', [
            'origin' => 'DEL',
            'destination' => 'BOM',
            'departure_date' => $departureDate,
        ])->json('data.0');

        $bookingId = $this->postJson('/api/flights/bookings', [
            'supplier_code' => $offer['supplier_code'],
            'external_id' => $offer['external_id'],
            'travel_date' => $departureDate,
            'segments' => $offer['segments'],
            'passengers' => [['name' => 'Broke Traveller']],
        ])->json('data.id');

        $this->postJson("/api/flights/bookings/{$bookingId}/confirm")->assertStatus(402);

        $booking = Booking::withoutTenantScope()->findOrFail($bookingId);
        $this->assertSame(Booking::STATUS_HELD, $booking->status, 'A failed payment must not advance the booking status.');
    }
}
