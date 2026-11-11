<?php

namespace Tests\Feature;

use App\Domains\Booking\Models\Booking;
use App\Domains\Booking\Services\BookingStatusService;
use App\Domains\Corporate\Models\TripRequest;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/** Corporate travel: policy, trip requests and approvals, monthly statement. */
class Phase3CorporateTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Mail::fake();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_company_policy_approval_and_statement(): void
    {
        $master = $this->createMasterTenant();
        $company = $this->createChildTenant($master, Tenant::TYPE_AGENT, ['name' => 'Acme Pvt Ltd']);
        $owner = $this->createUserForTenant($company, User::ROLE_AGENT, ['email' => 'boss@acme.test']);
        $employee = $this->createUserForTenant($company, User::ROLE_STAFF_USER, ['email' => 'emp@acme.test']);

        Sanctum::actingAs($employee);
        $this->postJson('/api/corporate/trip-requests', [])->assertForbidden();

        // Only staff/partner can switch it on; then the owner sets policy.
        Sanctum::actingAs($owner);
        $this->putJson("/api/corporate/companies/{$company->id}", ['enabled' => true])->assertForbidden();
        Sanctum::actingAs($this->createUserForTenant($master, User::ROLE_SUPER_ADMIN));
        $this->putJson("/api/corporate/companies/{$company->id}", ['enabled' => true])->assertOk()->assertJsonPath('data.enabled', true);
        Sanctum::actingAs($owner);
        $this->putJson("/api/corporate/companies/{$company->id}", ['policy' => ['flight_max_amount' => 10000, 'min_advance_days' => 3, 'auto_approve_below' => 5000]])->assertOk();

        Sanctum::actingAs($employee);
        $trip = ['travellers' => 'Emp One', 'module' => 'flight', 'origin' => 'MAA', 'destination' => 'BOM', 'depart_date' => now()->addDays(10)->toDateString(), 'purpose' => 'Client meeting', 'cost_center' => 'SALES'];
        $auto = $this->postJson('/api/corporate/trip-requests', $trip + ['estimated_amount' => 4000])->assertCreated();
        $this->assertSame('approved', $auto->json('data.status'));
        $big = $this->postJson('/api/corporate/trip-requests', $trip + ['estimated_amount' => 12000, 'cabin_class' => 'business'])->assertCreated();
        $this->assertSame('pending', $big->json('data.status'));
        $this->assertCount(2, $big->json('data.policy_issues'));
        $this->postJson('/api/corporate/trip-requests/'.$big->json('data.id').'/approve')->assertForbidden();
        $this->assertCount(2, $this->getJson('/api/corporate/trip-requests')->json('data'));

        Sanctum::actingAs($owner);
        $this->postJson('/api/corporate/trip-requests/'.$big->json('data.id').'/reject')->assertStatus(422);
        $this->postJson('/api/corporate/trip-requests/'.$big->json('data.id').'/reject', ['note' => 'Too costly'])->assertOk();
        $this->assertSame('rejected', TripRequest::query()->find($big->json('data.id'))->status);

        // Book the approved trip offline, link it, and see it on the statement.
        Sanctum::actingAs($employee);
        $this->postJson('/api/offline-bookings', ['module' => 'flight', 'supplier_name' => 'IndiGo', 'travel_date_start' => now()->addDays(10)->toDateString(), 'selling_amount' => 4000, 'supplier_amount' => 3700, 'travellers' => ['Emp One']])->assertForbidden();
        Sanctum::actingAs($owner);
        $code = $this->postJson('/api/offline-bookings', ['module' => 'flight', 'supplier_name' => 'IndiGo', 'travel_date_start' => now()->addDays(10)->toDateString(), 'selling_amount' => 4000, 'supplier_amount' => 3700, 'travellers' => ['Emp One']])->assertCreated()->json('data.booking_code');
        $this->postJson('/api/corporate/trip-requests/'.$auto->json('data.id').'/link', ['booking_code' => $code])->assertOk();

        $st = $this->getJson("/api/corporate/companies/{$company->id}/statement?month=".now()->format('Y-m'))->assertOk();
        $this->assertSame(4000.0, (float) $st->json('data.total'));
        $this->assertSame('SALES', $st->json('data.rows.0.cost_center'));
        $this->get("/api/corporate/companies/{$company->id}/statement?format=csv")->assertOk()->assertSee('Cost centre');

        // Another company cannot read it.
        Sanctum::actingAs($this->createUserForTenant($this->createChildTenant($master, Tenant::TYPE_AGENT), User::ROLE_AGENT));
        $this->getJson("/api/corporate/companies/{$company->id}/statement")->assertForbidden();
    }
}
