<?php

namespace Tests\Feature;

use App\Domains\Booking\Models\Booking;
use App\Domains\Booking\Services\BookingStatusService;
use App\Domains\Settings\Services\SettingsService;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Webhooks\Models\WebhookDelivery;
use App\Domains\Webhooks\Services\WebhookDispatcher;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/** Unpaid hold expiry, ticketing alerts, and signed partner webhooks with retries. */
class Phase2JobsWebhooksTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->seed(RolePermissionSeeder::class);
    }

    protected function booking(Tenant $tenant, array $attrs = []): Booking
    {
        $by = User::query()->where('tenant_id', $tenant->id)->first() ?? $this->createUserForTenant($tenant, User::ROLE_AGENT);

        return Booking::withoutTenantScope()->create(array_merge([
            'tenant_id' => $tenant->id, 'created_by_user_id' => $by->id, 'module' => 'flight', 'status' => Booking::STATUS_HELD,
            'channel' => Booking::CHANNEL_AGENT, 'supplier_code' => 'MOCKAIR', 'gross_amount' => '5000.00', 'supplier_net_amount' => '4500.00',
            'markup_amount' => '500.00', 'commission_amount' => '0.00', 'currency' => 'INR', 'total_amount' => '5000.00', 'passenger_count' => 1,
            'contact_email' => 'a@example.com', 'travel_date_start' => now()->addDays(20)->toDateString(),
        ], $attrs));
    }

    public function test_unpaid_holds_are_cancelled_after_the_set_time_but_holidays_are_kept(): void
    {
        $agency = $this->createChildTenant($this->createMasterTenant(), Tenant::TYPE_AGENT);
        app(SettingsService::class)->put('platform', ['hold_expiry_minutes' => 30]);
        $old = $this->booking($agency);
        $fresh = $this->booking($agency);
        $holiday = $this->booking($agency, ['module' => 'holiday']);
        Booking::withoutTenantScope()->whereIn('id', [$old->id, $holiday->id])->update(['created_at' => now()->subHour()]);

        $this->artisan('bookings:expire-holds')->assertSuccessful();
        $this->assertSame(Booking::STATUS_CANCELLED, $old->fresh()->status);
        $this->assertSame(Booking::STATUS_HELD, $fresh->fresh()->status);
        $this->assertSame(Booking::STATUS_HELD, $holiday->fresh()->status);
    }

    public function test_staff_are_alerted_once_about_paid_bookings_not_ticketed_near_travel(): void
    {
        Mail::fake();
        app(SettingsService::class)->put('monitoring', ['alert_emails' => ['ops@example.com']]);
        $agency = $this->createChildTenant($this->createMasterTenant(), Tenant::TYPE_AGENT);
        $b = $this->booking($agency, ['status' => Booking::STATUS_CONFIRMED, 'travel_date_start' => now()->addHours(10)]);
        $this->artisan('bookings:ticketing-alerts')->assertSuccessful();
        $this->artisan('bookings:ticketing-alerts')->assertSuccessful();
        $this->assertNotEmpty($b->fresh()->metadata['ticketing_alerted_at'] ?? null);
        $this->assertSame(1, \App\Domains\Notifications\Models\NotificationLog::query()->where('booking_id', $b->id)->count());
    }

    public function test_partner_gets_signed_webhooks_for_its_sub_accounts_and_failures_are_retried(): void
    {
        $master = $this->createMasterTenant();
        $partner = $this->createChildTenant($master, Tenant::TYPE_WHITE_LABEL);
        $agency = $this->createChildTenant($partner, Tenant::TYPE_AGENT);
        $admin = $this->createUserForTenant($partner, User::ROLE_PARTNER_ADMIN);

        Sanctum::actingAs($admin, ['*']);
        $created = $this->postJson('/api/webhooks', ['url' => 'https://partner.example.com/hook', 'events' => ['booking.cancelled']])->assertCreated();
        $secret = $created->json('data.secret');
        $this->assertStringStartsWith('whsec_', $secret);
        $this->assertArrayNotHasKey('secret', $this->getJson('/api/webhooks')->json('data.0'));

        Http::fake(['partner.example.com/*' => Http::sequence()->push('down', 500)->push('ok', 200)]);
        $b = $this->booking($agency);
        app(BookingStatusService::class)->transition($b, Booking::STATUS_CANCELLED, null, 'test');

        $d = WebhookDelivery::query()->first();
        $this->assertSame('pending', $d->status);
        $this->assertSame(1, $d->attempts);
        Http::assertSent(function (HttpRequest $r) use ($secret) {
            [$ts, $sig] = [$r->header('X-FyndMyTrip-Timestamp')[0], $r->header('X-FyndMyTrip-Signature')[0]];

            return $r['event'] === 'booking.cancelled' && $sig === 'sha256='.hash_hmac('sha256', $ts.'.'.$r->body(), $secret);
        });

        $this->travel(2)->minutes();
        app(WebhookDispatcher::class)->retryDue();
        $this->assertSame('delivered', $d->fresh()->status);
        $this->assertSame(1, $this->getJson('/api/webhooks/'.$created->json('data.id').'/deliveries')->assertOk()->json('data.0.attempts') - 1);

        $outsider = $this->createUserForTenant($this->createChildTenant($master, Tenant::TYPE_AGENT), User::ROLE_AGENT);
        Sanctum::actingAs($outsider, ['*']);
        $this->getJson('/api/webhooks/'.$created->json('data.id').'/deliveries')->assertNotFound();
    }
}
