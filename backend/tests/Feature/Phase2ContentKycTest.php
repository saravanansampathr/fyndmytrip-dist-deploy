<?php

namespace Tests\Feature;

use App\Domains\Audit\Models\AuditLog;
use App\Domains\Cms\Models\ContentItem;
use App\Domains\Kyc\Models\AgentApplication;
use App\Domains\Kyc\Models\KycDocument;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/** Banners/offers/FAQ/SEO content, image uploads, and agent self sign-up with KYC review. */
class Phase2ContentKycTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Storage::fake('local');
        Mail::fake();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_staff_publish_content_and_a_partner_overrides_it_on_their_site(): void
    {
        $master = $this->createMasterTenant();
        $partner = $this->createChildTenant($master, Tenant::TYPE_WHITE_LABEL);
        Sanctum::actingAs($this->createUserForTenant($master, User::ROLE_SUPER_ADMIN));

        $this->postJson('/api/admin/content', ['type' => 'banner', 'title' => 'Diwali sale', 'body' => '<p>Up to 20% off</p><script>x()</script>'])->assertCreated();
        $this->postJson('/api/admin/content', ['type' => 'faq', 'title' => 'How do I cancel?', 'body' => 'From My bookings.'])->assertCreated();
        $this->postJson('/api/admin/content', ['type' => 'seo', 'data' => ['path' => '/flights', 'meta_title' => 'Cheap flights']])->assertCreated();
        $this->postJson('/api/admin/content', ['type' => 'offer', 'title' => 'Expired', 'ends_at' => now()->subDay()->toDateString(), 'starts_at' => now()->subDays(3)->toDateString()])->assertCreated();
        $this->assertTrue(AuditLog::query()->where('subject_type', 'ContentItem')->exists());

        $r = $this->getJson('/api/content?type=banner,offer,faq')->assertOk();
        $this->assertSame('Diwali sale', $r->json('data.banner.0.title'));
        $this->assertStringNotContainsString('script', $r->json('data.banner.0.body'));
        $this->assertCount(0, $r->json('data.offer'));
        $this->assertSame('Cheap flights', $this->getJson('/api/content?type=seo&path=/flights')->json('data.seo.0.data.meta_title'));

        Sanctum::actingAs($this->createUserForTenant($partner, User::ROLE_PARTNER_ADMIN));
        $this->postJson('/api/admin/content', ['type' => 'banner', 'title' => 'Partner banner'])->assertCreated();
        $platformItem = ContentItem::query()->whereNull('tenant_id')->first();
        $this->putJson('/api/admin/content/'.$platformItem->id, ['type' => 'banner', 'title' => 'hijack'])->assertNotFound();
        $this->assertCount(1, $this->getJson('/api/admin/content')->json('data'));
    }

    public function test_image_uploads_are_public_but_only_images(): void
    {
        $master = $this->createMasterTenant();
        Sanctum::actingAs($this->createUserForTenant($master, User::ROLE_SUPER_ADMIN));
        $this->postJson('/api/admin/media', ['file' => UploadedFile::fake()->create('x.php', 10, 'text/x-php')])->assertStatus(422);
        $id = $this->postJson('/api/admin/media', ['file' => UploadedFile::fake()->image('b.png')])->assertCreated()->json('data.id');
        $this->get('/api/media/'.$id.'/b.png')->assertOk();
    }

    public function test_agent_signs_up_and_staff_approve_with_documents(): void
    {
        $master = $this->createMasterTenant();
        $staff = $this->createUserForTenant($master, User::ROLE_SUPER_ADMIN);
        $form = [
            'company_name' => 'Sky Tours', 'contact_name' => 'Ravi', 'email' => 'ravi@skytours.test', 'phone' => '9876543210', 'password' => 'secret123',
            'pan' => 'ABCDE1234F', 'gstin' => '33ABCDE1234F1Z5', 'address' => '1 Main Rd', 'city' => 'Chennai', 'state' => 'Tamil Nadu', 'consent' => '1',
            'documents' => ['pan' => UploadedFile::fake()->create('pan.pdf', 50, 'application/pdf'), 'id_proof' => UploadedFile::fake()->image('id.jpg'), 'iata' => UploadedFile::fake()->create('iata.pdf', 50, 'application/pdf')],
        ];
        $this->post('/api/agent-signup', array_merge($form, ['pan' => 'bad']), ['Accept' => 'application/json'])->assertStatus(422);
        $this->post('/api/agent-signup', $form, ['Accept' => 'application/json'])->assertCreated();
        $this->post('/api/agent-signup', $form, ['Accept' => 'application/json'])->assertStatus(422);
        $app = AgentApplication::query()->firstOrFail();
        $this->assertCount(3, $app->documents);

        // KYC files are never public.
        $doc = KycDocument::query()->where('type', 'iata')->first();
        $this->get('/api/media/'.$doc->media_file_id.'/iata.pdf')->assertNotFound();

        // Another agent cannot see applications.
        Sanctum::actingAs($this->createUserForTenant($this->createChildTenant($master, Tenant::TYPE_AGENT), User::ROLE_AGENT));
        $this->getJson('/api/admin/agent-applications')->assertForbidden();
        $this->get('/api/admin/kyc-documents/'.$doc->id.'/file')->assertForbidden();

        Sanctum::actingAs($staff);
        $this->assertSame('Sky Tours', $this->getJson('/api/admin/agent-applications')->json('data.0.company_name'));
        $this->get('/api/admin/kyc-documents/'.$doc->id.'/file')->assertOk();
        $this->postJson("/api/admin/agent-applications/{$app->id}/approve", ['expiry' => ['iata' => now()->addDays(10)->toDateString()]])->assertOk();

        $agent = User::query()->where('email', 'ravi@skytours.test')->firstOrFail();
        $this->assertSame(User::ROLE_AGENT, $agent->role);
        $tenant = Tenant::query()->findOrFail($agent->tenant_id);
        $this->assertSame('ABCDE1234F', $tenant->pan);
        $this->assertSame($master->id, $tenant->parent_tenant_id);
        $this->postJson('/api/auth/login', ['email' => 'ravi@skytours.test', 'password' => 'secret123'])->assertOk();

        // Expiry reminder fires once, not again the next day.
        $this->artisan('kyc:expiry-reminders')->expectsOutput('Reminded 1 documents.')->assertSuccessful();
        $this->artisan('kyc:expiry-reminders')->expectsOutput('Reminded 0 documents.')->assertSuccessful();
    }

    public function test_rejection_records_the_reason(): void
    {
        $master = $this->createMasterTenant();
        $app = AgentApplication::query()->create(['parent_tenant_id' => $master->id, 'company_name' => 'X', 'contact_name' => 'Y', 'email' => 'y@x.test', 'phone' => '1', 'pan' => 'ABCDE1234F', 'address' => 'a', 'city' => 'c', 'state' => 's', 'password_hash' => 'x', 'status' => 'pending']);
        Sanctum::actingAs($this->createUserForTenant($master, User::ROLE_SUPER_ADMIN));
        $this->postJson("/api/admin/agent-applications/{$app->id}/reject", [])->assertStatus(422);
        $this->postJson("/api/admin/agent-applications/{$app->id}/reject", ['reason' => 'PAN does not match'])->assertOk();
        $this->assertSame('rejected', $app->fresh()->status);
    }
}
