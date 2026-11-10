<?php

namespace Tests\Feature;

use App\Domains\Booking\Models\Booking;
use App\Domains\Suppliers\Models\MarkupRule;
use App\Domains\Suppliers\Services\MarkupService;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Margins for every user type (admins, staff, white-label, API partners,
 * agents, sub-agents, direct customers), for a chosen user or account, and
 * for one service or all of them — see MarkupService's docblock.
 */
class MarkupTargetingTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function service(): MarkupService
    {
        return app(MarkupService::class);
    }

    protected function rule(Tenant $owner, array $attrs): MarkupRule
    {
        $value = $attrs['value'] ?? '10';
        unset($attrs['value']);
        $type = $attrs['rule_type'] ?? MarkupRule::TYPE_PERCENTAGE;

        return MarkupRule::withoutTenantScope()->create(array_merge([
            'tenant_id' => $owner->id,
            'module' => Booking::MODULE_FLIGHT,
            'rule_type' => $type,
            'config' => $type === MarkupRule::TYPE_PERCENTAGE ? ['percentage' => $value] : ['amount' => $value],
            'applies_to_role' => MarkupRule::ROLE_B2C,
        ], $attrs));
    }

    protected function price(Tenant $tenant, ?User $user, string $module = Booking::MODULE_FLIGHT, string $net = '1000.00'): string
    {
        $svc = $this->service();

        return $svc->applyMarkup($tenant, $module, $svc->legacyRoleFor($user), $net, ['user' => $user])['markup_amount'];
    }

    public function test_every_user_type_maps_to_its_own_audience(): void
    {
        $svc = $this->service();
        $master = $this->createMasterTenant();

        $expect = [
            User::ROLE_SUPER_ADMIN => MarkupRule::ROLE_ADMIN,
            User::ROLE_STAFF => MarkupRule::ROLE_ADMIN,
            User::ROLE_PARTNER_ADMIN => MarkupRule::ROLE_WHITE_LABEL,
            User::ROLE_AGENT => MarkupRule::ROLE_AGENT,
            User::ROLE_SUB_AGENT => MarkupRule::ROLE_SUB_AGENT,
            User::ROLE_STAFF_USER => MarkupRule::ROLE_STAFF,
            User::ROLE_API_USER => MarkupRule::ROLE_API_PARTNER,
            User::ROLE_CUSTOMER => MarkupRule::ROLE_B2C,
        ];

        foreach ($expect as $role => $audience) {
            $user = $this->createUserForTenant($master, $role);
            $this->assertSame($audience, $svc->audienceFor($user), $role);
        }

        $this->assertSame(MarkupRule::ROLE_B2C, $svc->audienceFor(null));
    }

    public function test_admin_white_label_api_and_staff_audiences_get_their_own_margin(): void
    {
        $master = $this->createMasterTenant();
        $wl = $this->createChildTenant($master, Tenant::TYPE_WHITE_LABEL);
        $api = $this->createChildTenant($master, Tenant::TYPE_API_PARTNER);
        $agent = $this->createChildTenant($master, Tenant::TYPE_AGENT);

        $this->rule($master, ['applies_to_role' => MarkupRule::ROLE_ADMIN, 'value' => '1']);
        $this->rule($master, ['applies_to_role' => MarkupRule::ROLE_WHITE_LABEL, 'value' => '2']);
        $this->rule($master, ['applies_to_role' => MarkupRule::ROLE_API_PARTNER, 'value' => '3']);
        $this->rule($master, ['applies_to_role' => MarkupRule::ROLE_STAFF, 'value' => '4']);
        $this->rule($master, ['applies_to_role' => MarkupRule::ROLE_B2C, 'value' => '9']);

        $this->assertSame('10.00', $this->price($master, $this->createUserForTenant($master, User::ROLE_SUPER_ADMIN)));
        $this->assertSame('10.00', $this->price($master, $this->createUserForTenant($master, User::ROLE_STAFF)));
        $this->assertSame('20.00', $this->price($wl, $this->createUserForTenant($wl, User::ROLE_PARTNER_ADMIN)));
        $this->assertSame('30.00', $this->price($api, $this->createUserForTenant($api, User::ROLE_API_USER)));
        $this->assertSame('40.00', $this->price($agent, $this->createUserForTenant($agent, User::ROLE_STAFF_USER)));
        $this->assertSame('90.00', $this->price($master, $this->createUserForTenant($master, User::ROLE_CUSTOMER)));
        $this->assertSame('90.00', $this->price($master, null), 'Guests price as direct customers.');
    }

    public function test_new_audiences_fall_back_to_legacy_rules_when_none_exist(): void
    {
        $master = $this->createMasterTenant();
        $wl = $this->createChildTenant($master, Tenant::TYPE_WHITE_LABEL);

        // Only the pre-existing 'agent' rule — a white-label partner admin
        // kept getting it before white_label existed, and still must.
        $this->rule($master, ['applies_to_role' => MarkupRule::ROLE_AGENT, 'value' => '5']);

        $this->assertSame('50.00', $this->price($wl, $this->createUserForTenant($wl, User::ROLE_PARTNER_ADMIN)));
    }

    public function test_rule_for_a_chosen_user_beats_the_general_rule(): void
    {
        $master = $this->createMasterTenant();
        $agent = $this->createChildTenant($master, Tenant::TYPE_AGENT);
        $vip = $this->createUserForTenant($agent, User::ROLE_AGENT);
        $other = $this->createUserForTenant($agent, User::ROLE_AGENT);

        $this->rule($master, ['applies_to_role' => MarkupRule::ROLE_AGENT, 'value' => '5']);
        $this->rule($master, ['applies_to_role' => MarkupRule::ROLE_AGENT, 'value' => '2', 'user_id' => $vip->id]);

        $this->assertSame('20.00', $this->price($agent, $vip));
        $this->assertSame('50.00', $this->price($agent, $other));
    }

    public function test_admin_can_set_a_margin_for_themselves(): void
    {
        $master = $this->createMasterTenant();
        $admin = $this->createUserForTenant($master, User::ROLE_SUPER_ADMIN);
        $otherAdmin = $this->createUserForTenant($master, User::ROLE_SUPER_ADMIN);

        $this->rule($master, ['applies_to_role' => MarkupRule::ROLE_ALL, 'value' => '0', 'user_id' => $admin->id]);
        $this->rule($master, ['applies_to_role' => MarkupRule::ROLE_ALL, 'value' => '7']);

        $this->assertSame('0.00', $this->price($master, $admin));
        $this->assertSame('70.00', $this->price($master, $otherAdmin));
    }

    public function test_rule_for_a_chosen_account_only_applies_to_that_account(): void
    {
        $master = $this->createMasterTenant();
        $agentA = $this->createChildTenant($master, Tenant::TYPE_AGENT);
        $agentB = $this->createChildTenant($master, Tenant::TYPE_AGENT);

        $this->rule($master, ['applies_to_role' => MarkupRule::ROLE_AGENT, 'value' => '5']);
        $this->rule($master, ['applies_to_role' => MarkupRule::ROLE_AGENT, 'value' => '8', 'target_tenant_id' => $agentA->id]);

        $this->assertSame('80.00', $this->price($agentA, $this->createUserForTenant($agentA, User::ROLE_AGENT)));
        $this->assertSame('50.00', $this->price($agentB, $this->createUserForTenant($agentB, User::ROLE_AGENT)));
    }

    public function test_all_services_rule_applies_everywhere_but_a_service_rule_wins(): void
    {
        $master = $this->createMasterTenant();
        $customer = $this->createUserForTenant($master, User::ROLE_CUSTOMER);

        $this->rule($master, ['module' => MarkupRule::MODULE_ALL, 'value' => '3']);
        $this->rule($master, ['module' => Booking::MODULE_HOTEL, 'value' => '12']);

        $this->assertSame('30.00', $this->price($master, $customer, Booking::MODULE_FLIGHT));
        $this->assertSame('30.00', $this->price($master, $customer, Booking::MODULE_BUS));
        $this->assertSame('120.00', $this->price($master, $customer, Booking::MODULE_HOTEL));
    }

    public function test_agent_targeting_a_sub_agent_login_is_still_capped_by_master(): void
    {
        $master = $this->createMasterTenant();
        $agent = $this->createChildTenant($master, Tenant::TYPE_AGENT);
        $sub = $this->createUserForTenant($agent, User::ROLE_SUB_AGENT);

        $this->rule($master, ['applies_to_role' => MarkupRule::ROLE_SUB_AGENT, 'value' => '6']);
        $this->rule($agent, ['applies_to_role' => MarkupRule::ROLE_SUB_AGENT, 'value' => '15', 'user_id' => $sub->id]);

        $this->assertSame('60.00', $this->price($agent, $sub));
    }

    public function test_a_tenants_own_specific_rule_may_exceed_its_own_general_rule(): void
    {
        $master = $this->createMasterTenant();
        $agent = $this->createChildTenant($master, Tenant::TYPE_AGENT);
        $customer = $this->createUserForTenant($agent, User::ROLE_CUSTOMER);

        $this->rule($agent, ['value' => '5']);
        $this->rule($agent, ['value' => '9', 'user_id' => $customer->id]);

        $this->assertSame('90.00', $this->price($agent, $customer));
    }

    public function test_flat_rule_for_a_user_on_all_services(): void
    {
        $master = $this->createMasterTenant();
        $api = $this->createChildTenant($master, Tenant::TYPE_API_PARTNER);
        $apiUser = $this->createUserForTenant($api, User::ROLE_API_USER);

        $this->rule($master, [
            'module' => MarkupRule::MODULE_ALL,
            'applies_to_role' => MarkupRule::ROLE_API_PARTNER,
            'rule_type' => MarkupRule::TYPE_FLAT_PER_PNR,
            'value' => '150',
            'user_id' => $apiUser->id,
        ]);

        $this->assertSame('150.00', $this->price($api, $apiUser, Booking::MODULE_HOTEL));
        $this->assertSame('150.00', $this->price($api, $apiUser, Booking::MODULE_CAB));
    }

    public function test_an_audience_rule_on_all_services_beats_a_legacy_service_rule(): void
    {
        $master = $this->createMasterTenant();
        $admin = $this->createUserForTenant($master, User::ROLE_SUPER_ADMIN);
        $customer = $this->createUserForTenant($master, User::ROLE_CUSTOMER);

        // Pre-existing per-service customer rule (what the demo seeders create).
        $this->rule($master, ['module' => Booking::MODULE_FLIGHT, 'applies_to_role' => MarkupRule::ROLE_B2C, 'value' => '6']);
        $this->rule($master, ['module' => MarkupRule::MODULE_ALL, 'applies_to_role' => MarkupRule::ROLE_ADMIN, 'value' => '1']);

        $this->assertSame('10.00', $this->price($master, $admin));
        $this->assertSame('60.00', $this->price($master, $customer));
    }
}
