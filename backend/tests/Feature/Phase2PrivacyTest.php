<?php

namespace Tests\Feature;

use App\Domains\Booking\Models\Booking;
use App\Domains\Marketing\Models\NewsletterSubscriber;
use App\Domains\Privacy\Models\ConsentLog;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Wallet\Models\Wallet;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/** DPDP: consent at sign-up and later, download my data, delete my account. */
class Phase2PrivacyTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_sign_up_records_terms_and_optional_offers_consent(): void
    {
        $this->createMasterTenant();
        $this->postJson('/api/auth/register', ['name' => 'Asha', 'email' => 'asha@example.com', 'password' => 'password1', 'marketing_consent' => true])->assertCreated();
        $u = User::query()->where('email', 'asha@example.com')->firstOrFail();
        $this->assertNotNull($u->terms_accepted_at);
        $this->assertTrue($u->marketing_consent);
        $this->assertSame(['terms', 'marketing'], ConsentLog::query()->where('user_id', $u->id)->orderBy('id')->pluck('purpose')->all());
        $this->assertTrue(NewsletterSubscriber::query()->where('email', 'asha@example.com')->whereNull('unsubscribed_at')->exists());

        Sanctum::actingAs($u);
        $this->putJson('/api/privacy/consent', ['marketing' => false])->assertOk();
        $this->assertFalse($u->fresh()->marketing_consent);
        $this->assertNotNull(NewsletterSubscriber::query()->where('email', 'asha@example.com')->value('unsubscribed_at'));
        $this->assertFalse($this->getJson('/api/privacy')->json('data.marketing_consent'));
    }

    public function test_download_my_data_and_delete_my_account(): void
    {
        $master = $this->createMasterTenant();
        $u = $this->createUserForTenant($master, User::ROLE_CUSTOMER, ['email' => 'c@example.com', 'name' => 'Chris']);
        $wallet = Wallet::query()->firstOrCreate(['tenant_id' => $master->id, 'owner_type' => Wallet::OWNER_USER, 'owner_id' => $u->id]);
        $booking = Booking::withoutTenantScope()->create([
            'tenant_id' => $master->id, 'created_by_user_id' => $u->id, 'module' => 'flight', 'status' => Booking::STATUS_TICKETED,
            'channel' => Booking::CHANNEL_DIRECT, 'supplier_code' => 'MOCKAIR', 'gross_amount' => '5000.00', 'supplier_net_amount' => '4500.00',
            'markup_amount' => '500.00', 'commission_amount' => '0.00', 'currency' => 'INR', 'total_amount' => '5000.00', 'passenger_count' => 1,
            'contact_email' => 'c@example.com', 'travel_date_start' => now()->addDays(5)->toDateString(),
        ]);
        $booking->passengers()->create(['name' => 'Chris P', 'first_name' => 'Chris', 'last_name' => 'P', 'passenger_type' => 'adult', 'passport_number' => 'Z1234567']);
        Sanctum::actingAs($u);

        $export = $this->getJson('/api/privacy/export')->assertOk();
        $this->assertSame('Chris', $export->json('profile.name'));
        $this->assertSame('Z1234567', $export->json('bookings.0.travellers.0.passport_number'));

        // Upcoming trip blocks deletion; a wrong password too.
        $this->postJson('/api/privacy/delete-account', ['password' => 'password', 'confirm' => 'DELETE'])->assertStatus(422);
        $booking->forceFill(['travel_date_start' => now()->subDays(5)->toDateString()])->save();
        $this->postJson('/api/privacy/delete-account', ['password' => 'wrong', 'confirm' => 'DELETE'])->assertStatus(422);
        $this->postJson('/api/privacy/delete-account', ['password' => 'password', 'confirm' => 'DELETE'])->assertOk();

        $u->refresh();
        $this->assertSame('Deleted user', $u->name);
        $this->assertStringEndsWith('@invalid.local', $u->email);
        $this->assertSame(User::STATUS_DEACTIVATED, $u->status);
        $this->assertNotNull($u->erased_at);
        $this->assertNull($booking->passengers()->first()->passport_number);
        $this->assertNotNull(Booking::withoutTenantScope()->find($booking->id), 'bookings are kept for tax records');
        $this->assertSame(0, $u->tokens()->count());
        $this->postJson('/api/auth/login', ['email' => 'c@example.com', 'password' => 'password'])->assertStatus(422);
    }

    public function test_business_logins_cannot_self_erase(): void
    {
        $agency = $this->createChildTenant($this->createMasterTenant(), Tenant::TYPE_AGENT);
        Sanctum::actingAs($this->createUserForTenant($agency, User::ROLE_AGENT));
        $this->postJson('/api/privacy/delete-account', ['password' => 'password', 'confirm' => 'DELETE'])->assertStatus(422);
    }
}
