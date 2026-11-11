<?php

namespace Tests\Feature;

use App\Domains\Suppliers\Adapters\AkbarTravelsFlightSupplierAdapter;
use App\Domains\Suppliers\Models\Supplier;
use App\Domains\Suppliers\Models\TenantSupplierConfig;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/** The admin enters supplier API credentials in the CRM (Supplier connections), not in .env. */
class SupplierConnectionCrmTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    public function test_admin_saves_akbar_credentials_without_them_ever_being_sent_back(): void
    {
        $master = $this->createMasterTenant();
        $akbar = Supplier::create(['code' => 'AKBAR', 'name' => 'Akbar Travels', 'supported_modules' => ['flight'], 'adapter_class' => AkbarTravelsFlightSupplierAdapter::class]);
        $admin = $this->createUserForTenant($master, User::ROLE_SUPER_ADMIN);
        Sanctum::actingAs($admin);

        $row = collect($this->getJson('/api/supplier-connections')->assertOk()->json('data'))->firstWhere('code', 'AKBAR');
        $this->assertFalse($row['is_connected']);
        $this->assertSame(['api_key', 'api_secret', 'base_url'], array_column($row['credential_fields'], 'key'));

        $saved = $this->postJson('/api/supplier-connections', [
            'supplier_id' => $akbar->id,
            'credentials' => ['api_key' => 'KEY-123', 'api_secret' => 'SECRET-456', 'base_url' => 'https://api.example.test'],
        ])->assertCreated();
        $this->assertStringNotContainsString('SECRET-456', $saved->getContent());
        $this->assertStringNotContainsString('KEY-123', $saved->getContent());

        $list = $this->getJson('/api/supplier-connections')->assertOk();
        $this->assertStringNotContainsString('SECRET-456', $list->getContent());
        $row = collect($list->json('data'))->firstWhere('code', 'AKBAR');
        $this->assertTrue($row['is_connected']);
        $this->assertEqualsCanonicalizing(['api_key', 'api_secret', 'base_url'], $row['configured_keys']);

        // Switching it off with the secret fields left blank keeps the saved secrets.
        $this->putJson("/api/supplier-connections/{$row['config_id']}", ['is_active' => false, 'credentials' => ['api_key' => '', 'api_secret' => '']])->assertOk();
        $config = TenantSupplierConfig::withoutGlobalScopes()->find($row['config_id']);
        $this->assertFalse($config->is_active);
        $this->assertSame('SECRET-456', $config->credentials_encrypted['api_secret']);

        // Stored encrypted, not in plain text.
        $raw = \DB::table('tenant_supplier_configs')->where('id', $config->id)->value('credentials_encrypted');
        $this->assertStringNotContainsString('SECRET-456', (string) $raw);

        $this->deleteJson("/api/supplier-connections/{$row['config_id']}")->assertNoContent();
        $this->assertFalse(collect($this->getJson('/api/supplier-connections')->json('data'))->firstWhere('code', 'AKBAR')['is_connected']);
    }

    public function test_an_agent_cannot_change_supplier_connections(): void
    {
        $master = $this->createMasterTenant();
        $akbar = Supplier::create(['code' => 'AKBAR', 'name' => 'Akbar Travels', 'supported_modules' => ['flight'], 'adapter_class' => AkbarTravelsFlightSupplierAdapter::class]);
        $agentTenant = $this->createChildTenant($master, Tenant::TYPE_AGENT);
        Sanctum::actingAs($this->createUserForTenant($agentTenant, User::ROLE_AGENT));

        $this->postJson('/api/supplier-connections', ['supplier_id' => $akbar->id, 'credentials' => ['api_key' => 'x']])->assertForbidden();
    }
}
