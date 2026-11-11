<?php

namespace Tests\Feature;

use App\Domains\Booking\Models\Booking;
use App\Domains\Suppliers\Models\Supplier;
use App\Domains\Suppliers\Models\TenantSupplierConfig;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\HolidaySamplePackageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Live report (demo site): booking a flight as a guest with an email and
 * phone signed the visitor in under that email, with no password or code,
 * and a phone number already used by some account blocked the booking.
 *
 * Guest checkout must never sign in to an account, must not care whether
 * the email/phone is already used, and its token must only work for the
 * guest's own booking.
 */
class GuestCheckoutTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected Tenant $master;

    protected function setUp(): void
    {
        parent::setUp();
        $this->master = $this->createMasterTenant();

        $supplier = Supplier::create([
            'code' => 'MOCKAIR',
            'name' => 'Mock Flight Supplier',
            'supported_modules' => ['flight'],
            'adapter_class' => \App\Domains\Suppliers\Adapters\MockFlightSupplierAdapter::class,
        ]);
        TenantSupplierConfig::create([
            'tenant_id' => $this->master->id,
            'supplier_id' => $supplier->id,
            'credentials_encrypted' => ['api_key' => 'test'],
            'is_active' => true,
            'priority_order' => 1,
        ]);
    }

    protected function guestFlightHold(array $guest, array $overrides = [])
    {
        $date = now()->addDays(12)->toDateString();
        $offer = $this->postJson('/api/flights/search', [
            'origin' => 'DEL', 'destination' => 'BOM', 'departure_date' => $date,
        ])->assertOk()->json('data.legs.0.offers.0');

        return $this->postJson('/api/flights/guest-bookings', array_merge([
            'legs' => [[
                'supplier_code' => $offer['supplier_code'],
                'external_id' => $offer['external_id'],
                'segments' => $offer['segments'],
            ]],
            'travel_date' => $date,
            'contact_email' => $guest['guest_email'],
            'contact_phone' => $guest['guest_phone'] ?? null,
            'passengers' => [['first_name' => 'Guest', 'last_name' => 'Traveller', 'gender' => 'female']],
        ], $guest, $overrides));
    }

    /** Use a bearer token for the next request, with no cached login. */
    protected function asBearer(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$token);
    }

    public function test_guest_using_an_existing_accounts_email_and_phone_is_not_signed_in_to_it(): void
    {
        $owner = $this->createUserForTenant($this->master, User::ROLE_CUSTOMER, [
            'name' => 'Account Owner', 'email' => 'owner@example.test', 'phone' => '9876543210',
        ]);

        $hold = $this->guestFlightHold([
            'guest_name' => 'Someone Else', 'guest_email' => 'Owner@Example.test', 'guest_phone' => '9876543210',
        ])->assertCreated()
            ->assertJsonPath('guest_checkout', true)
            ->assertJsonPath('user.is_guest', true)
            ->assertJsonPath('user.email', null)
            ->assertJsonPath('user.phone', null);

        $this->assertNotSame($owner->id, $hold->json('user.id'));
        $this->assertSame(0, $owner->tokens()->count(), 'No token may ever be issued for the existing account.');

        $booking = Booking::withoutTenantScope()->findOrFail($hold->json('data.id'));
        $this->assertNotSame($owner->id, $booking->created_by_user_id);
        $this->assertSame('Owner@Example.test', $booking->contact_email);

        // The token is a guest session, not the owner's account.
        $this->asBearer($hold->json('token'))->getJson('/api/auth/me')
            ->assertOk()->assertJsonPath('data.is_guest', true)->assertJsonPath('data.email', null);

        // And it can't see the owner's bookings or change any profile.
        $this->asBearer($hold->json('token'))->getJson('/api/my-bookings')->assertUnauthorized()->assertJsonPath('guest_session', true);
        $this->asBearer($hold->json('token'))->putJson('/api/auth/profile', ['name' => 'X', 'email' => 'new@example.test'])->assertUnauthorized();
        $this->asBearer($hold->json('token'))->postJson('/api/auth/change-password', ['current_password' => 'x', 'password' => 'password1', 'password_confirmation' => 'password1'])->assertUnauthorized();
        $this->asBearer($hold->json('token'))->postJson('/api/auth/two-factor/setup')->assertUnauthorized();
        $this->asBearer($hold->json('token'))->postJson('/api/wallet/topup', ['amount' => 100])->assertUnauthorized();
    }

    public function test_guest_can_finish_and_view_their_own_booking_only(): void
    {
        $hold = $this->guestFlightHold([
            'guest_name' => 'Asha Rao', 'guest_email' => 'asha@example.test', 'guest_phone' => '9000000001',
        ])->assertCreated();
        $id = $hold->json('data.id');
        $token = $hold->json('token');

        $this->asBearer($token)->getJson("/api/flights/bookings/{$id}")->assertOk();
        $this->asBearer($token)->getJson('/api/wallet')->assertOk();
        $this->asBearer($token)->getJson('/api/flights/ancillary-catalog')->assertOk();
        $this->asBearer($token)->getJson("/api/flights/bookings/{$id}/seatmap")->assertOk();
        $this->asBearer($token)->postJson("/api/flights/bookings/{$id}/confirm", ['wallet_amount' => 0])
            ->assertOk()->assertJsonPath('requires_payment', true);
        $this->asBearer($token)->postJson('/api/payments/checkout', ['booking_id' => $id, 'method' => 'upi'])->assertOk();
        $this->asBearer($token)->postJson("/api/flights/bookings/{$id}/pay", ['method' => 'upi', 'fmt_checked' => true])
            ->assertOk()->assertJsonPath('data.status', Booking::STATUS_TICKETED);

        // Another guest's token can't open this booking.
        $other = $this->guestFlightHold([
            'guest_name' => 'Ravi', 'guest_email' => 'ravi@example.test', 'guest_phone' => '9000000002',
        ])->assertCreated();
        $this->asBearer($other->json('token'))->getJson("/api/flights/bookings/{$id}")->assertForbidden();
    }

    public function test_same_guest_email_and_phone_can_book_again(): void
    {
        $guest = ['guest_name' => 'Repeat Guest', 'guest_email' => 'repeat@example.test', 'guest_phone' => '9000000003'];
        $first = $this->guestFlightHold($guest)->assertCreated();
        $second = $this->guestFlightHold($guest)->assertCreated();

        $this->assertNotSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(0, User::query()->where('email', 'repeat@example.test')->count(), 'Guest checkout must not create accounts.');
    }

    public function test_guest_session_cannot_be_used_to_sign_in_or_reset_a_password(): void
    {
        $hold = $this->guestFlightHold([
            'guest_name' => 'Asha', 'guest_email' => 'asha2@example.test', 'guest_phone' => '9000000004',
        ])->assertCreated();
        $guestUser = User::findOrFail($hold->json('user.id'));
        $this->assertTrue($guestUser->is_guest);
        $this->assertNull($guestUser->password_hash);

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/auth/login', ['email' => 'asha2@example.test', 'password' => ''])->assertStatus(422);
        $this->postJson('/api/auth/forgot-password', ['email' => 'asha2@example.test'])->assertOk();
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => 'asha2@example.test']);

        // The guest can still create a real account with the same email and phone later.
        $this->postJson('/api/auth/register', [
            'name' => 'Asha', 'email' => 'asha2@example.test', 'phone' => '9000000004', 'password' => 'password123',
        ])->assertCreated()->assertJsonPath('user.is_guest', false);
    }

    public function test_failed_guest_hold_leaves_no_guest_user_behind(): void
    {
        $this->postJson('/api/flights/guest-bookings', [
            'guest_name' => 'Nobody', 'guest_email' => 'nobody@example.test',
            'legs' => [], 'travel_date' => now()->addDays(5)->toDateString(), 'passengers' => [],
        ])->assertStatus(422);

        $this->assertSame(0, User::query()->where('is_guest', true)->count());
    }

    public function test_holiday_guest_booking_with_an_existing_email_is_a_guest_booking(): void
    {
        $this->seed(HolidaySamplePackageSeeder::class);
        $owner = $this->createUserForTenant($this->master, User::ROLE_CUSTOMER, ['email' => 'pkg@example.test', 'phone' => '9111111111']);

        $hold = $this->postJson('/api/packages/goa-beach-escape-north-south-goa/guest-bookings', [
            'guest_name' => 'Pkg Guest', 'guest_email' => 'pkg@example.test', 'guest_phone' => '9111111111',
            'travel_date' => now()->addDays(40)->toDateString(),
            'travelers' => [['name' => 'Pkg Guest', 'occupancy_type' => 'adult_sharing'], ['name' => 'Two', 'occupancy_type' => 'adult_sharing']],
        ])->assertCreated()->assertJsonPath('user.is_guest', true);

        $this->assertSame(0, $owner->tokens()->count());
        $booking = Booking::withoutTenantScope()->findOrFail($hold->json('data.id'));
        $this->assertSame('pkg@example.test', $booking->contact_email);

        $this->asBearer($hold->json('token'))->postJson("/api/holiday-bookings/{$booking->id}/confirm", ['wallet_amount' => 0])
            ->assertOk()->assertJsonPath('requires_payment', true);
    }

    public function test_guest_tokens_expire(): void
    {
        $hold = $this->guestFlightHold([
            'guest_name' => 'Short', 'guest_email' => 'short@example.test',
        ])->assertCreated();

        $token = PersonalAccessToken::findToken($hold->json('token'));
        $this->assertSame(['guest-checkout'], $token->abilities);
        $this->assertNotNull($token->expires_at);
        $this->assertTrue($token->expires_at->lte(now()->addHours(6)->addMinute()));
    }

    public function test_registering_with_a_phone_already_in_use_is_a_friendly_error(): void
    {
        $this->createUserForTenant($this->master, User::ROLE_CUSTOMER, ['phone' => '9222222222']);

        $this->postJson('/api/auth/register', [
            'name' => 'New', 'email' => 'new@example.test', 'phone' => '9222222222', 'password' => 'password123',
        ])->assertStatus(422)->assertJsonValidationErrors('phone');
    }
    public function test_a_customer_cannot_open_or_list_another_customers_booking(): void
    {
        $hold = $this->guestFlightHold([
            'guest_name' => 'Owner', 'guest_email' => 'own@example.test',
        ])->assertCreated();
        $id = $hold->json('data.id');

        $stranger = $this->createUserForTenant($this->master, User::ROLE_CUSTOMER);
        $token = $stranger->issueApiToken();
        $this->asBearer($token)->getJson("/api/flights/bookings/{$id}")->assertForbidden();
        $this->asBearer($token)->getJson("/api/flights/bookings/{$id}/ticket.pdf")->assertForbidden();
        $this->asBearer($token)->postJson("/api/flights/bookings/{$id}/confirm", ['wallet_amount' => 0])->assertForbidden();
        $this->asBearer($token)->getJson('/api/flights/bookings')->assertOk()->assertJsonCount(0, 'data');
    }
    public function test_wallet_movements_on_a_guest_wallet_do_not_fail_for_lack_of_an_email(): void
    {
        $hold = $this->guestFlightHold(['guest_name' => 'Pay', 'guest_email' => 'pay@example.test'])->assertCreated();
        $guest = User::findOrFail($hold->json('user.id'));

        $entry = app(\App\Domains\Wallet\Services\WalletService::class)
            ->credit($this->walletFor($guest), '100.00', \App\Domains\Wallet\Models\WalletLedgerEntry::REF_TOPUP);
        $this->assertSame('100.00', (string) $entry->running_balance);
    }
}
