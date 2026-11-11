<?php

namespace Tests\Feature;

use App\Domains\Booking\Models\Booking;
use App\Domains\Holiday\Models\HolidayPackage;
use App\Domains\Notifications\Models\NotificationGatewayConfig;
use App\Domains\Notifications\Models\NotificationLog;
use App\Domains\Payments\Models\PaymentGatewayConfig;
use App\Domains\Payments\Models\PaymentTransaction;
use App\Domains\Settings\Models\InvoiceDocument;
use App\Domains\Settings\Services\SettingsService;
use App\Domains\Settings\Services\TaxService;
use App\Domains\Auth\Services\TwoFactorService;
use App\Domains\Wallet\Models\WalletLedgerEntry;
use App\Domains\Wallet\Services\WalletService;
use App\Models\User;
use Database\Seeders\HolidaySamplePackageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;
use App\Domains\Payments\Services\GatewayClient as GatewayClientAlias;

/**
 * Phase 1 launch blockers: login security, tax settings and GST document
 * series, TCS, message templates and providers, real gateway checks,
 * failed-booking queue, website pages and health.
 */
class Phase1LaunchTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['booking.fx_fixed_rates' => 'USD=0.012']);
    }

    protected function admin(): User
    {
        $master = $this->createMasterTenant();

        return $this->createUserForTenant($master, User::ROLE_SUPER_ADMIN);
    }

    /** Holds and pays (wallet) for a domestic package; returns the booking. */
    protected function paidPackage(User $customer, string $slug = 'goa-beach-escape-north-south-goa', array $extra = []): Booking
    {
        Sanctum::actingAs($customer, ['*']);
        $hold = $this->postJson("/api/packages/{$slug}/bookings", [
            'travel_date' => now()->addDays(40)->toDateString(),
            'travelers' => [['name' => 'Asha Rao', 'occupancy_type' => 'adult_sharing']],
            'contact_email' => 'asha@example.com',
            'contact_phone' => '9876543210',
        ] + $extra)->assertCreated();
        $id = $hold->json('data.id');
        $total = Booking::withoutTenantScope()->find($id)->recalculateTotal();
        app(WalletService::class)->credit($this->walletFor($customer), '500000.00', WalletLedgerEntry::REF_TOPUP);
        $this->postJson("/api/holiday-bookings/{$id}/confirm", ['wallet_amount' => $total])->assertOk();

        return Booking::withoutTenantScope()->findOrFail($id);
    }

    /* ---------- login security ---------- */

    public function test_login_locks_after_too_many_wrong_passwords(): void
    {
        $admin = $this->admin();
        app(SettingsService::class)->put('security', ['login_max_attempts' => 3]);

        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/auth/login', ['email' => $admin->email, 'password' => 'wrong'])->assertStatus(422);
        }
        $this->postJson('/api/auth/login', ['email' => $admin->email, 'password' => 'password'])->assertStatus(429);
    }

    public function test_tokens_expire_by_role(): void
    {
        $admin = $this->admin();
        app(SettingsService::class)->put('security', ['staff_session_hours' => 8]);
        $this->postJson('/api/auth/login', ['email' => $admin->email, 'password' => 'password'])->assertOk();
        $token = $admin->tokens()->latest('id')->first();
        $this->assertEqualsWithDelta(now()->addHours(8)->timestamp, $token->expires_at->timestamp, 5);
    }

    public function test_two_factor_set_up_and_login(): void
    {
        $admin = $this->admin();
        Sanctum::actingAs($admin, ['*']);
        $setup = $this->postJson('/api/auth/two-factor/setup')->assertOk()->json('data');
        $this->assertStringContainsString('<svg', $setup['qr_svg']);
        $tf = app(TwoFactorService::class);
        $this->postJson('/api/auth/two-factor/confirm', ['code' => '000000'])->assertStatus(422);
        $codes = $this->postJson('/api/auth/two-factor/confirm', ['code' => $tf->code($setup['secret'])])->assertOk()->json('data.recovery_codes');
        $this->assertCount(8, $codes);

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/auth/login', ['email' => $admin->email, 'password' => 'password'])
            ->assertStatus(422)->assertJsonPath('two_factor_required', true);
        $this->postJson('/api/auth/login', ['email' => $admin->email, 'password' => 'password', 'two_factor_code' => $tf->code($setup['secret'])])
            ->assertOk()->assertJsonStructure(['token']);
        // A recovery code works once.
        $this->postJson('/api/auth/login', ['email' => $admin->email, 'password' => 'password', 'two_factor_code' => $codes[0]])->assertOk();
        $this->postJson('/api/auth/login', ['email' => $admin->email, 'password' => 'password', 'two_factor_code' => $codes[0]])->assertStatus(422);
    }

    /* ---------- tax, invoices, credit notes, TCS ---------- */

    public function test_paid_booking_gets_a_numbered_invoice_using_admin_tax_settings(): void
    {
        $admin = $this->admin();
        $this->seed(HolidaySamplePackageSeeder::class);
        Sanctum::actingAs($admin, ['*']);
        $this->putJson('/api/admin/settings/tax', ['gst_rates' => ['holiday' => 5], 'invoice_prefix' => 'abc'])->assertOk()
            ->assertJsonPath('data.gst_rates.holiday', 5)->assertJsonPath('data.invoice_prefix', 'ABC');

        $customer = $this->createUserForTenant($admin->tenant, User::ROLE_CUSTOMER);
        $one = $this->paidPackage($customer);
        $two = $this->paidPackage($customer);

        $fy = substr(TaxService::financialYear(now()), 2, 2).substr(TaxService::financialYear(now()), -2);
        $inv1 = InvoiceDocument::query()->where('booking_id', $one->id)->firstOrFail();
        $inv2 = InvoiceDocument::query()->where('booking_id', $two->id)->firstOrFail();
        $this->assertSame("ABC{$fy}-000001", $inv1->number);
        $this->assertSame("ABC{$fy}-000002", $inv2->number);
        $this->assertSame(5.0, (float) $inv1->details['gst_rate']);
        $markup = (float) $one->markup_amount;
        $this->assertEqualsWithDelta($markup - $markup / 1.05, (float) $inv1->tax_amount, 0.02);

        $html = null;
        \Illuminate\Support\Facades\View::composer('pdf.invoice', function ($view) use (&$html) {
            if ($html === null) {
                $html = '';
                $html = view('pdf.invoice', $view->getData())->render();
            }
        });
        $this->get("/api/bookings/{$one->id}/invoice.pdf")->assertOk();
        $this->assertStringContainsString("ABC{$fy}-000001", (string) $html);
        // A direct customer's invoice folds our fee and its GST into Base
        // Fare (user's rule, 2026-11-11); the GST is still recorded above.
        $this->assertStringNotContainsString('(2.5%)', (string) $html);

        Sanctum::actingAs($admin, ['*']);
        $csv = $this->get('/api/admin/gst-register?format=csv')->assertOk()->getContent();
        $this->assertStringContainsString("ABC{$fy}-000002", $csv);
    }

    public function test_refund_issues_a_credit_note(): void
    {
        $admin = $this->admin();
        $this->seed(HolidaySamplePackageSeeder::class);
        $customer = $this->createUserForTenant($admin->tenant, User::ROLE_CUSTOMER);
        $booking = $this->paidPackage($customer);

        Sanctum::actingAs($admin, ['*']);
        app(\App\Domains\Booking\Services\BookingStatusService::class)->transition($booking->fresh(), Booking::STATUS_CANCELLED, $admin, 'Customer cancelled');
        app(\App\Domains\Booking\Services\RefundService::class)->initiate($booking->fresh(), $admin, 'wallet', '1000.00');

        $note = InvoiceDocument::query()->where('booking_id', $booking->id)->where('type', InvoiceDocument::TYPE_CREDIT_NOTE)->firstOrFail();
        $this->assertSame(1000.0, (float) $note->amount);
        $this->assertStringStartsWith('FCN', $note->number);
        $this->get("/api/bookings/{$booking->id}/invoice.pdf?document=credit_note")->assertOk();
    }

    public function test_tcs_is_added_to_overseas_packages_when_switched_on(): void
    {
        $admin = $this->admin();
        $this->seed(HolidaySamplePackageSeeder::class);
        app(SettingsService::class)->put('tax', ['tcs' => ['enabled' => true, 'threshold' => 1000000, 'rate_below' => 5, 'rate_above' => 20]]);
        $slug = HolidayPackage::query()->withoutGlobalScopes()->where('booking_scope', HolidayPackage::SCOPE_INTERNATIONAL)->value('slug');
        $customer = $this->createUserForTenant($admin->tenant, User::ROLE_CUSTOMER);
        Sanctum::actingAs($customer, ['*']);

        $quote = $this->postJson("/api/packages/{$slug}/quote", ['travelers' => [['occupancy_type' => 'adult_sharing']], 'pan' => 'ABCDE1234F'])->assertOk()->json('data');
        $this->assertGreaterThan(0, (float) $quote['tcs_amount']);

        $booking = $this->paidPackage($customer, $slug, ['pan' => 'ABCDE1234F']);
        $tcs = (float) $booking->metadata['tcs_amount'];
        $this->assertEqualsWithDelta((float) $booking->gross_amount * 0.05, $tcs, 0.02);
        $this->assertEqualsWithDelta((float) $booking->gross_amount + $tcs, (float) $booking->total_amount, 0.02);
        $this->assertEqualsWithDelta($tcs, (float) InvoiceDocument::query()->where('booking_id', $booking->id)->value('tcs_amount'), 0.01);

        // Without a PAN the higher rate applies.
        $noPan = $this->paidPackage($customer, $slug);
        $this->assertEqualsWithDelta((float) $noPan->gross_amount * 0.20, (float) $noPan->metadata['tcs_amount'], 0.02);
    }

    /* ---------- messages ---------- */

    public function test_confirmed_booking_sends_edited_email_and_sms_through_providers(): void
    {
        Mail::fake();
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM123'], 201)]);
        $admin = $this->admin();
        $this->seed(HolidaySamplePackageSeeder::class);
        NotificationGatewayConfig::create(['tenant_id' => null, 'channel' => 'sms', 'provider' => 'twilio', 'credentials_encrypted' => ['account_sid' => 'AC1', 'auth_token' => 't', 'from_number' => '+15550001'], 'is_active' => true]);

        Sanctum::actingAs($admin, ['*']);
        $this->putJson('/api/admin/notification-templates', [
            'event' => 'booking_confirmed', 'channel' => 'sms', 'body' => 'Booked {{booking_code}} for {{customer_name}}', 'is_active' => true,
        ])->assertOk();
        $list = $this->getJson('/api/admin/notification-templates')->assertOk()->json('data.events');
        $this->assertSame('edited', collect($list)->firstWhere('event', 'booking_confirmed')['channels']['sms']['source']);

        $customer = $this->createUserForTenant($admin->tenant, User::ROLE_CUSTOMER);
        $booking = $this->paidPackage($customer);

        Http::assertSent(fn ($r) => str_contains($r->url(), 'api.twilio.com') && $r['To'] === '+919876543210'
            && $r['Body'] === 'Booked '.$booking->booking_code.' for Asha Rao');
        $logs = NotificationLog::withoutTenantScope()->where('booking_id', $booking->id)->get();
        $this->assertSame(['email', 'sms'], $logs->pluck('channel')->sort()->values()->all());
        $this->assertTrue($logs->every(fn ($l) => $l->status === 'sent'));
    }

    public function test_provider_errors_are_logged_not_thrown(): void
    {
        Mail::fake();
        Http::fake(['api.twilio.com/*' => Http::response(['message' => 'bad number'], 400)]);
        $admin = $this->admin();
        NotificationGatewayConfig::create(['tenant_id' => null, 'channel' => 'whatsapp', 'provider' => 'twilio', 'credentials_encrypted' => ['account_sid' => 'AC1', 'auth_token' => 't', 'from_number' => '+15550001'], 'is_active' => true]);
        Sanctum::actingAs($admin, ['*']);

        $this->postJson('/api/admin/notification-templates/test', ['event' => 'booking_confirmed', 'channel' => 'whatsapp', 'to' => '9876543210'])
            ->assertOk()->assertJsonPath('data.status', 'failed');
        $this->assertStringContainsString('bad number', $this->getJson('/api/admin/notification-logs')->json('data.0.error'));
    }

    /* ---------- real gateway ---------- */

    protected function razorpayHold(User $admin): array
    {
        $this->seed(HolidaySamplePackageSeeder::class);
        PaymentGatewayConfig::create(['tenant_id' => null, 'gateway' => 'razorpay', 'credentials_encrypted' => ['key_id' => 'rzp_test_abc', 'key_secret' => 'secret', 'webhook_secret' => 'whsec'], 'is_active' => true, 'priority' => 1]);
        $customer = $this->createUserForTenant($admin->tenant, User::ROLE_CUSTOMER);
        Sanctum::actingAs($customer, ['*']);
        $id = $this->postJson('/api/packages/goa-beach-escape-north-south-goa/bookings', [
            'travel_date' => now()->addDays(40)->toDateString(),
            'travelers' => [['name' => 'Asha Rao', 'occupancy_type' => 'adult_sharing']],
        ])->assertCreated()->json('data.id');
        $this->postJson("/api/holiday-bookings/{$id}/confirm", ['wallet_amount' => 0])->assertOk();

        return [$id, $customer];
    }

    public function test_razorpay_payment_must_be_verified_before_the_booking_is_confirmed(): void
    {
        $admin = $this->admin();
        [$id] = $this->razorpayHold($admin);
        Http::fake([
            'api.razorpay.com/v1/orders' => Http::response(['id' => 'order_1'], 200),
            'api.razorpay.com/v1/payments/pay_1' => Http::response(['id' => 'pay_1', 'order_id' => 'order_1', 'amount' => 99999999, 'status' => 'captured', 'currency' => 'INR'], 200),
        ]);

        // Skipping the payment page is refused.
        $this->postJson("/api/holiday-bookings/{$id}/pay", ['method' => 'upi'])->assertStatus(402);

        $checkout = $this->postJson('/api/payments/checkout', ['booking_id' => $id, 'method' => 'upi'])->assertOk()->json('data');
        $this->assertSame('razorpay', $checkout['mode']);
        $this->assertSame('order_1', $checkout['order_id']);

        $this->postJson("/api/holiday-bookings/{$id}/pay", ['method' => 'upi', 'razorpay_payment_id' => 'pay_1', 'razorpay_signature' => 'forged'])->assertStatus(402);
        $sig = hash_hmac('sha256', 'order_1|pay_1', 'secret');
        $this->postJson("/api/holiday-bookings/{$id}/pay", ['method' => 'upi', 'razorpay_payment_id' => 'pay_1', 'razorpay_signature' => $sig])->assertOk();

        $txn = PaymentTransaction::withoutTenantScope()->where('booking_id', $id)->firstOrFail();
        $this->assertSame('success', $txn->status);
        $this->assertSame('pay_1', $txn->gateway_payment_id);
        $this->assertSame(Booking::STATUS_CONFIRMED, Booking::withoutTenantScope()->find($id)->status);
    }

    public function test_webhook_is_signature_checked_and_abandoned_payments_reach_the_failed_queue(): void
    {
        Mail::fake();
        $admin = $this->admin();
        [$id] = $this->razorpayHold($admin);
        Http::fake([
            'api.razorpay.com/v1/orders' => Http::response(['id' => 'order_9'], 200),
            'api.razorpay.com/v1/payments/*/refund' => Http::response(['id' => 'rfnd_1'], 200),
        ]);
        $this->postJson('/api/payments/checkout', ['booking_id' => $id, 'method' => 'credit_card'])->assertOk();

        $payload = json_encode(['event' => 'payment.captured', 'payload' => ['payment' => ['entity' => ['id' => 'pay_9', 'order_id' => 'order_9']]]]);
        $this->call('POST', '/api/payments/webhooks/razorpay', [], [], [], ['HTTP_X-Razorpay-Signature' => 'nope', 'CONTENT_TYPE' => 'application/json'], $payload)->assertStatus(400);
        $this->call('POST', '/api/payments/webhooks/razorpay', [], [], [], ['HTTP_X-Razorpay-Signature' => hash_hmac('sha256', $payload, 'whsec'), 'CONTENT_TYPE' => 'application/json'], $payload)->assertOk();

        PaymentTransaction::withoutTenantScope()->where('booking_id', $id)->update(['updated_at' => now()->subMinutes(20)]);
        Sanctum::actingAs($admin, ['*']);
        $row = collect($this->getJson('/api/admin/failed-bookings')->assertOk()->json('data'))->firstWhere('id', $id);
        $this->assertSame('abandoned', $row['kind']);

        $res = $this->postJson("/api/admin/failed-bookings/{$id}/refund");
        $this->assertSame(200, $res->status(), $res->getContent());
        $res->assertJsonPath('data.method', 'gateway');
        Http::assertSent(fn ($r) => str_contains($r->url(), '/payments/pay_9/refund'));
        $this->assertSame(Booking::STATUS_REFUNDED, Booking::withoutTenantScope()->find($id)->status);
    }

    public function test_staff_can_issue_a_failed_paid_booking_by_hand(): void
    {
        Mail::fake();
        $admin = $this->admin();
        $this->seed(HolidaySamplePackageSeeder::class);
        $customer = $this->createUserForTenant($admin->tenant, User::ROLE_CUSTOMER);
        $booking = $this->paidPackage($customer);
        // Simulate a supplier failure after payment.
        Booking::withoutTenantScope()->whereKey($booking->id)->update(['status' => Booking::STATUS_FAILED]);

        Sanctum::actingAs($admin, ['*']);
        $this->assertSame('failed', collect($this->getJson('/api/admin/failed-bookings')->json('data'))->firstWhere('id', $booking->id)['kind']);
        $this->postJson("/api/admin/failed-bookings/{$booking->id}/issue", ['ticket_numbers' => ['VCH-1']])->assertOk();
        $fresh = Booking::withoutTenantScope()->find($booking->id);
        $this->assertSame(Booking::STATUS_CONFIRMED, $fresh->status);
        $this->assertSame(['VCH-1'], $fresh->metadata['ticket_numbers']);
    }

    /* ---------- pages, health, access ---------- */

    public function test_pages_are_hidden_until_published_and_scripts_are_stripped(): void
    {
        $admin = $this->admin();
        $this->getJson('/api/pages/privacy-policy')->assertNotFound();
        Sanctum::actingAs($admin, ['*']);
        $this->assertTrue(collect($this->getJson('/api/admin/pages')->json('data'))->contains('slug', 'refund-policy'));
        $this->putJson('/api/admin/pages/privacy-policy', [
            'title' => 'Privacy', 'body' => '<p onclick="x()">Hi</p><script>alert(1)</script>', 'is_published' => true,
        ])->assertOk();
        $page = $this->getJson('/api/pages/privacy-policy')->assertOk()->json('data');
        $this->assertSame('<p>Hi</p>', $page['body']);
    }

    public function test_health_and_admin_only_settings(): void
    {
        $admin = $this->admin();
        $this->getJson('/api/health')->assertOk()->assertJsonPath('checks.database.ok', true);

        $agent = $this->createUserForTenant($this->createChildTenant($admin->tenant, 'agent'), User::ROLE_AGENT);
        Sanctum::actingAs($agent, ['*']);
        $this->getJson('/api/admin/settings')->assertForbidden();
        $this->putJson('/api/admin/settings/tax', ['gst_rates' => ['flight' => 0]])->assertForbidden();
        $this->getJson('/api/admin/failed-bookings')->assertForbidden();

        Sanctum::actingAs($admin, ['*']);
        $this->getJson('/api/admin/system-status')->assertOk()->assertJsonStructure(['data' => ['status', 'checks' => ['scheduler', 'backup']]]);
        $this->putJson('/api/admin/settings/tax', ['gst_rates' => ['flight' => 40]])->assertStatus(422);
    }

    public function test_stripe_checkout_charges_in_the_customers_currency_and_is_verified(): void
    {
        $admin = $this->admin();
        $this->seed(HolidaySamplePackageSeeder::class);
        PaymentGatewayConfig::create(['tenant_id' => null, 'gateway' => 'stripe', 'credentials_encrypted' => ['publishable_key' => 'pk_test_1', 'secret_key' => 'sk_test_1'], 'is_active' => true, 'priority' => 1]);
        \App\Domains\Payments\Models\PaymentMethodRoute::create(['tenant_id' => null, 'payment_method' => 'international_card', 'gateway' => 'stripe', 'is_active' => true, 'priority' => 1]);
        $customer = $this->createUserForTenant($admin->tenant, User::ROLE_CUSTOMER);
        Sanctum::actingAs($customer, ['*']);
        $id = $this->postJson('/api/packages/goa-beach-escape-north-south-goa/bookings', [
            'travel_date' => now()->addDays(40)->toDateString(),
            'travelers' => [['name' => 'Asha Rao', 'occupancy_type' => 'adult_sharing']],
        ])->assertCreated()->json('data.id');
        $this->postJson("/api/holiday-bookings/{$id}/confirm", ['wallet_amount' => 0])->assertOk();

        Http::fake(['api.stripe.com/v1/checkout/sessions' => Http::response(['id' => 'cs_1', 'url' => 'https://checkout.stripe.com/c/cs_1'], 200)]);
        $checkout = $this->postJson('/api/payments/checkout', ['booking_id' => $id, 'method' => 'international_card', 'currency' => 'USD'])->assertOk()->json('data');
        $this->assertSame('stripe', $checkout['mode']);
        $this->assertSame('USD', $checkout['currency']);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'checkout/sessions') && $r['line_items'][0]['price_data']['currency'] === 'usd');

        $txn = PaymentTransaction::withoutTenantScope()->where('booking_id', $id)->firstOrFail();
        Http::fake(['api.stripe.com/v1/checkout/sessions/cs_1' => Http::sequence()
            ->push(['payment_status' => 'unpaid', 'amount_total' => 0])
            ->push(['payment_status' => 'paid', 'amount_total' => GatewayClientAlias::minorUnits((float) $txn->charge_amount, 'USD'), 'payment_intent' => 'pi_1'])]);
        $this->postJson("/api/holiday-bookings/{$id}/pay", ['method' => 'international_card', 'currency' => 'USD'])->assertStatus(402);
        $this->postJson("/api/holiday-bookings/{$id}/pay", ['method' => 'international_card', 'currency' => 'USD'])->assertOk();
        $this->assertSame('pi_1', $txn->fresh()->gateway_payment_id);
    }

    public function test_backup_and_reconcile_commands_run(): void
    {
        Mail::fake();
        $this->admin();
        $db = tempnam(sys_get_temp_dir(), 'fmtdb');
        config(['database.connections.sqlite_backup_probe' => ['driver' => 'sqlite', 'database' => $db]]);
        config(['database.connections.sqlite.database' => $db]);
        $this->artisan('backup:run', ['--no-files' => true])->assertExitCode(0);
        $this->assertTrue(app(\App\Domains\Settings\Services\SystemStatus::class)->report()['checks']['backup']['ok']);
        $this->artisan('payments:reconcile')->assertExitCode(0);
    }
}
