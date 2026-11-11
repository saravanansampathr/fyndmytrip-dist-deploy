<?php

namespace Tests\Feature;

use App\Domains\Auth\Models\ApiPartnerCredential;
use App\Domains\Booking\Models\Booking;
use App\Domains\Suppliers\Adapters\MockHotelSupplierAdapter;
use App\Domains\Suppliers\Models\Supplier;
use App\Domains\Suppliers\Models\TenantSupplierConfig;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Wallet\Models\WalletLedgerEntry;
use App\Domains\Wallet\Services\WalletService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/** The partner (Connect) API: token, scopes, origin, rate limit, and a full hotel booking. */
class PartnerApiTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected User $apiUser;

    protected function credential(array $overrides = []): array
    {
        $master = $this->createMasterTenant();
        $supplier = Supplier::create(['code' => 'MOCKHOTEL', 'name' => 'MOCKHOTEL', 'supported_modules' => ['hotel'], 'adapter_class' => MockHotelSupplierAdapter::class]);
        TenantSupplierConfig::create(['tenant_id' => $master->id, 'supplier_id' => $supplier->id, 'credentials_encrypted' => ['api_key' => 'x'], 'is_active' => true, 'priority_order' => 1]);

        $partner = $this->createChildTenant($master, Tenant::TYPE_API_PARTNER);
        $this->apiUser = $this->createUserForTenant($partner, User::ROLE_API_USER);
        app(WalletService::class)->credit($this->walletFor($this->apiUser), '100000.00', WalletLedgerEntry::REF_TOPUP);

        $credential = ApiPartnerCredential::withoutTenantScope()->create(array_merge([
            'tenant_id' => $partner->id,
            'label' => 'Test',
            'client_secret_hash' => Hash::make('s3cret'),
            'allowed_domain' => 'partner.example.com',
            'allowed_scopes' => ['hotel'],
            'rate_limit_per_min' => 30,
            'is_active' => true,
        ], $overrides));

        return [$credential->client_id, 's3cret', $credential];
    }

    protected function token(string $id, string $secret): string
    {
        return $this->postJson('/api/partner/v1/token', ['client_id' => $id, 'client_secret' => $secret])->assertOk()->json('access_token');
    }

    public function test_a_partner_books_a_hotel_end_to_end_from_its_wallet(): void
    {
        [$id, $secret] = $this->credential();
        $auth = ['Authorization' => 'Bearer '.$this->token($id, $secret)];

        $this->getJson('/api/partner/v1/me', $auth)->assertOk()->assertJsonPath('data.wallet.balance', '100000.00');

        $in = now()->addDays(15)->toDateString();
        $out = now()->addDays(17)->toDateString();
        $offer = $this->postJson('/api/partner/v1/hotels/search', ['city' => 'Goa', 'check_in_date' => $in, 'check_out_date' => $out, 'rooms' => 1, 'guests' => 2], $auth)
            ->assertOk()->json('data.offers.0');
        $this->assertArrayNotHasKey('net_amount', $offer);

        $hold = $this->postJson('/api/partner/v1/hotels/bookings', [
            'supplier_code' => $offer['supplier_code'], 'external_id' => $offer['external_id'], 'segment' => $offer['segments'][0],
            'check_in_date' => $in, 'check_out_date' => $out, 'rooms' => 1, 'guests' => [['name' => 'Asha', 'age' => 30], ['name' => 'Ravi', 'age' => 31]],
        ], $auth)->assertCreated()->json('data');
        $this->assertEqualsWithDelta((float) $offer['gross_amount'], (float) $hold['total_amount'], 0.01);

        $this->postJson("/api/partner/v1/hotels/bookings/{$hold['id']}/confirm", [], $auth)->assertOk();
        $booking = $this->getJson("/api/partner/v1/bookings/{$hold['id']}", $auth)->assertOk()->json('data');
        $this->assertContains($booking['status'], [Booking::STATUS_CONFIRMED, Booking::STATUS_TICKETED]);
        $this->assertSame(1, $this->getJson('/api/partner/v1/bookings', $auth)->assertOk()->json('meta.total'));

        $this->postJson("/api/partner/v1/bookings/{$hold['id']}/cancel", [], $auth)->assertOk();
    }

    public function test_bad_secret_scope_origin_and_revocation_are_refused(): void
    {
        [$id, $secret, $credential] = $this->credential();
        $this->postJson('/api/partner/v1/token', ['client_id' => $id, 'client_secret' => 'wrong'])->assertUnauthorized();

        $auth = ['Authorization' => 'Bearer '.$this->token($id, $secret)];
        $this->postJson('/api/partner/v1/flights/search', ['origin' => 'DEL', 'destination' => 'BOM', 'departure_date' => now()->addDays(5)->toDateString(), 'adults' => 1], $auth)->assertForbidden();
        $this->getJson('/api/partner/v1/me', $auth + ['Origin' => 'https://evil.example.org'])->assertForbidden();
        $this->getJson('/api/partner/v1/me', $auth + ['Origin' => 'https://www.partner.example.com'])->assertOk();

        // A normal portal login token can't be used on the partner API.
        $portal = $this->apiUser->createToken('portal')->plainTextToken;
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/partner/v1/me', ['Authorization' => 'Bearer '.$portal])->assertUnauthorized();

        $credential->update(['is_active' => false]);
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/partner/v1/me', $auth)->assertUnauthorized();
    }

    public function test_rate_limit_applies_per_credential(): void
    {
        [$id, $secret] = $this->credential(['rate_limit_per_min' => 2]);
        $auth = ['Authorization' => 'Bearer '.$this->token($id, $secret)];
        $this->getJson('/api/partner/v1/me', $auth)->assertOk();
        $this->getJson('/api/partner/v1/me', $auth)->assertOk();
        $this->getJson('/api/partner/v1/me', $auth)->assertStatus(429);
    }

    public function test_docs_are_published(): void
    {
        $this->getJson('/api/partner/v1/openapi.json')->assertOk()->assertJsonPath('info.title', 'FyndMyTrip Partner API');
        $this->get('/api/partner/v1/docs')->assertOk()->assertSee('redoc', false);
    }
}
