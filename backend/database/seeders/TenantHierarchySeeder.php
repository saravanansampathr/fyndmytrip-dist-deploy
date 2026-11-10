<?php

namespace Database\Seeders;

use App\Domains\Suppliers\Models\MarkupRule;
use App\Domains\Suppliers\Models\Supplier;
use App\Domains\Suppliers\Models\TenantSupplierConfig;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Services\TenantContext;
use App\Domains\Tenancy\Services\TenantService;
use App\Domains\Wallet\Models\Wallet;
use App\Domains\Wallet\Models\WalletLedgerEntry;
use App\Domains\Wallet\Services\WalletService;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Seeds a full demo tenant hierarchy matching Master Brief §3: Master ->
 * White-Label Partner -> Agent -> Sub-Agent, plus a Direct Agent and an API
 * Partner attached straight to Master, a B2C customer, and the mock flight
 * supplier wired up for every tenant that will search/book during a demo.
 *
 * All demo users share the password "password" (see the README this
 * seeder's console output points to) — change or remove before any
 * non-local deployment.
 */
class TenantHierarchySeeder extends Seeder
{
    public function run(): void
    {
        $tenantService = app(TenantService::class);
        $walletService = app(WalletService::class);

        // Master is a real row (parent_tenant_id NULL) so that
        // users.tenant_id NOT NULL holds even for Super Admin/Staff/B2C —
        // see the note in app/Domains/Tenancy/Models/Tenant.php.
        $master = Tenant::query()->firstOrCreate(
            ['type' => Tenant::TYPE_MASTER],
            ['name' => 'FyndMyTrip (Master)', 'legal_name' => 'Afynd Pvt Ltd', 'status' => Tenant::STATUS_ACTIVE, 'subdomain' => 'www']
        );

        $mockSupplier = Supplier::query()->firstOrCreate(
            ['code' => 'MOCKAIR'],
            [
                'name' => 'Mock Flight Supplier',
                'supported_modules' => ['flight'],
                'adapter_class' => \App\Domains\Suppliers\Adapters\MockFlightSupplierAdapter::class,
            ]
        );

        // Catalog rows only — no TenantSupplierConfig is seeded for these
        // three: a tenant connects one for real from the CRM's Supplier
        // Connections screen (SupplierConnectionController), entering its
        // own API key/credentials rather than having them baked in here.
        Supplier::query()->firstOrCreate(
            ['code' => 'TBO'],
            [
                'name' => 'TBO (Tek Travels)',
                'supported_modules' => ['flight'],
                'adapter_class' => \App\Domains\Suppliers\Adapters\TBOFlightSupplierAdapter::class,
            ]
        );
        Supplier::query()->firstOrCreate(
            ['code' => 'TRIPJACK'],
            [
                'name' => 'TripJack',
                'supported_modules' => ['flight'],
                'adapter_class' => \App\Domains\Suppliers\Adapters\TripJackFlightSupplierAdapter::class,
            ]
        );
        Supplier::query()->firstOrCreate(
            ['code' => 'AKBAR'],
            [
                'name' => 'Akbar Travels',
                'supported_modules' => ['flight'],
                'adapter_class' => \App\Domains\Suppliers\Adapters\AkbarTravelsFlightSupplierAdapter::class,
            ]
        );

        app(TenantContext::class)->set($master)->bypass();

        TenantSupplierConfig::query()->firstOrCreate(
            ['tenant_id' => $master->id, 'supplier_id' => $mockSupplier->id],
            ['credentials_encrypted' => ['api_key' => 'demo'], 'is_active' => true, 'priority_order' => 1]
        );

        MarkupRule::query()->firstOrCreate(
            ['tenant_id' => $master->id, 'module' => 'flight', 'applies_to_role' => 'b2c'],
            ['rule_type' => MarkupRule::TYPE_PERCENTAGE, 'config' => ['percentage' => '6.0']]
        );

        $superAdmin = $this->makeUser($master, 'Afynd Super Admin', 'admin@fyndmytrip.com', User::ROLE_SUPER_ADMIN);
        $this->makeUser($master, 'Afynd Ops Staff', 'staff@fyndmytrip.com', User::ROLE_STAFF);

        $customer = $this->makeUser($master, 'Demo Customer', 'customer@fyndmytrip.com', User::ROLE_CUSTOMER);
        $this->fundWallet($walletService, $master, $customer, '10000.00');

        $whiteLabel = $tenantService->createTenant([
            'type' => Tenant::TYPE_WHITE_LABEL,
            'name' => 'Acme Travel (White-Label demo)',
            'legal_name' => 'Acme Travel Pvt Ltd',
            'subdomain' => 'acmetravel',
            'status' => Tenant::STATUS_ACTIVE,
        ], $master);

        $partnerAdmin = $this->makeUser($whiteLabel, 'Acme Partner Admin', 'admin@acmetravel.fyndmytrip.com', User::ROLE_PARTNER_ADMIN);

        $agent = $tenantService->createTenant([
            'type' => Tenant::TYPE_AGENT,
            'name' => 'Acme Agent — Downtown Branch',
            'status' => Tenant::STATUS_ACTIVE,
        ], $whiteLabel);

        $agentUser = $this->makeUser($agent, 'Downtown Agent', 'agent@acmetravel.fyndmytrip.com', User::ROLE_AGENT);
        $this->fundWallet($walletService, $agent, $agentUser, '50000.00');

        $subAgent = $tenantService->createTenant([
            'type' => Tenant::TYPE_SUB_AGENT,
            'name' => 'Downtown Sub-Agent — Kiosk 3',
            'status' => Tenant::STATUS_ACTIVE,
        ], $agent);

        $this->makeUser($subAgent, 'Kiosk 3 Sub-Agent', 'subagent@acmetravel.fyndmytrip.com', User::ROLE_SUB_AGENT);

        $directAgent = $tenantService->createTenant([
            'type' => Tenant::TYPE_AGENT,
            'name' => 'Direct Agent (no white-label)',
            'status' => Tenant::STATUS_ACTIVE,
        ], $master);

        $this->makeUser($directAgent, 'Direct Agent Admin', 'agent@fyndmytrip.com', User::ROLE_AGENT);

        $apiPartner = $tenantService->createTenant([
            'type' => Tenant::TYPE_API_PARTNER,
            'name' => 'Connect API Demo Partner',
            'status' => Tenant::STATUS_ACTIVE,
        ], $master);

        $this->makeUser($apiPartner, 'API Partner Staff', 'api@fyndmytrip.com', User::ROLE_API_USER);

        app(TenantContext::class)->clear();

        $this->command?->info('Seeded tenant hierarchy: Master -> White-Label -> Agent -> Sub-Agent, plus Direct Agent, API Partner, and a B2C customer.');
        $this->command?->info('All demo users share the password: password');
    }

    protected function makeUser(Tenant $tenant, string $name, string $email, string $role): User
    {
        app(TenantContext::class)->set($tenant)->bypass();

        $user = User::query()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'email' => $email],
            [
                'name' => $name,
                'password_hash' => Hash::make('password'),
                'role' => $role,
                'status' => User::STATUS_ACTIVE,
            ]
        );

        Wallet::query()->firstOrCreate([
            'tenant_id' => $tenant->id,
            'owner_type' => Wallet::OWNER_USER,
            'owner_id' => $user->id,
        ]);

        return $user;
    }

    protected function fundWallet(WalletService $walletService, Tenant $tenant, User $user, string $amount): void
    {
        app(TenantContext::class)->set($tenant)->bypass();

        $wallet = Wallet::query()->firstOrCreate([
            'tenant_id' => $tenant->id,
            'owner_type' => Wallet::OWNER_USER,
            'owner_id' => $user->id,
        ]);

        if ((float) $wallet->balance === 0.0) {
            $walletService->credit($wallet, $amount, WalletLedgerEntry::REF_ADJUSTMENT, null, 'Initial demo balance');
        }
    }
}
