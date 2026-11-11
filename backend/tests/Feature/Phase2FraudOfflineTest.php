<?php

namespace Tests\Feature;

use App\Domains\Booking\Models\Booking;
use App\Domains\Booking\Services\BookingStatusService;
use App\Domains\Reporting\Models\FraudFlag;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/** Fraud scoring on paid bookings, and bookings made outside the website. */
class Phase2FraudOfflineTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Mail::fake();
        $this->seed(RolePermissionSeeder::class);
    }

    protected function booking(Tenant $tenant, User $by, array $attrs = []): Booking
    {
        return Booking::withoutTenantScope()->create(array_merge([
            'tenant_id' => $tenant->id, 'created_by_user_id' => $by->id, 'module' => 'flight', 'status' => Booking::STATUS_HELD,
            'channel' => Booking::CHANNEL_DIRECT, 'supplier_code' => 'MOCKAIR', 'gross_amount' => '5000.00', 'supplier_net_amount' => '4500.00',
            'markup_amount' => '500.00', 'commission_amount' => '0.00', 'currency' => 'INR', 'total_amount' => '5000.00', 'passenger_count' => 1,
            'contact_email' => 'a@example.com', 'travel_date_start' => now()->addDays(20)->toDateString(),
        ], $attrs));
    }

    public function test_risky_paid_booking_is_flagged_and_normal_one_is_not(): void
    {
        $master = $this->createMasterTenant();
        $fresh = $this->createUserForTenant($master, User::ROLE_CUSTOMER);
        $svc = app(BookingStatusService::class);

        $ok = $this->booking($master, $fresh);
        $svc->transition($ok, Booking::STATUS_CONFIRMED);
        $this->assertSame(0, FraudFlag::query()->count());
        $this->assertSame(0, $ok->fresh()->metadata['fraud_score']);

        $risky = $this->booking($master, $fresh, ['total_amount' => '250000.00', 'gross_amount' => '250000.00', 'contact_email' => 'x@mailinator.com']);
        $svc->transition($risky, Booking::STATUS_CONFIRMED);
        $flag = FraudFlag::query()->firstOrFail();
        $this->assertSame($risky->id, $flag->subject_id);
        $this->assertGreaterThanOrEqual(50, (float) $flag->risk_score);
        $this->assertStringContainsString('Throw-away email', $flag->reason);

        // Checked once only.
        $svc->transition($risky->fresh(), Booking::STATUS_TICKETED);
        $this->assertSame(1, FraudFlag::query()->count());

        Sanctum::actingAs($this->createUserForTenant($master, User::ROLE_SUPER_ADMIN));
        $this->assertSame($risky->booking_code, $this->getJson('/api/admin/fraud-flags')->json('data.0.booking.booking_code'));
        $this->putJson('/api/admin/fraud-flags/'.$flag->id, ['status' => 'cleared'])->assertOk();
        $this->assertCount(0, $this->getJson('/api/admin/fraud-flags')->json('data'));
    }

    public function test_fraud_list_is_staff_only(): void
    {
        $agency = $this->createChildTenant($this->createMasterTenant(), Tenant::TYPE_AGENT);
        Sanctum::actingAs($this->createUserForTenant($agency, User::ROLE_AGENT));
        $this->getJson('/api/admin/fraud-flags')->assertForbidden();
    }

    public function test_agent_enters_an_offline_booking_with_invoice_and_no_customer_message(): void
    {
        $master = $this->createMasterTenant();
        $agency = $this->createChildTenant($master, Tenant::TYPE_AGENT);
        $other = $this->createChildTenant($master, Tenant::TYPE_AGENT);
        Sanctum::actingAs($this->createUserForTenant($agency, User::ROLE_AGENT));

        $this->postJson('/api/offline-bookings', ['module' => 'flight', 'supplier_name' => 'IndiGo', 'travel_date_start' => now()->addDays(5)->toDateString(), 'selling_amount' => 10000, 'travellers' => ['Ravi Kumar'], 'tenant_id' => $other->id])->assertForbidden();
        $r = $this->postJson('/api/offline-bookings', [
            'module' => 'flight', 'supplier_name' => 'IndiGo', 'supplier_ref' => 'ABC123', 'travel_date_start' => now()->addDays(5)->toDateString(),
            'selling_amount' => 10000, 'supplier_amount' => 9000, 'travellers' => ['Ravi Kumar', 'Anita Kumar'], 'contact_email' => 'ravi@example.com', 'payment_mode' => 'upi',
        ])->assertCreated();
        $b = Booking::withoutTenantScope()->findOrFail($r->json('data.id'));
        $this->assertSame(Booking::STATUS_TICKETED, $b->status);
        $this->assertSame('OFFLINE', $b->supplier_code);
        $this->assertSame('1000.00', $b->markup_amount);
        $this->assertSame(2, $b->passengers()->count());
        $this->assertTrue($b->metadata['offline']);
        $this->assertTrue(\App\Domains\Settings\Models\InvoiceDocument::query()->where('booking_id', $b->id)->exists());
        $this->assertSame(0, FraudFlag::query()->count());
    }

    public function test_staff_import_offline_bookings_from_csv(): void
    {
        $master = $this->createMasterTenant();
        $agency = $this->createChildTenant($master, Tenant::TYPE_AGENT);
        Sanctum::actingAs($this->createUserForTenant($master, User::ROLE_SUPER_ADMIN));
        $this->get('/api/offline-bookings/template')->assertOk()->assertSee('supplier_name');

        $d = now()->addDays(7)->toDateString();
        $csv = "account_id,service,supplier_name,supplier_ref,travel_date,return_date,travellers,contact_email,contact_phone,supplier_amount,selling_amount,currency,payment_mode,status,international,notes\n"
            ."{$agency->id},hotel,Taj,HX1,{$d},,Meena R,m@example.com,,4000,5000,INR,bank,confirmed,no,\n"
            ."{$agency->id},spaceship,X,,{$d},,A,,,1,2,INR,bank,ticketed,no,\n"
            .",flight,Air India,AI9,{$d},,Joe;Ann,,,8000,9000,INR,cash,ticketed,yes,phone\n";
        $file = UploadedFile::fake()->createWithContent('b.csv', $csv);
        $r = $this->post('/api/offline-bookings/import', ['file' => $file], ['Accept' => 'application/json'])->assertOk();
        $this->assertSame('2 of 3 rows saved.', $r->json('message'));
        $this->assertFalse($r->json('data.1.ok'));
        $this->assertSame(2, Booking::withoutTenantScope()->where('supplier_code', 'OFFLINE')->count());
        $this->assertSame(Booking::STATUS_CONFIRMED, Booking::withoutTenantScope()->where('tenant_id', $agency->id)->value('status'));
    }
}
