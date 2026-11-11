<?php

namespace Tests\Feature;

use App\Domains\Booking\Models\Booking;
use App\Domains\Commission\Models\CommissionCredit;
use App\Domains\Commission\Models\CommissionRule;
use App\Domains\Commission\Services\CommissionService;
use App\Domains\Settings\Services\SettingsService;
use App\Domains\Settings\Services\TaxService;
use App\Domains\Suppliers\Models\MarkupRule;
use App\Domains\Suppliers\Services\MarkupService;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/** Seasonal rules, TDS on commission, business reports and tax returns data. */
class Phase2ReportsTest extends TestCase
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
        $by = User::query()->where('tenant_id', $tenant->id)->first() ?? $this->createUserForTenant($tenant, $tenant->type === Tenant::TYPE_MASTER ? User::ROLE_CUSTOMER : User::ROLE_AGENT);

        return Booking::withoutTenantScope()->create(array_merge([
            'tenant_id' => $tenant->id, 'created_by_user_id' => $by->id, 'module' => 'flight', 'status' => Booking::STATUS_TICKETED, 'channel' => Booking::CHANNEL_AGENT,
            'supplier_code' => 'MOCKAIR', 'gross_amount' => '10000.00', 'supplier_net_amount' => '9000.00', 'markup_amount' => '1000.00',
            'commission_amount' => '0.00', 'currency' => 'INR', 'total_amount' => '10000.00', 'passenger_count' => 1,
            'contact_email' => 'a@example.com', 'travel_date_start' => now()->addDays(20)->toDateString(),
        ], $attrs));
    }

    public function test_margin_rules_only_apply_between_their_dates(): void
    {
        $master = $this->createMasterTenant();
        $base = ['tenant_id' => $master->id, 'module' => 'flight', 'rule_type' => MarkupRule::TYPE_PERCENTAGE, 'applies_to_role' => MarkupRule::ROLE_ALL, 'is_active' => true];
        MarkupRule::withoutTenantScope()->create($base + ['name' => 'Base', 'config' => ['percentage' => '5.00']]);
        MarkupRule::withoutTenantScope()->create($base + ['name' => 'Diwali', 'config' => ['percentage' => '1.00'], 'supplier_code' => 'MOCKAIR', 'valid_from' => now()->addDays(5), 'valid_until' => now()->addDays(10)]);

        $svc = app(MarkupService::class);
        $this->assertSame('Base', $svc->resolve($master, 'flight', MarkupRule::ROLE_B2C, '1000.00', ['supplier_code' => 'MOCKAIR'])['rule']?->name);
        $this->travel(6)->days();
        $this->assertSame('Diwali', $svc->resolve($master, 'flight', MarkupRule::ROLE_B2C, '1000.00', ['supplier_code' => 'MOCKAIR'])['rule']?->name);
        $this->travel(10)->days();
        $this->assertSame('Base', $svc->resolve($master, 'flight', MarkupRule::ROLE_B2C, '1000.00', ['supplier_code' => 'MOCKAIR'])['rule']?->name);
    }

    public function test_tds_is_deducted_from_commission_once_the_year_passes_the_threshold(): void
    {
        $master = $this->createMasterTenant();
        $agency = $this->createChildTenant($master, Tenant::TYPE_AGENT, ['pan' => 'ABCDE1234F']);
        CommissionRule::withoutTenantScope()->create(['tenant_id' => $master->id, 'name' => 'Flat', 'rule_type' => CommissionRule::TYPE_FLAT_PER_PNR,
            'applies_to_type' => Tenant::TYPE_AGENT, 'config' => ['amount' => '6000.00'], 'booking_scope' => CommissionRule::SCOPE_BOTH, 'is_active' => true]);
        app(SettingsService::class)->put('tax', ['tds' => ['enabled' => true, 'rate' => 2, 'threshold' => 10000]]);
        $svc = app(CommissionService::class);

        $svc->applyCommission($this->booking($agency, ['markup_amount' => '8000.00', 'total_amount' => '17000.00']));
        $first = CommissionCredit::query()->latest('id')->first();
        $this->assertSame('0.00', (string) $first->tds_amount, 'below the yearly threshold');
        $this->assertSame('6000.00', (string) $first->amount);

        $svc->applyCommission($this->booking($agency, ['markup_amount' => '8000.00', 'total_amount' => '17000.00']));
        $second = CommissionCredit::query()->latest('id')->first();
        // Year total 12,000 passes 10,000: 2% on this 6,000 and the earlier 6,000.
        $this->assertSame('240.00', (string) $second->tds_amount);
        $this->assertSame('5760.00', (string) $second->amount);
        $this->assertSame('ABCDE1234F', $second->pan);

        Sanctum::actingAs($this->createUserForTenant($master, User::ROLE_SUPER_ADMIN), ['*']);
        $fy = TaxService::financialYear(now());
        $q = (int) ceil(((now()->month + 8) % 12 + 1) / 3);
        $tds = $this->getJson("/api/business-reports/tds?financial_year={$fy}&quarter={$q}")->assertOk();
        $this->assertEquals(240, $tds->json('totals.tds'));
        $this->assertStringContainsString('Tds rate', $this->get("/api/business-reports/tds?financial_year={$fy}&quarter={$q}&format=csv")->getContent());
    }

    public function test_profit_and_sales_reports_group_and_scope(): void
    {
        $master = $this->createMasterTenant();
        $agency = $this->createChildTenant($master, Tenant::TYPE_AGENT, ['name' => 'Sky Travels']);
        $other = $this->createChildTenant($master, Tenant::TYPE_AGENT, ['name' => 'Other']);
        $this->booking($agency);
        $this->booking($agency, ['module' => 'hotel', 'supplier_code' => 'MOCKHOTEL']);
        $this->booking($other, ['status' => Booking::STATUS_CANCELLED]);

        Sanctum::actingAs($this->createUserForTenant($master, User::ROLE_SUPER_ADMIN), ['*']);
        $p = $this->getJson('/api/business-reports/profit')->assertOk();
        $this->assertSame(2, $p->json('totals.bookings'));
        $this->assertEquals(2000, $p->json('totals.margin'));
        $this->assertEquals(18000, $p->json('totals.supplier_cost'));
        $g = $this->getJson('/api/business-reports/profit?group_by=agent')->assertOk();
        $this->assertSame('Sky Travels', $g->json('data.0.group'));
        $this->assertSame(1, $this->getJson('/api/business-reports/cancellations')->json('totals.bookings'));

        $agent = $this->createUserForTenant($agency, User::ROLE_AGENT);
        Sanctum::actingAs($agent, ['*']);
        $this->getJson('/api/business-reports/profit')->assertForbidden();
        $s = $this->getJson('/api/business-reports/sales?group_by=service')->assertOk();
        $this->assertSame(2, $s->json('totals.bookings'), 'agents only see their own bookings');
        $this->getJson("/api/business-reports/sales?tenant_id={$other->id}")->assertForbidden();
    }

    public function test_gstr1_splits_b2b_b2c_and_credit_notes(): void
    {
        $master = $this->createMasterTenant();
        $master->update(['business_registration_no' => '33ABCDE1234F1Z5']);
        $b2b = $this->createChildTenant($master, Tenant::TYPE_AGENT, ['business_registration_no' => '29AAACB1234C1Z2']);
        $tax = app(TaxService::class);
        $tax->invoiceFor($this->booking($b2b));
        $b2c = $this->booking($master, ['channel' => Booking::CHANNEL_DIRECT]);
        $tax->invoiceFor($b2c);
        $tax->creditNoteFor($b2c, 5000, 'Cancelled');

        Sanctum::actingAs($this->createUserForTenant($master, User::ROLE_SUPER_ADMIN), ['*']);
        $r = $this->getJson('/api/business-reports/gstr1?month='.now()->format('Y-m'))->assertOk();
        $this->assertCount(1, $r->json('data.b2b'));
        $this->assertSame('29AAACB1234C1Z2', $r->json('data.b2b.0.buyer_gstin'));
        $this->assertGreaterThan(0, $r->json('data.b2b.0.igst'), 'Karnataka buyer, Tamil Nadu seller: IGST');
        $this->assertSame('33-Tamil Nadu', $r->json('data.b2cs.0.place_of_supply'));
        $this->assertGreaterThan(0, $r->json('data.b2cs.0.cgst'));
        $this->assertCount(1, $r->json('data.cdnur'));
        $this->assertStringContainsString('Buyer gstin', $this->get('/api/business-reports/gstr1?month='.now()->format('Y-m').'&format=csv&section=b2b')->getContent());
    }
}
