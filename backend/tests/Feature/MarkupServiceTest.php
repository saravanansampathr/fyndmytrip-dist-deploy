<?php

namespace Tests\Feature;

use App\Domains\Booking\Models\Booking;
use App\Domains\Suppliers\Models\MarkupRule;
use App\Domains\Suppliers\Services\MarkupService;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Covers the four markup rule types the feature request asked for ("fixed
 * per PNR or PAX or Destination or percentage wise") and the ancestor
 * ceiling that stops a child tenant configuring its way past its parent's
 * cap — see MarkupService's docblock for the resolution algorithm.
 */
class MarkupServiceTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function service(): MarkupService
    {
        return app(MarkupService::class);
    }

    public function test_percentage_rule_applies_to_net_amount(): void
    {
        $master = $this->createMasterTenant();
        $agent = $this->createChildTenant($master, Tenant::TYPE_AGENT);

        MarkupRule::withoutTenantScope()->create([
            'tenant_id' => $agent->id,
            'module' => Booking::MODULE_FLIGHT,
            'rule_type' => MarkupRule::TYPE_PERCENTAGE,
            'config' => ['percentage' => '10'],
            'applies_to_role' => MarkupRule::ROLE_B2C,
        ]);

        $result = $this->service()->applyMarkup($agent, Booking::MODULE_FLIGHT, 'b2c', '1000.00');

        $this->assertSame('100.00', $result['markup_amount']);
        $this->assertSame('1100.00', $result['gross_amount']);
    }

    public function test_flat_per_pnr_ignores_passenger_count(): void
    {
        $master = $this->createMasterTenant();
        $agent = $this->createChildTenant($master, Tenant::TYPE_AGENT);

        MarkupRule::withoutTenantScope()->create([
            'tenant_id' => $agent->id,
            'module' => Booking::MODULE_FLIGHT,
            'rule_type' => MarkupRule::TYPE_FLAT_PER_PNR,
            'config' => ['amount' => '150'],
            'applies_to_role' => MarkupRule::ROLE_B2C,
        ]);

        $result = $this->service()->applyMarkup($agent, Booking::MODULE_FLIGHT, 'b2c', '1000.00', ['passenger_count' => 4]);

        $this->assertSame('150.00', $result['markup_amount']);
    }

    public function test_flat_per_pax_multiplies_by_passenger_count(): void
    {
        $master = $this->createMasterTenant();
        $agent = $this->createChildTenant($master, Tenant::TYPE_AGENT);

        MarkupRule::withoutTenantScope()->create([
            'tenant_id' => $agent->id,
            'module' => Booking::MODULE_FLIGHT,
            'rule_type' => MarkupRule::TYPE_FLAT_PER_PAX,
            'config' => ['amount' => '50'],
            'applies_to_role' => MarkupRule::ROLE_B2C,
        ]);

        $result = $this->service()->applyMarkup($agent, Booking::MODULE_FLIGHT, 'b2c', '1000.00', ['passenger_count' => 4]);

        $this->assertSame('200.00', $result['markup_amount']);
    }

    public function test_flat_per_destination_only_matches_configured_route(): void
    {
        $master = $this->createMasterTenant();
        $agent = $this->createChildTenant($master, Tenant::TYPE_AGENT);

        MarkupRule::withoutTenantScope()->create([
            'tenant_id' => $agent->id,
            'module' => Booking::MODULE_FLIGHT,
            'rule_type' => MarkupRule::TYPE_FLAT_PER_DESTINATION,
            'config' => ['amount' => '300'],
            'destination' => 'DXB',
            'applies_to_role' => MarkupRule::ROLE_B2C,
        ]);

        $matching = $this->service()->applyMarkup($agent, Booking::MODULE_FLIGHT, 'b2c', '1000.00', [
            'origin' => 'DEL', 'destination' => 'DXB',
        ]);
        $this->assertSame('300.00', $matching['markup_amount']);

        $nonMatching = $this->service()->applyMarkup($agent, Booking::MODULE_FLIGHT, 'b2c', '1000.00', [
            'origin' => 'DEL', 'destination' => 'BOM',
        ]);
        $this->assertSame('0.00', $nonMatching['markup_amount']);
    }

    public function test_no_rule_anywhere_in_chain_applies_zero_markup(): void
    {
        $master = $this->createMasterTenant();
        $agent = $this->createChildTenant($master, Tenant::TYPE_AGENT);

        $result = $this->service()->applyMarkup($agent, Booking::MODULE_FLIGHT, 'b2c', '1000.00');

        $this->assertSame('0.00', $result['markup_amount']);
        $this->assertSame('1000.00', $result['gross_amount']);
    }

    /**
     * The ceiling rule: Master caps every agent's percentage markup at 10%;
     * a sub-agent under Agent A tries to configure 25% for its own
     * customers, but the resolved value is capped to the lowest same-type
     * value found anywhere in the ancestor chain (10%, Master's), never
     * the sub-agent's own higher number.
     */
    public function test_child_tenant_cannot_exceed_ancestor_percentage_ceiling(): void
    {
        $master = $this->createMasterTenant();
        $agentA = $this->createChildTenant($master, Tenant::TYPE_AGENT);
        $subAgent = $this->createChildTenant($agentA, Tenant::TYPE_SUB_AGENT);

        MarkupRule::withoutTenantScope()->create([
            'tenant_id' => $master->id,
            'module' => Booking::MODULE_FLIGHT,
            'rule_type' => MarkupRule::TYPE_PERCENTAGE,
            'config' => ['percentage' => '10'],
            'applies_to_role' => MarkupRule::ROLE_B2C,
        ]);

        MarkupRule::withoutTenantScope()->create([
            'tenant_id' => $subAgent->id,
            'module' => Booking::MODULE_FLIGHT,
            'rule_type' => MarkupRule::TYPE_PERCENTAGE,
            'config' => ['percentage' => '25'],
            'applies_to_role' => MarkupRule::ROLE_B2C,
        ]);

        $result = $this->service()->applyMarkup($subAgent, Booking::MODULE_FLIGHT, 'b2c', '1000.00');

        // Capped to Master's 10%, not the sub-agent's own configured 25%.
        $this->assertSame('100.00', $result['markup_amount']);
    }

    public function test_inactive_rule_is_ignored(): void
    {
        $master = $this->createMasterTenant();
        $agent = $this->createChildTenant($master, Tenant::TYPE_AGENT);

        MarkupRule::withoutTenantScope()->create([
            'tenant_id' => $agent->id,
            'module' => Booking::MODULE_FLIGHT,
            'rule_type' => MarkupRule::TYPE_PERCENTAGE,
            'config' => ['percentage' => '10'],
            'applies_to_role' => MarkupRule::ROLE_B2C,
            'is_active' => false,
        ]);

        $result = $this->service()->applyMarkup($agent, Booking::MODULE_FLIGHT, 'b2c', '1000.00');

        $this->assertSame('0.00', $result['markup_amount']);
    }

    /**
     * Platform roadmap Phase 6: "Markup should be in like Domestic &
     * International" — a domestic-only rule must never apply to an
     * international search/booking, and vice versa, even though both share
     * the same module + role.
     */
    public function test_booking_scope_domestic_only_rule_does_not_apply_internationally(): void
    {
        $master = $this->createMasterTenant();
        $agent = $this->createChildTenant($master, Tenant::TYPE_AGENT);

        MarkupRule::withoutTenantScope()->create([
            'tenant_id' => $agent->id,
            'module' => Booking::MODULE_FLIGHT,
            'rule_type' => MarkupRule::TYPE_PERCENTAGE,
            'config' => ['percentage' => '10'],
            'applies_to_role' => MarkupRule::ROLE_B2C,
            'booking_scope' => MarkupRule::SCOPE_DOMESTIC,
        ]);

        $domestic = $this->service()->applyMarkup($agent, Booking::MODULE_FLIGHT, 'b2c', '1000.00', [
            'is_international' => false,
        ]);
        $this->assertSame('100.00', $domestic['markup_amount']);

        $international = $this->service()->applyMarkup($agent, Booking::MODULE_FLIGHT, 'b2c', '1000.00', [
            'is_international' => true,
        ]);
        $this->assertSame('0.00', $international['markup_amount']);
    }

    /**
     * "In that all airlines or Difference in specific airlines" — a rule
     * naming a specific airline overrides the tenant's general rule for
     * that airline only; every other airline still gets the general rule.
     */
    public function test_supplier_specific_rule_overrides_general_rule_for_that_supplier_only(): void
    {
        $master = $this->createMasterTenant();
        $agent = $this->createChildTenant($master, Tenant::TYPE_AGENT);

        MarkupRule::withoutTenantScope()->create([
            'tenant_id' => $agent->id,
            'module' => Booking::MODULE_FLIGHT,
            'rule_type' => MarkupRule::TYPE_PERCENTAGE,
            'config' => ['percentage' => '10'],
            'applies_to_role' => MarkupRule::ROLE_B2C,
        ]);

        MarkupRule::withoutTenantScope()->create([
            'tenant_id' => $agent->id,
            'module' => Booking::MODULE_FLIGHT,
            'rule_type' => MarkupRule::TYPE_PERCENTAGE,
            'config' => ['percentage' => '4'],
            'applies_to_role' => MarkupRule::ROLE_B2C,
            'supplier_code' => '6E',
        ]);

        $indigo = $this->service()->applyMarkup($agent, Booking::MODULE_FLIGHT, 'b2c', '1000.00', [
            'supplier_code' => '6e',
        ]);
        $this->assertSame('40.00', $indigo['markup_amount']);

        $otherAirline = $this->service()->applyMarkup($agent, Booking::MODULE_FLIGHT, 'b2c', '1000.00', [
            'supplier_code' => 'AI',
        ]);
        $this->assertSame('100.00', $otherAirline['markup_amount']);
    }
}
