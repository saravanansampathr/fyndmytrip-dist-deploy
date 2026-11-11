<?php

namespace Tests\Feature;

use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class StorefrontTemplatesTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private const AXES = ['header', 'hero', 'results', 'details', 'travellers', 'payment', 'footer'];

    public function test_the_catalog_has_fifty_plus_distinct_full_flow_templates(): void
    {
        $templates = config('whitelabel_templates');

        $this->assertGreaterThanOrEqual(50, count($templates));
        $this->assertSame(range(1, count($templates)), array_column($templates, 'id'));
        $this->assertCount(count($templates), array_unique(array_column($templates, 'name')));

        $layouts = [];
        foreach ($templates as $tpl) {
            $this->assertSame(self::AXES, array_keys($tpl['storefront_layout']), $tpl['name']);
            $this->assertNotEmpty($tpl['homepage_hero']);
            $layouts[] = implode('|', $tpl['storefront_layout']);
        }
        $this->assertCount(count($templates), array_unique($layouts), 'every template has its own set of page layouts');
    }

    public function test_a_partner_can_pick_any_template_and_its_storefront_uses_it(): void
    {
        $master = $this->createMasterTenant();
        $partner = $this->createChildTenant($master, Tenant::TYPE_WHITE_LABEL, ['subdomain' => 'acme']);
        $admin = $this->createUserForTenant($partner, User::ROLE_PARTNER_ADMIN);
        $last = collect(config('whitelabel_templates'))->last();

        Sanctum::actingAs($admin, ['*']);
        $this->getJson('/api/whitelabel/templates')
            ->assertOk()
            ->assertJsonCount(count(config('whitelabel_templates')), 'data');

        $this->putJson("/api/tenants/{$partner->id}/whitelabel", ['template_id' => $last['id']])
            ->assertOk();

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/whitelabel/current', ['X-Storefront-Host' => 'acme'.config('tenancy.default_hosting_suffix')])
            ->assertOk()
            ->assertJsonPath('data.template_id', $last['id'])
            ->assertJsonPath('data.template_name', $last['name'])
            ->assertJsonPath('data.storefront_layout.results', $last['storefront_layout']['results']);
    }
}
