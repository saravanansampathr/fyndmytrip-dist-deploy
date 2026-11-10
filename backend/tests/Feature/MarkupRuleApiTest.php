<?php

namespace Tests\Feature;

use App\Domains\Booking\Models\Booking;
use App\Domains\Suppliers\Models\MarkupRule;
use App\Domains\Suppliers\Models\Supplier;
use App\Domains\Suppliers\Models\TenantSupplierConfig;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Wallet\Models\WalletLedgerEntry;
use App\Domains\Wallet\Services\WalletService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * HTTP surface of the margin CRM (/api/markup-rules) — who may manage it,
 * targeting a chosen user / account / service, and that a targeted rule is
 * what a real booking is actually priced with.
 */
class MarkupRuleApiTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function payload(array $overrides = []): array
    {
        return array_merge([
            'module' => 'flight',
            'rule_type' => 'percentage',
            'applies_to_role' => 'agent',
            'percentage' => 5,
        ], $overrides);
    }

    public function test_direct_customer_cannot_manage_or_view_margins(): void
    {
        $master = $this->createMasterTenant();
        Sanctum::actingAs($this->createUserForTenant($master, User::ROLE_CUSTOMER), ['*']);

        $this->postJson('/api/markup-rules', $this->payload())->assertForbidden();
        $this->getJson('/api/markup-rules')->assertForbidden();
        $this->assertSame(0, MarkupRule::withoutTenantScope()->count());
    }

    public function test_sub_agent_is_read_only(): void
    {
        $master = $this->createMasterTenant();
        $agent = $this->createChildTenant($master, Tenant::TYPE_AGENT);
        Sanctum::actingAs($this->createUserForTenant($agent, User::ROLE_SUB_AGENT), ['*']);

        $this->getJson('/api/markup-rules')->assertOk();
        $this->getJson('/api/markup-rules/options')->assertOk()->assertJsonPath('data.can_manage', false);
        $this->postJson('/api/markup-rules', $this->payload())->assertForbidden();
    }

    public function test_every_managing_role_can_create_a_rule(): void
    {
        $master = $this->createMasterTenant();
        $cases = [
            [$master, User::ROLE_SUPER_ADMIN],
            [$master, User::ROLE_STAFF],
            [$this->createChildTenant($master, Tenant::TYPE_WHITE_LABEL), User::ROLE_PARTNER_ADMIN],
            [$this->createChildTenant($master, Tenant::TYPE_AGENT), User::ROLE_AGENT],
            [$this->createChildTenant($master, Tenant::TYPE_API_PARTNER), User::ROLE_API_USER],
        ];

        foreach ($cases as [$tenant, $role]) {
            Sanctum::actingAs($this->createUserForTenant($tenant, $role), ['*']);
            $this->postJson('/api/markup-rules', $this->payload(['applies_to_role' => 'b2c']))
                ->assertCreated()
                ->assertJsonPath('data.tenant_id', $tenant->id);
        }
    }

    public function test_admin_targets_a_chosen_user_and_service_for_every_audience(): void
    {
        $master = $this->createMasterTenant();
        $agent = $this->createChildTenant($master, Tenant::TYPE_AGENT);
        $agentUser = $this->createUserForTenant($agent, User::ROLE_AGENT);
        Sanctum::actingAs($this->createUserForTenant($master, User::ROLE_SUPER_ADMIN), ['*']);

        foreach (MarkupRule::ROLES as $audience) {
            $this->postJson('/api/markup-rules', $this->payload(['applies_to_role' => $audience]))->assertCreated();
        }

        $this->postJson('/api/markup-rules', $this->payload([
            'module' => 'all',
            'user_id' => $agentUser->id,
            'target_tenant_id' => $agent->id,
            'rule_type' => 'flat_per_pax',
            'amount' => 120,
            'percentage' => null,
        ]))->assertCreated()
            ->assertJsonPath('data.user.id', $agentUser->id)
            ->assertJsonPath('data.target_tenant.id', $agent->id)
            ->assertJsonPath('data.module', 'all');

        $this->getJson('/api/markup-rules?user_id='.$agentUser->id)->assertOk()->assertJsonCount(1, 'data');

        $options = $this->getJson('/api/markup-rules/options')->assertOk();
        $this->assertCount(count(MarkupRule::ROLES), $options->json('data.audiences'));
        $this->assertContains('all', collect($options->json('data.modules'))->pluck('value')->all());
        $this->assertContains($agentUser->id, collect($options->json('data.users'))->pluck('id')->all());
        $this->assertTrue($options->json('data.can_choose_owner'));
    }

    public function test_agent_cannot_target_users_or_accounts_outside_its_hierarchy(): void
    {
        $master = $this->createMasterTenant();
        $agentA = $this->createChildTenant($master, Tenant::TYPE_AGENT);
        $agentB = $this->createChildTenant($master, Tenant::TYPE_AGENT);
        $foreignUser = $this->createUserForTenant($agentB, User::ROLE_CUSTOMER);
        $ownSub = $this->createUserForTenant($agentA, User::ROLE_SUB_AGENT);

        Sanctum::actingAs($this->createUserForTenant($agentA, User::ROLE_AGENT), ['*']);

        $this->postJson('/api/markup-rules', $this->payload(['user_id' => $foreignUser->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('user_id');
        $this->postJson('/api/markup-rules', $this->payload(['target_tenant_id' => $agentB->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('target_tenant_id');
        // tenant_id from a non-staff caller is ignored — always its own tenant.
        $this->postJson('/api/markup-rules', $this->payload(['tenant_id' => $master->id, 'applies_to_role' => 'sub_agent', 'user_id' => $ownSub->id]))
            ->assertCreated()->assertJsonPath('data.tenant_id', $agentA->id);

        $options = $this->getJson('/api/markup-rules/options')->assertOk();
        $userIds = collect($options->json('data.users'))->pluck('id')->all();
        $this->assertContains($ownSub->id, $userIds);
        $this->assertNotContains($foreignUser->id, $userIds);

        $this->postJson('/api/markup-rules/preview', ['module' => 'flight', 'net_amount' => 1000, 'user_id' => $foreignUser->id])
            ->assertForbidden();
    }

    public function test_preview_reports_the_rule_a_chosen_user_gets(): void
    {
        $master = $this->createMasterTenant();
        $agent = $this->createChildTenant($master, Tenant::TYPE_AGENT);
        $agentUser = $this->createUserForTenant($agent, User::ROLE_AGENT);
        Sanctum::actingAs($this->createUserForTenant($master, User::ROLE_STAFF), ['*']);

        $this->postJson('/api/markup-rules', $this->payload(['percentage' => 5]))->assertCreated();
        $this->postJson('/api/markup-rules', $this->payload(['percentage' => 2, 'user_id' => $agentUser->id]))->assertCreated();

        $this->postJson('/api/markup-rules/preview', ['module' => 'flight', 'net_amount' => 10000, 'user_id' => $agentUser->id])
            ->assertOk()
            ->assertJsonPath('data.markup_amount', '200.00')
            ->assertJsonPath('data.gross_amount', '10200.00')
            ->assertJsonPath('data.audience', 'agent')
            ->assertJsonPath('data.rule.user_id', $agentUser->id);

        $this->postJson('/api/markup-rules/preview', ['module' => 'flight', 'net_amount' => 10000, 'tenant_id' => $agent->id])
            ->assertOk()->assertJsonPath('data.markup_amount', '0.00');
    }

    public function test_user_targeted_rule_prices_a_real_flight_booking(): void
    {
        $master = $this->createMasterTenant();
        $agent = $this->createChildTenant($master, Tenant::TYPE_AGENT);
        $vip = $this->createUserForTenant($agent, User::ROLE_AGENT);
        $regular = $this->createUserForTenant($agent, User::ROLE_AGENT);

        $supplier = Supplier::create([
            'code' => 'MOCKAIR',
            'name' => 'Mock Flight Supplier',
            'supported_modules' => ['flight'],
            'adapter_class' => \App\Domains\Suppliers\Adapters\MockFlightSupplierAdapter::class,
        ]);
        foreach ([$master, $agent] as $t) {
            TenantSupplierConfig::create([
                'tenant_id' => $t->id, 'supplier_id' => $supplier->id,
                'credentials_encrypted' => ['api_key' => 'test'], 'is_active' => true, 'priority_order' => 1,
            ]);
        }

        Sanctum::actingAs($this->createUserForTenant($master, User::ROLE_SUPER_ADMIN), ['*']);
        $this->postJson('/api/markup-rules', $this->payload(['percentage' => 10]))->assertCreated();
        $this->postJson('/api/markup-rules', $this->payload([
            'rule_type' => 'flat_per_pnr', 'amount' => 99, 'percentage' => null, 'user_id' => $vip->id,
        ]))->assertCreated();

        $book = function (User $user) {
            app(WalletService::class)->credit($this->walletFor($user), '50000.00', WalletLedgerEntry::REF_TOPUP);
            Sanctum::actingAs($user, ['*']);
            $date = now()->addDays(10)->toDateString();
            $offer = $this->postJson('/api/flights/search', ['origin' => 'DEL', 'destination' => 'BOM', 'departure_date' => $date])
                ->assertOk()->json('data.legs.0.offers.0');

            $id = $this->postJson('/api/flights/bookings', [
                'legs' => [['supplier_code' => $offer['supplier_code'], 'external_id' => $offer['external_id'], 'segments' => $offer['segments']]],
                'travel_date' => $date,
                'passengers' => [['first_name' => 'A', 'last_name' => 'B']],
            ])->assertCreated()->json('data.id');

            return Booking::withoutTenantScope()->findOrFail($id);
        };

        $vipBooking = $book($vip);
        $this->assertSame('99.00', (string) $vipBooking->markup_amount);

        $regularBooking = $book($regular);
        $expected = (string) \Brick\Math\BigDecimal::of((string) $regularBooking->supplier_net_amount)
            ->multipliedBy('0.10')->toScale(2, \Brick\Math\RoundingMode::HALF_UP);
        $this->assertSame($expected, (string) $regularBooking->markup_amount);
    }
}
