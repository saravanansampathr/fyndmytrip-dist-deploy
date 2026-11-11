<?php

namespace Tests\Feature;

use App\Domains\Booking\Models\Booking;
use App\Domains\Booking\Services\BookingStatusService;
use App\Domains\Loyalty\Models\FareAlert;
use App\Domains\Loyalty\Models\LoyaltyPoint;
use App\Domains\Loyalty\Services\FareAlertService;
use App\Domains\Loyalty\Services\LoyaltyService;
use App\Domains\Notifications\Models\NotificationLog;
use App\Domains\Settings\Services\SettingsService;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Wallet\Models\Wallet;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/** Loyalty points, referrals, fare alerts and per-site marketing tags. */
class Phase3RewardsAlertsTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Mail::fake();
        $this->seed(RolePermissionSeeder::class);
    }

    protected function booking(Tenant $tenant, User $by, string $amount = '10000.00'): Booking
    {
        return Booking::withoutTenantScope()->create([
            'tenant_id' => $tenant->id, 'created_by_user_id' => $by->id, 'module' => 'flight', 'status' => Booking::STATUS_HELD,
            'channel' => Booking::CHANNEL_DIRECT, 'supplier_code' => 'MOCKAIR', 'gross_amount' => $amount, 'supplier_net_amount' => '9000.00',
            'markup_amount' => '500.00', 'commission_amount' => '0.00', 'currency' => 'INR', 'total_amount' => $amount, 'passenger_count' => 1,
            'contact_email' => 'a@example.com', 'travel_date_start' => now()->addDays(20)->toDateString(),
        ]);
    }

    public function test_points_referrals_redeem_and_reversal(): void
    {
        $master = $this->createMasterTenant();
        app(SettingsService::class)->put('loyalty', ['enabled' => true, 'points_per_100' => 1, 'point_value' => 0.5, 'min_redeem_points' => 100, 'referral' => ['enabled' => true, 'referrer_points' => 300, 'referee_points' => 150, 'min_booking_amount' => 2000]]);
        $friend = $this->createUserForTenant($master, User::ROLE_CUSTOMER, ['email' => 'friend@example.com']);
        Sanctum::actingAs($friend);
        $code = $this->getJson('/api/loyalty')->assertOk()->json('data.referral.code');
        $this->assertNotEmpty($code);

        Sanctum::actingAs(new User()); // sign out
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/auth/register', ['name' => 'New Person', 'email' => 'new@example.com', 'password' => 'password1', 'referral_code' => strtolower($code)])->assertCreated();
        $new = User::query()->where('email', 'new@example.com')->firstOrFail();
        $this->assertSame($friend->id, $new->referred_by_user_id);

        $svc = app(BookingStatusService::class);
        $b = $this->booking($master, $new);
        $svc->transition($b, Booking::STATUS_CONFIRMED);
        $svc->transition($b->fresh(), Booking::STATUS_TICKETED);
        $loyalty = app(LoyaltyService::class);
        $this->assertSame(100 + 150, $loyalty->balance($new));
        $this->assertSame(300, $loyalty->balance($friend));

        // A second booking earns points but no second referral bonus.
        $b2 = $this->booking($master, $new, '5000.00');
        $svc->transition($b2, Booking::STATUS_CONFIRMED);
        $svc->transition($b2->fresh(), Booking::STATUS_TICKETED);
        $this->assertSame(300, $loyalty->balance($new));
        $this->assertSame(300, $loyalty->balance($friend));

        Sanctum::actingAs($new);
        $this->postJson('/api/loyalty/redeem', ['points' => 50])->assertStatus(422);
        $this->postJson('/api/loyalty/redeem', ['points' => 1000])->assertStatus(422);
        $this->postJson('/api/loyalty/redeem', ['points' => 200])->assertOk()->assertJsonPath('data.credited', '100.00');
        $this->assertSame('100.00', Wallet::query()->where('owner_type', Wallet::OWNER_USER)->where('owner_id', $new->id)->value('balance'));
        $this->assertSame(100, $loyalty->balance($new));

        // Cancelling the first booking takes back its points and the referral bonuses.
        $svc->transition($b->fresh(), Booking::STATUS_CANCELLED);
        $this->assertSame(100 - 250, $loyalty->balance($new));
        $this->assertSame(0, $loyalty->balance($friend));
    }

    public function test_points_expire_oldest_first(): void
    {
        $master = $this->createMasterTenant();
        $u = $this->createUserForTenant($master, User::ROLE_CUSTOMER);
        LoyaltyPoint::query()->create(['user_id' => $u->id, 'tenant_id' => $master->id, 'points' => 100, 'reason' => 'earn', 'expires_at' => now()->subDay()]);
        LoyaltyPoint::query()->create(['user_id' => $u->id, 'tenant_id' => $master->id, 'points' => 50, 'reason' => 'earn', 'expires_at' => now()->addMonth()]);
        LoyaltyPoint::query()->create(['user_id' => $u->id, 'tenant_id' => $master->id, 'points' => -30, 'reason' => 'redeem']);
        app(LoyaltyService::class)->expire();
        $this->assertSame(50, app(LoyaltyService::class)->balance($u));
        app(LoyaltyService::class)->expire();
        $this->assertSame(50, app(LoyaltyService::class)->balance($u));
    }

    public function test_fare_alert_emails_on_a_drop_and_stops_from_the_link(): void
    {
        $master = $this->createMasterTenant();
        $u = $this->createUserForTenant($master, User::ROLE_CUSTOMER, ['email' => 'fly@example.com']);
        Sanctum::actingAs($u);
        $this->postJson('/api/fare-alerts', ['origin' => 'MAA', 'destination' => 'MAA', 'depart_date' => now()->addDays(30)->toDateString()])->assertStatus(422);
        $id = $this->postJson('/api/fare-alerts', ['origin' => 'maa', 'destination' => 'del', 'depart_date' => now()->addDays(30)->toDateString(), 'current_price' => 6000])->assertCreated()->json('data.id');

        $prices = [5900, 5000];
        $this->partialMock(FareAlertService::class, function ($m) use (&$prices) {
            $m->shouldReceive('lowestFare')->andReturnUsing(function () use (&$prices) {
                return array_shift($prices);
            });
        });
        $this->artisan('fares:check-alerts')->expectsOutput('Sent 0 fare alerts.');
        FareAlert::query()->update(['last_checked_at' => now()->subHours(6)]);
        $this->artisan('fares:check-alerts')->expectsOutput('Sent 1 fare alerts.');
        $log = NotificationLog::query()->where('template_key', 'fare_drop')->first();
        $this->assertSame('fly@example.com', $log->recipient);

        $token = FareAlert::query()->findOrFail($id)->token;
        $this->get('/api/fare-alerts/stop/'.$token)->assertOk()->assertSee('Fare alert stopped');
        $this->assertCount(0, $this->getJson('/api/fare-alerts')->json('data'));
    }

    public function test_marketing_tags_are_validated_ids_and_reach_the_storefront(): void
    {
        $master = $this->createMasterTenant();
        $partner = $this->createChildTenant($master, Tenant::TYPE_WHITE_LABEL);
        Sanctum::actingAs($this->createUserForTenant($master, User::ROLE_SUPER_ADMIN));
        $this->putJson('/api/marketing-tags', ['ga4_id' => '<script>alert(1)</script>'])->assertStatus(422);
        $this->putJson('/api/marketing-tags', ['ga4_id' => 'G-ABC123XYZ', 'meta_pixel_id' => '123456789012345'])->assertOk();

        Sanctum::actingAs($this->createUserForTenant($partner, User::ROLE_PARTNER_ADMIN));
        $this->putJson('/api/marketing-tags', ['tenant_id' => $master->id, 'ga4_id' => 'G-HIJACK1'])->assertForbidden();
        $this->putJson('/api/marketing-tags', ['gtm_id' => 'GTM-PART123'])->assertOk();
        $this->assertSame('GTM-PART123', $partner->fresh()->config['marketing']['gtm_id']);
        $this->assertSame('G-ABC123XYZ', \App\Http\Controllers\Api\MarketingTagsController::forSite($master->fresh())['ga4_id']);
        $this->assertSame(['gtm_id' => 'GTM-PART123'], \App\Http\Controllers\Api\MarketingTagsController::forSite($partner->fresh()));
    }
}
