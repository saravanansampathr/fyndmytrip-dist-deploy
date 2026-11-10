<?php

namespace Tests\Concerns;

use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Services\TenantService;
use App\Domains\Wallet\Models\Wallet;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 * Shared fixture builders for feature tests — mirrors the shape of
 * database/seeders/TenantHierarchySeeder.php but scoped to whatever a
 * single test needs, rather than the full demo hierarchy.
 */
trait InteractsWithTenants
{
    protected function createMasterTenant(): Tenant
    {
        return Tenant::query()->firstOrCreate(
            ['type' => Tenant::TYPE_MASTER],
            ['name' => 'Test Master', 'status' => Tenant::STATUS_ACTIVE, 'subdomain' => 'www']
        );
    }

    protected function createChildTenant(Tenant $parent, string $type, array $attributes = []): Tenant
    {
        return app(TenantService::class)->createTenant(array_merge([
            'type' => $type,
            'name' => 'Test '.str($type)->headline(),
            'status' => Tenant::STATUS_ACTIVE,
        ], $attributes), $parent);
    }

    protected function createUserForTenant(Tenant $tenant, string $role, array $attributes = []): User
    {
        return User::query()->create(array_merge([
            'tenant_id' => $tenant->id,
            'name' => 'Test User',
            'email' => 'user-'.uniqid().'@example.test',
            'password_hash' => Hash::make('password'),
            'role' => $role,
            'status' => User::STATUS_ACTIVE,
        ], $attributes));
    }

    protected function walletFor(User $user): Wallet
    {
        return Wallet::query()->firstOrCreate([
            'tenant_id' => $user->tenant_id,
            'owner_type' => Wallet::OWNER_USER,
            'owner_id' => $user->id,
        ]);
    }
}
