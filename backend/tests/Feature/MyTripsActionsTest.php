<?php

namespace Tests\Feature;

use App\Domains\Booking\Models\Booking;
use App\Domains\Suppliers\Adapters\MockHotelSupplierAdapter;
use App\Domains\Suppliers\Models\Supplier;
use App\Domains\Suppliers\Models\TenantSupplierConfig;
use App\Domains\Wallet\Models\WalletLedgerEntry;
use App\Domains\Wallet\Services\WalletService;
use App\Models\User;
use Database\Seeders\HolidaySamplePackageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * My Trips: pay for a held booking, cancel your own booking (with the
 * policy refund to your wallet), and package children/infants priced on
 * their own instead of at the adult fare.
 */
class MyTripsActionsTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function hotelHold(User $user): array
    {
        $in = now()->addDays(20)->toDateString();
        $out = now()->addDays(22)->toDateString();
        $offer = $this->postJson('/api/hotels/search', ['city' => 'Goa', 'check_in_date' => $in, 'check_out_date' => $out, 'rooms' => 1, 'guests' => 2])->json('data.offers.0');

        return $this->postJson('/api/hotels/bookings', [
            'supplier_code' => $offer['supplier_code'], 'external_id' => $offer['external_id'], 'segment' => $offer['segments'][0],
            'check_in_date' => $in, 'check_out_date' => $out, 'rooms' => 1, 'guests' => [['name' => 'Asha', 'age' => 30], ['name' => 'Ravi', 'age' => 31]],
        ])->assertCreated()->json('data');
    }

    protected function setUpCustomer(): User
    {
        $master = $this->createMasterTenant();
        $supplier = Supplier::create(['code' => 'MOCKHOTEL', 'name' => 'MOCKHOTEL', 'supported_modules' => ['hotel'], 'adapter_class' => MockHotelSupplierAdapter::class]);
        TenantSupplierConfig::create(['tenant_id' => $master->id, 'supplier_id' => $supplier->id, 'credentials_encrypted' => ['api_key' => 'test'], 'is_active' => true, 'priority_order' => 1]);
        $user = $this->createUserForTenant($master, User::ROLE_CUSTOMER);
        app(WalletService::class)->credit($this->walletFor($user), '100000.00', WalletLedgerEntry::REF_TOPUP);
        Sanctum::actingAs($user, ['*']);

        return $user;
    }

    public function test_held_booking_offers_pay_now_and_cancel_in_my_trips(): void
    {
        $user = $this->setUpCustomer();
        $hold = $this->hotelHold($user);

        $row = $this->getJson('/api/my-bookings')->assertOk()->json('data.0');
        $this->assertSame('hotels/bookings', $row['pay_path']);
        $this->assertTrue($row['can_cancel']);

        // Pay from My Trips using the module's own confirm endpoint.
        $this->postJson("/api/{$row['pay_path']}/{$hold['id']}/confirm", ['wallet_amount' => $row['total_amount']])->assertOk();
        $row = $this->getJson('/api/my-bookings')->json('data.0');
        $this->assertNull($row['pay_path']);
        $this->assertContains($row['status'], [Booking::STATUS_CONFIRMED, Booking::STATUS_TICKETED]);
    }

    public function test_cancelling_an_unpaid_hold_releases_it_without_charge(): void
    {
        $user = $this->setUpCustomer();
        $hold = $this->hotelHold($user);

        $this->getJson("/api/my-bookings/{$hold['id']}/cancellation-quote")->assertOk()
            ->assertJsonPath('data.paid', false)->assertJsonPath('data.refund_amount', '0.00');
        $this->postJson("/api/my-bookings/{$hold['id']}/cancel")->assertOk()
            ->assertJsonPath('data.status', Booking::STATUS_CANCELLED);
        $this->assertSame('100000.00', (string) $this->walletFor($user)->fresh()->balance);
        $this->postJson("/api/my-bookings/{$hold['id']}/cancel")->assertStatus(422);
    }

    public function test_cancelling_a_paid_booking_refunds_the_policy_amount_to_the_wallet(): void
    {
        $user = $this->setUpCustomer();
        $hold = $this->hotelHold($user);
        $total = (string) Booking::withoutTenantScope()->find($hold['id'])->recalculateTotal();
        $this->postJson("/api/hotels/bookings/{$hold['id']}/confirm", ['wallet_amount' => $total])->assertOk();
        $afterPay = (float) $this->walletFor($user)->fresh()->balance;

        $quote = $this->getJson("/api/my-bookings/{$hold['id']}/cancellation-quote")->assertOk()->json('data');
        $this->assertTrue($quote['paid']);
        $this->assertTrue($quote['cancellable']);

        $res = $this->postJson("/api/my-bookings/{$hold['id']}/cancel", ['reason' => 'Plans changed'])->assertOk();
        $this->assertSame($quote['refund_amount'], $res->json('refund_amount'));
        $this->assertEqualsWithDelta($afterPay + (float) $quote['refund_amount'], (float) $this->walletFor($user)->fresh()->balance, 0.01);

        $expected = (float) $quote['refund_amount'] > 0 ? Booking::STATUS_REFUNDED : Booking::STATUS_CANCELLED;
        $this->assertSame($expected, Booking::withoutTenantScope()->find($hold['id'])->status);
        $this->assertFalse($this->getJson('/api/my-bookings')->json('data.0.can_cancel'));
    }

    public function test_only_the_booker_can_cancel(): void
    {
        $user = $this->setUpCustomer();
        $hold = $this->hotelHold($user);

        Sanctum::actingAs($this->createUserForTenant($user->tenant, User::ROLE_CUSTOMER), ['*']);
        $this->postJson("/api/my-bookings/{$hold['id']}/cancel")->assertForbidden();
        $this->getJson("/api/my-bookings/{$hold['id']}/cancellation-quote")->assertForbidden();
    }

    public function test_package_children_and_infants_get_their_own_price(): void
    {
        $master = $this->createMasterTenant();
        $this->seed(HolidaySamplePackageSeeder::class);
        $slug = 'goa-beach-escape-north-south-goa';

        // Goa has tiers for adults and child-with-bed only.
        $tiers = collect($this->getJson("/api/packages/{$slug}")->assertOk()->json('data.price_tiers'))->keyBy('occupancy_type');
        $this->assertCount(5, $tiers);
        $adult = (float) $tiers['adult_sharing']['sell_price'];
        $this->assertEqualsWithDelta($adult * 0.5, (float) $tiers['child_without_bed']['sell_price'], 0.01);
        $this->assertEqualsWithDelta($adult * 0.1, (float) $tiers['infant']['sell_price'], 0.01);
        $this->assertTrue($tiers['infant']['derived']);
        $this->assertArrayNotHasKey('net_price', $tiers['infant']);

        $mix = [
            ['name' => 'Asha', 'occupancy_type' => 'adult_sharing'],
            ['name' => 'Ravi', 'occupancy_type' => 'adult_sharing'],
            ['name' => 'Tara', 'occupancy_type' => 'child_with_bed'],
            ['name' => 'Kiran', 'occupancy_type' => 'child_without_bed'],
            ['name' => 'Baby', 'occupancy_type' => 'infant'],
        ];
        $expected = $adult * 2 + (float) $tiers['child_with_bed']['sell_price'] + $adult * 0.5 + $adult * 0.1;

        $quote = $this->postJson("/api/packages/{$slug}/quote", ['travelers' => $mix])->assertOk()->json('data.total_amount');
        $this->assertEqualsWithDelta($expected, (float) $quote, 0.01);

        Sanctum::actingAs($this->createUserForTenant($master, User::ROLE_CUSTOMER), ['*']);
        $hold = $this->postJson("/api/packages/{$slug}/bookings", ['travel_date' => now()->addDays(30)->toDateString(), 'travelers' => $mix])->assertCreated();
        $this->assertEqualsWithDelta((float) $quote, (float) $hold->json('data.total_amount'), 0.01);
    }
}
