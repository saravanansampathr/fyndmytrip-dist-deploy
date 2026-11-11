<?php

namespace Tests\Feature;

use App\Domains\Booking\Models\Booking;
use App\Domains\Wallet\Models\WalletLedgerEntry;
use App\Domains\Wallet\Services\WalletService;
use App\Models\User;
use Database\Seeders\HolidaySamplePackageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/** A held holiday-package booking can be paid for, by its traveller only. */
class HolidayPaymentTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    public function test_traveller_pays_for_a_package_with_wallet_and_gateway(): void
    {
        $master = $this->createMasterTenant();
        $this->seed(HolidaySamplePackageSeeder::class);
        $customer = $this->createUserForTenant($master, User::ROLE_CUSTOMER);
        app(WalletService::class)->credit($this->walletFor($customer), '50000.00', WalletLedgerEntry::REF_TOPUP);
        Sanctum::actingAs($customer, ['*']);

        $hold = $this->postJson('/api/packages/goa-beach-escape-north-south-goa/bookings', [
            'travel_date' => now()->addDays(40)->toDateString(),
            'travelers' => [['name' => 'Asha Rao', 'occupancy_type' => 'adult_sharing'], ['name' => 'Ravi Rao', 'occupancy_type' => 'adult_sharing']],
        ])->assertCreated()->assertJsonMissingPath('data.markup_amount')->assertJsonMissingPath('data.metadata.reseller_markup_amount');
        $id = $hold->json('data.id');
        $total = (float) $hold->json('data.total_amount');
        $this->assertGreaterThan(0, $total);

        $other = $this->createUserForTenant($master, User::ROLE_CUSTOMER);
        Sanctum::actingAs($other, ['*']);
        $this->postJson("/api/holiday-bookings/{$id}/confirm", ['wallet_amount' => 0])->assertForbidden();

        Sanctum::actingAs($customer, ['*']);
        $this->postJson("/api/holiday-bookings/{$id}/confirm", ['wallet_amount' => 1000])
            ->assertOk()->assertJsonPath('requires_payment', true);
        $this->postJson("/api/holiday-bookings/{$id}/pay", ['method' => 'upi'])
            ->assertOk()->assertJsonPath('data.status', Booking::STATUS_CONFIRMED);

        $this->assertSame('49000.00', (string) $this->walletFor($customer)->fresh()->balance);
        $this->postJson("/api/holiday-bookings/{$id}/confirm", ['wallet_amount' => 0])->assertStatus(409);
    }

    public function test_visa_application_is_priced_per_applicant_and_paid(): void
    {
        $master = $this->createMasterTenant();
        $this->seed(\Database\Seeders\VisaSampleProductSeeder::class);
        $customer = $this->createUserForTenant($master, User::ROLE_CUSTOMER);
        app(WalletService::class)->credit($this->walletFor($customer), '50000.00', WalletLedgerEntry::REF_TOPUP);

        $product = $this->getJson('/api/visa?q=Thailand')->assertOk()->assertJsonMissingPath('data.0.net_price')->json('data.0');

        Sanctum::actingAs($customer, ['*']);
        $hold = $this->postJson("/api/visa/{$product['id']}/applications", [
            'travel_date' => now()->addDays(30)->toDateString(),
            'applicants' => [['name' => 'Asha Rao', 'passport_number' => 'Z1234567'], ['name' => 'Ravi Rao', 'passport_number' => 'Y7654321']],
        ])->assertCreated()->assertJsonPath('data.status', Booking::STATUS_HELD)->assertJsonMissingPath('data.markup_amount');

        $this->assertEqualsWithDelta((float) $product['sell_price'] * 2, (float) $hold->json('data.total_amount'), 0.01);

        $this->postJson('/api/visa-bookings/'.$hold->json('data.id').'/confirm', ['wallet_amount' => $hold->json('data.total_amount')])
            ->assertOk()->assertJsonPath('data.status', Booking::STATUS_CONFIRMED);
        $this->getJson('/api/my-bookings')->assertOk()->assertJsonPath('data.0.summary', 'Tourist e-Visa · Thailand');
    }
}
