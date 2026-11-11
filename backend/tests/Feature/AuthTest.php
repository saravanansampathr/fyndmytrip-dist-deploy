<?php

namespace Tests\Feature;

use App\Domains\Wallet\Models\Wallet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    public function test_a_customer_can_register_against_the_default_storefront(): void
    {
        $this->createMasterTenant();

        $response = $this->postJson('/api/auth/register', [
            'name' => 'Jane Traveller',
            'email' => 'jane@example.test',
            'password' => 'super-secret',
        ]);

        $response->assertCreated()
            ->assertJsonPath('user.email', 'jane@example.test')
            ->assertJsonPath('user.role', User::ROLE_CUSTOMER)
            ->assertJsonStructure(['user', 'token']);

        $user = User::query()->where('email', 'jane@example.test')->firstOrFail();

        $this->assertDatabaseHas('wallets', [
            'owner_type' => Wallet::OWNER_USER,
            'owner_id' => $user->id,
            'balance' => '0.00',
        ]);
    }

    public function test_login_fails_with_wrong_password(): void
    {
        $master = $this->createMasterTenant();
        $this->createUserForTenant($master, User::ROLE_CUSTOMER, ['email' => 'bob@example.test']);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'bob@example.test',
            'password' => 'wrong-password',
        ]);

        $response->assertUnprocessable();
    }

    public function test_login_succeeds_and_returns_a_token(): void
    {
        $master = $this->createMasterTenant();
        $this->createUserForTenant($master, User::ROLE_CUSTOMER, ['email' => 'ann@example.test']);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'ann@example.test',
            'password' => 'password',
        ]);

        $response->assertOk()->assertJsonStructure(['user', 'token']);
    }
}
