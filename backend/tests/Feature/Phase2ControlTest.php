<?php

namespace Tests\Feature;

use App\Domains\Audit\Models\AuditLog;
use App\Domains\Auth\Models\RolePermission;
use App\Domains\Payments\Services\CurrencyService;
use App\Domains\Settings\Services\SettingsService;
use App\Domains\Suppliers\Models\MarkupRule;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Phase 2 admin control: change history, settings hub (currencies,
 * maintenance, server settings, per-account services), roles and
 * per-user rights.
 */
class Phase2ControlTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['booking.fx_fixed_rates' => 'USD=0.012,EUR=0.011']);
        $this->seed(RolePermissionSeeder::class);
    }

    protected function admin(): User
    {
        return $this->createUserForTenant($this->createMasterTenant(), User::ROLE_SUPER_ADMIN);
    }

    public function test_settings_changes_are_recorded_with_old_and_new_values_and_secrets_hidden(): void
    {
        Sanctum::actingAs($admin = $this->admin(), ['*']);
        $this->putJson('/api/admin/settings/tax', ['invoice_prefix' => 'ABC'])->assertOk();
        $this->putJson('/api/admin/settings/tax', ['invoice_prefix' => 'XYZ'])->assertOk();

        $log = AuditLog::query()->where('subject_type', 'AppSetting')->latest('id')->first();
        $this->assertSame($admin->id, $log->user_id);
        $this->assertSame('updated', $log->action);
        $this->assertSame('ABC', $log->changes['value'][0]['invoice_prefix']);
        $this->assertSame('XYZ', $log->changes['value'][1]['invoice_prefix']);

        \App\Domains\Payments\Models\PaymentGatewayConfig::query()->create([
            'tenant_id' => $admin->tenant_id, 'gateway' => 'razorpay', 'is_active' => true,
            'credentials_encrypted' => ['key_id' => 'rzp_test_x', 'key_secret' => 'supersecret'],
        ]);
        $gw = AuditLog::query()->where('subject_type', 'PaymentGatewayConfig')->first();
        $this->assertStringNotContainsString('supersecret', json_encode($gw->changes));

        $res = $this->getJson('/api/admin/audit-logs?type=AppSetting')->assertOk();
        $this->assertGreaterThanOrEqual(2, $res->json('meta.total'));
        $this->assertSame($admin->email, $res->json('data.0.user.email'));
    }

    public function test_currency_switches_fx_margin_and_country_choice(): void
    {
        Sanctum::actingAs($this->admin(), ['*']);
        $this->putJson('/api/admin/settings/currency', [
            'enabled' => ['INR', 'USD'], 'fx_margin_percent' => 2, 'country_currency' => ['ae' => 'USD'],
        ])->assertOk();

        $rates = $this->getJson('/api/currency-rates?country=AE')->assertOk();
        $this->assertEqualsCanonicalizing(['INR', 'USD'], array_keys($rates->json('data.rates')));
        $this->assertEqualsWithDelta(0.012 * 1.02, $rates->json('data.rates.USD'), 1e-9);
        $this->assertSame('USD', $rates->json('data.suggested'));

        $c = app(CurrencyService::class);
        $this->assertSame('INR', $c->chargeFor('1000', 'EUR')['currency'], 'a switched-off currency falls back to INR');
        $this->assertSame('12.24', $c->chargeFor('1000', 'USD')['amount']);
    }

    public function test_maintenance_mode_blocks_bookings_but_not_staff_or_login(): void
    {
        $master = $this->createMasterTenant();
        $customer = $this->createUserForTenant($master, User::ROLE_CUSTOMER);
        app(SettingsService::class)->put('platform', ['maintenance' => ['enabled' => true, 'message' => 'Back at 6 pm']]);

        Sanctum::actingAs($customer, ['*']);
        $this->postJson('/api/flights/search', ['origin' => 'DEL', 'destination' => 'BOM', 'departure_date' => now()->addDays(9)->toDateString()])
            ->assertStatus(503)->assertJson(['message' => 'Back at 6 pm', 'maintenance' => true]);
        $this->getJson('/api/currency-rates')->assertOk();

        Sanctum::actingAs($this->admin(), ['*']);
        $this->putJson('/api/admin/settings/platform', ['maintenance' => ['enabled' => false]])->assertOk();
        Sanctum::actingAs($customer, ['*']);
        $this->assertNotSame(503, $this->postJson('/api/flights/search', ['origin' => 'DEL', 'destination' => 'BOM', 'departure_date' => now()->addDays(9)->toDateString()])->status());
    }

    public function test_services_switched_off_for_an_account_are_refused_for_it_and_below(): void
    {
        $master = $this->createMasterTenant();
        $partner = $this->createChildTenant($master, Tenant::TYPE_WHITE_LABEL, ['enabled_modules' => ['flight', 'hotel']]);
        $agency = $this->createChildTenant($partner, Tenant::TYPE_AGENT);
        $agent = $this->createUserForTenant($agency, User::ROLE_AGENT);

        Sanctum::actingAs($agent, ['*']);
        $this->postJson('/api/cabs/search', [])->assertStatus(403)->assertJson(['module' => 'cab']);
        $this->assertNotSame(403, $this->postJson('/api/hotels/search', [])->status());
    }

    public function test_server_settings_override_config(): void
    {
        Sanctum::actingAs($this->admin(), ['*']);
        $this->putJson('/api/admin/settings/platform', ['search_cache_seconds' => 90, 'home_country' => 'ae'])->assertOk()
            ->assertJsonPath('data.home_country', 'AE');
        $this->assertSame(90, config('suppliers.search_cache_ttl_seconds'));
        $this->assertSame('AE', config('booking.home_country'));
    }

    public function test_super_admin_edits_role_rights_and_deploy_seeder_keeps_them(): void
    {
        Sanctum::actingAs($this->admin(), ['*']);
        $roles = $this->getJson('/api/admin/roles')->assertOk();
        $this->assertNotEmpty($roles->json('data.catalogue'));

        $this->putJson('/api/admin/roles/sub_agent', ['permissions' => ['booking.view', 'wallet.view']])->assertOk();
        $this->assertEqualsCanonicalizing(['booking.view', 'wallet.view'], RolePermission::query()->where('role_key', 'sub_agent')->pluck('permission_key')->all());

        $this->seed(RolePermissionSeeder::class);
        $this->assertEqualsCanonicalizing(['booking.view', 'wallet.view'], RolePermission::query()->where('role_key', 'sub_agent')->pluck('permission_key')->all());

        $this->postJson('/api/admin/roles/sub_agent/reset')->assertOk();
        $this->assertContains('booking.create', RolePermission::query()->where('role_key', 'sub_agent')->pluck('permission_key')->all());
        $this->assertTrue(AuditLog::query()->where('subject_type', 'RolePermission')->exists());

        $staff = $this->createUserForTenant($this->createMasterTenant(), User::ROLE_STAFF);
        Sanctum::actingAs($staff, ['*']);
        $this->putJson('/api/admin/roles/agent', ['permissions' => []])->assertForbidden();
    }

    public function test_per_user_rights_grant_and_revoke_within_your_own_rights(): void
    {
        $master = $this->createMasterTenant();
        $agency = $this->createChildTenant($master, Tenant::TYPE_AGENT);
        $agent = $this->createUserForTenant($agency, User::ROLE_AGENT);
        $desk = $this->createUserForTenant($agency, User::ROLE_STAFF_USER);
        $this->assertTrue($desk->hasPermission('booking.create'));
        $this->assertFalse($desk->hasPermission('wallet.view'));

        Sanctum::actingAs($agent, ['*']);
        $this->putJson("/api/tenants/{$agency->id}/users/{$desk->id}/rights", ['grant' => ['wallet.view'], 'revoke' => ['booking.create']])->assertOk();
        $desk->refresh();
        $this->assertTrue($desk->hasPermission('wallet.view'));
        $this->assertFalse($desk->hasPermission('booking.create'));

        $this->putJson("/api/tenants/{$agency->id}/users/{$desk->id}/rights", ['grant' => ['supplier.configure'], 'revoke' => []])->assertStatus(422);
    }
}
