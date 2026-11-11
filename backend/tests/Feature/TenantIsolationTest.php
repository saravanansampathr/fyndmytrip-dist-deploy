<?php

namespace Tests\Feature;

use App\Domains\Booking\Models\Booking;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Verifies the core promise of the Database Design Document's multi-tenancy
 * model: row-level tenant_id scoping via the global TenantScope actually
 * stops one tenant from seeing another's data through the API, not just
 * that the column exists.
 */
class TenantIsolationTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    public function test_an_agents_bookings_are_invisible_to_a_different_agent(): void
    {
        $master = $this->createMasterTenant();

        $agentA = $this->createChildTenant($master, Tenant::TYPE_AGENT, ['name' => 'Agent A']);
        $agentB = $this->createChildTenant($master, Tenant::TYPE_AGENT, ['name' => 'Agent B']);

        $userA = $this->createUserForTenant($agentA, User::ROLE_AGENT);
        $userB = $this->createUserForTenant($agentB, User::ROLE_AGENT);

        $bookingA = Booking::create([
            'tenant_id' => $agentA->id,
            'created_by_user_id' => $userA->id,
            'module' => Booking::MODULE_FLIGHT,
            'supplier_code' => 'MOCKAIR',
            'gross_amount' => '5000.00',
            'supplier_net_amount' => '4700.00',
            'travel_date_start' => now()->addWeek()->toDateString(),
        ]);

        Booking::create([
            'tenant_id' => $agentB->id,
            'created_by_user_id' => $userB->id,
            'module' => Booking::MODULE_FLIGHT,
            'supplier_code' => 'MOCKAIR',
            'gross_amount' => '3000.00',
            'supplier_net_amount' => '2800.00',
            'travel_date_start' => now()->addWeek()->toDateString(),
        ]);

        Sanctum::actingAs($userA, ['*']);

        $response = $this->getJson('/api/flights/bookings');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();

        $this->assertContains($bookingA->id, $ids);
        $this->assertCount(1, $ids, 'Agent A should only see their own tenant\'s bookings.');

        // Directly requesting Agent B's booking by ID must 404, not 403 —
        // the global scope hides it from the query entirely rather than
        // leaking its existence.
        $bookingB = Booking::withoutTenantScope()->where('tenant_id', $agentB->id)->firstOrFail();
        $this->getJson("/api/flights/bookings/{$bookingB->id}")->assertNotFound();
    }

    public function test_super_admin_can_see_across_every_tenant(): void
    {
        $master = $this->createMasterTenant();
        $agentA = $this->createChildTenant($master, Tenant::TYPE_AGENT, ['name' => 'Agent A']);
        $agentB = $this->createChildTenant($master, Tenant::TYPE_AGENT, ['name' => 'Agent B']);

        $userA = $this->createUserForTenant($agentA, User::ROLE_AGENT);
        $userB = $this->createUserForTenant($agentB, User::ROLE_AGENT);

        Booking::create([
            'tenant_id' => $agentA->id, 'created_by_user_id' => $userA->id, 'module' => Booking::MODULE_FLIGHT,
            'supplier_code' => 'MOCKAIR', 'gross_amount' => '1000.00', 'supplier_net_amount' => '900.00',
            'travel_date_start' => now()->addWeek()->toDateString(),
        ]);
        Booking::create([
            'tenant_id' => $agentB->id, 'created_by_user_id' => $userB->id, 'module' => Booking::MODULE_FLIGHT,
            'supplier_code' => 'MOCKAIR', 'gross_amount' => '2000.00', 'supplier_net_amount' => '1900.00',
            'travel_date_start' => now()->addWeek()->toDateString(),
        ]);

        $superAdmin = $this->createUserForTenant($master, User::ROLE_SUPER_ADMIN, ['email' => 'root@fyndmytrip.com']);
        Sanctum::actingAs($superAdmin, ['*']);

        $response = $this->getJson('/api/flights/bookings');

        $response->assertOk();
        $this->assertCount(2, $response->json('data'), 'Super Admin (bypassed tenant scope) should see every tenant\'s bookings.');
    }
}
