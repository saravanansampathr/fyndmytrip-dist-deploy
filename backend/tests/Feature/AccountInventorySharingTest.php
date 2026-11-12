<?php

namespace Tests\Feature;

use App\Domains\Inventory\Models\BusInventoryItem;
use App\Domains\Suppliers\Models\Supplier;
use App\Domains\Suppliers\Models\TenantSupplierConfig;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * FyndMyTrip is the master account. Its inventories reach the accounts
 * below it (white-label partners, agents, API partners) only where they
 * are switched on, and the admin assigns and monitors this in one place.
 */
class AccountInventorySharingTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected Tenant $master;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->master = $this->createMasterTenant();
        $air = Supplier::create(['code' => 'MOCKAIR', 'name' => 'Mock Flight Supplier', 'supported_modules' => ['flight'], 'adapter_class' => \App\Domains\Suppliers\Adapters\MockFlightSupplierAdapter::class]);
        TenantSupplierConfig::create(['tenant_id' => $this->master->id, 'supplier_id' => $air->id, 'credentials_encrypted' => ['api_key' => 'x'], 'is_active' => true]);
        $bus = Supplier::create(['code' => 'MOCKBUS', 'name' => 'Mock Bus Supplier', 'supported_modules' => ['bus'], 'adapter_class' => \App\Domains\Suppliers\Adapters\MockBusSupplierAdapter::class]);
        TenantSupplierConfig::create(['tenant_id' => $this->master->id, 'supplier_id' => $bus->id, 'credentials_encrypted' => ['api_key' => 'x'], 'is_active' => true]);
    }

    protected function flightOffers(User $user): array
    {
        Sanctum::actingAs($user, ['*']);

        return $this->postJson('/api/flights/search', [
            'origin' => 'DEL', 'destination' => 'BOM', 'departure_date' => now()->addDays(10)->toDateString(),
        ])->assertOk()->json('data.legs.0.offers') ?? [];
    }

    public function test_master_switches_an_inventory_off_for_one_agent_only(): void
    {
        $agentA = $this->createChildTenant($this->master, Tenant::TYPE_AGENT, ['name' => 'Agent A']);
        $agentB = $this->createChildTenant($this->master, Tenant::TYPE_AGENT, ['name' => 'Agent B']);
        $userA = $this->createUserForTenant($agentA, User::ROLE_AGENT);
        $userB = $this->createUserForTenant($agentB, User::ROLE_AGENT);

        // Default: every account gets the master's inventories.
        $offer = $this->flightOffers($userA)[0] ?? null;
        $this->assertNotNull($offer);

        $admin = $this->createUserForTenant($this->master, User::ROLE_SUPER_ADMIN);
        Sanctum::actingAs($admin);
        $this->putJson("/api/admin/accounts/{$agentA->id}", ['inventories' => ['MOCKAIR' => false]])
            ->assertOk()->assertJsonPath('data.inventories.MOCKAIR.state', 'off')->assertJsonPath('data.inventories.MOCKAIR.source', 'set');

        $this->assertSame([], $this->flightOffers($userA));
        $this->assertNotEmpty($this->flightOffers($userB));

        // A held fare from before the switch cannot be booked either.
        Sanctum::actingAs($userA, ['*']);
        $this->postJson('/api/flights/bookings', [
            'legs' => [['supplier_code' => $offer['supplier_code'], 'external_id' => $offer['external_id'], 'segments' => $offer['segments']]],
            'travel_date' => now()->addDays(10)->toDateString(),
            'passengers' => [['first_name' => 'Jane', 'last_name' => 'Traveller', 'gender' => 'female']],
        ])->assertForbidden()->assertJsonPath('message', 'This inventory is not available for your account.');

        // Back to the default.
        Sanctum::actingAs($admin);
        $this->putJson("/api/admin/accounts/{$agentA->id}", ['inventories' => ['MOCKAIR' => null]])
            ->assertOk()->assertJsonPath('data.inventories.MOCKAIR.state', 'on')->assertJsonPath('data.inventories.MOCKAIR.source', 'default');
        $this->assertNotEmpty($this->flightOffers($userA));

        // Every change is in the change history.
        $this->assertDatabaseHas('admin_audit_logs', ['subject_type' => 'TenantInventoryAccess']);
    }

    public function test_default_none_means_nothing_until_assigned_and_bulk_assign_works(): void
    {
        $agent = $this->createChildTenant($this->master, Tenant::TYPE_AGENT);
        $api = $this->createChildTenant($this->master, Tenant::TYPE_API_PARTNER);
        $user = $this->createUserForTenant($agent, User::ROLE_AGENT);
        $admin = $this->createUserForTenant($this->master, User::ROLE_STAFF);

        Sanctum::actingAs($admin);
        $this->putJson('/api/admin/accounts/default-access', ['default_access' => 'none'])->assertOk();
        $this->assertSame([], $this->flightOffers($user));

        Sanctum::actingAs($admin);
        $this->postJson('/api/admin/accounts/bulk', ['tenant_ids' => [$agent->id, $api->id], 'inventories' => ['MOCKAIR' => true]])
            ->assertOk()->assertJsonPath('data.updated', 2);
        $this->assertNotEmpty($this->flightOffers($user));

        Sanctum::actingAs($user);
        $services = $this->getJson('/api/account/services')->assertOk();
        $this->assertSame(['MOCKAIR'], array_column($services->json('data.inventories'), 'key'));
    }

    public function test_white_label_partner_only_passes_on_what_it_has(): void
    {
        $wl = $this->createChildTenant($this->master, Tenant::TYPE_WHITE_LABEL, ['name' => 'Partner Travels', 'custom_domain' => 'partnertravels.example']);
        $wlAgent = $this->createChildTenant($wl, Tenant::TYPE_AGENT, ['name' => 'Partner Agent']);
        $other = $this->createChildTenant($this->master, Tenant::TYPE_AGENT, ['name' => 'Direct Agent']);
        $partnerAdmin = $this->createUserForTenant($wl, User::ROLE_PARTNER_ADMIN);
        $agentUser = $this->createUserForTenant($wlAgent, User::ROLE_AGENT);

        $admin = $this->createUserForTenant($this->master, User::ROLE_SUPER_ADMIN);
        Sanctum::actingAs($admin);
        $this->putJson("/api/admin/accounts/{$wl->id}", ['inventories' => ['MOCKAIR' => false], 'services' => ['flight', 'hotel']])->assertOk();

        // The partner's agent loses it too, even with nothing set on the agent.
        Sanctum::actingAs($admin);
        $this->getJson("/api/admin/accounts/{$wlAgent->id}")->assertOk()
            ->assertJsonPath('data.inventories.MOCKAIR.state', 'off_above')
            ->assertJsonPath('data.inventories.MOCKAIR.allowed', false)
            ->assertJsonPath('data.services', ['flight', 'hotel']);
        $this->assertSame([], $this->flightOffers($agentUser));

        // The partner admin sees only accounts below it and only inventories it has.
        Sanctum::actingAs($partnerAdmin);
        $list = $this->getJson('/api/admin/accounts')->assertOk();
        $this->assertSame(['Partner Agent'], array_column($list->json('data'), 'name'));
        $this->assertNotContains('MOCKAIR', array_column($list->json('meta.catalog'), 'key'));
        $this->putJson("/api/admin/accounts/{$wlAgent->id}", ['inventories' => ['MOCKAIR' => true]])->assertStatus(422);
        $this->putJson("/api/admin/accounts/{$wlAgent->id}", ['inventories' => ['MOCKBUS' => false]])->assertOk();
        $this->putJson("/api/admin/accounts/{$other->id}", ['inventories' => ['MOCKBUS' => false]])->assertForbidden();
        $this->putJson("/api/admin/accounts/{$wl->id}", ['inventories' => ['MOCKBUS' => true]])->assertForbidden();
        $this->putJson('/api/admin/accounts/default-access', ['default_access' => 'none'])->assertForbidden();

        // An agent cannot open the screen at all.
        Sanctum::actingAs($agentUser);
        $this->getJson('/api/admin/accounts')->assertForbidden();
    }

    public function test_own_inventory_can_be_withheld(): void
    {
        $agent = $this->createChildTenant($this->master, Tenant::TYPE_AGENT);
        $user = $this->createUserForTenant($agent, User::ROLE_AGENT);
        $date = now()->addDays(5)->toDateString();
        BusInventoryItem::create([
            'tenant_id' => null, 'title' => 'Chennai-Bengaluru AC Sleeper', 'origin' => 'Chennai', 'destination' => 'Bengaluru',
            'travel_date' => $date, 'departure_time' => '22:00', 'operator_name' => 'Test Operator', 'bus_type' => 'AC Sleeper',
            'boarding_point' => 'Central', 'dropping_point' => 'Depot', 'duration_minutes' => 360, 'is_refundable' => true,
            'capacity' => 2, 'net_price' => '900.00',
        ]);
        $own = function () use ($user, $date) {
            Sanctum::actingAs($user, ['*']);

            return collect($this->postJson('/api/bus/search', ['origin' => 'Chennai', 'destination' => 'Bengaluru', 'departure_date' => $date])
                ->json('data.offers'))->where('supplier_code', BusInventoryItem::SUPPLIER_CODE)->count();
        };

        $this->assertSame(1, $own());
        Sanctum::actingAs($this->createUserForTenant($this->master, User::ROLE_SUPER_ADMIN));
        $this->putJson("/api/admin/accounts/{$agent->id}", ['inventories' => ['own_inventory' => false]])->assertOk();
        $this->assertSame(0, $own());
    }

    public function test_monitoring_list_shows_sales_per_account_and_inventory(): void
    {
        $wl = $this->createChildTenant($this->master, Tenant::TYPE_WHITE_LABEL, ['name' => 'Partner Travels']);
        $agent = $this->createChildTenant($wl, Tenant::TYPE_AGENT, ['name' => 'Partner Agent']);
        $user = $this->createUserForTenant($agent, User::ROLE_AGENT);
        \DB::table('bookings')->insert([
            'booking_reference' => (string) \Illuminate\Support\Str::uuid(), 'tenant_id' => $agent->id, 'created_by_user_id' => $user->id,
            'module' => 'flight', 'supplier_code' => 'MOCKAIR', 'status' => 'ticketed', 'gross_amount' => 5000, 'supplier_net_amount' => 4500,
            'currency' => 'INR', 'travel_date_start' => now()->addDays(3)->toDateString(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        Sanctum::actingAs($this->createUserForTenant($this->master, User::ROLE_SUPER_ADMIN));
        $res = $this->getJson('/api/admin/accounts')->assertOk();
        $rows = collect($res->json('data'))->keyBy('name');
        $this->assertSame(1, $rows['Partner Agent']['bookings']);
        $this->assertEquals(5000, $rows['Partner Agent']['sales']);
        $this->assertEquals(5000, $rows['Partner Travels']['network_sales']);
        $this->assertSame(0, $rows['Partner Travels']['bookings']);
        $usage = collect($res->json('meta.inventory_usage'))->keyBy('key');
        $this->assertSame(1, $usage['MOCKAIR']['bookings']);
        $this->assertSame(2, $usage['MOCKAIR']['accounts_with_access']);

        $this->getJson("/api/admin/accounts/{$agent->id}")->assertOk()->assertJsonPath('data.by_inventory.0.supplier_code', 'MOCKAIR');
        $this->putJson("/api/admin/accounts/{$agent->id}", ['status' => 'suspended'])->assertOk()->assertJsonPath('data.status', 'suspended');
    }
}
