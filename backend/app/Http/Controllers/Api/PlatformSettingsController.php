<?php

namespace App\Http\Controllers\Api;

use App\Domains\Tenancy\Models\Tenant;
use App\Http\Controllers\Controller;
use App\Http\Resources\TenantResource;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * The platform-level counterpart to WhiteLabelController's per-tenant
 * profile screen — the backlog gap flagged as "global settings and
 * Company profile" for the platform itself (Master), distinct from any
 * White-Label Partner's own business details. Reuses the same Tenant
 * columns (this platform's own row, type=master) rather than a parallel
 * table, plus a handful of sub-keys of Tenant::config for settings that
 * are genuinely global rather than per-tenant (default currency, timezone,
 * maintenance mode, app-store links, certifications).
 *
 * `app_links` and `certifications` are what the storefront footer's app
 * badges and certification logos (see publicSettings() below) and —
 * for app_links — every booking PDF's branding strip (PdfBranding::
 * appLinks()) actually read. Before this, `app_links` existed only as a
 * read path with no way to ever set it from here ("Logo, social links, QR
 * target and app-store URLs live in one CRM/branding settings screen, not
 * hardcoded" — Platform Roadmap Phase 5); this closes that gap. Both stay
 * null until platform staff enter a real value — never a placeholder — so
 * the footer/PDF strip's existing "don't render until real" rule keeps
 * working with no frontend changes beyond reading from here instead of a
 * hardcoded file.
 */
class PlatformSettingsController extends Controller
{
    public function show(Request $request)
    {
        $this->authorizeManage($request->user());

        return response()->json(['data' => new TenantResource($this->masterTenant())]);
    }

    public function update(Request $request)
    {
        $this->authorizeManage($request->user());
        $tenant = $this->masterTenant();

        $templateIds = array_column(config('whitelabel_templates'), 'id');

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:191'],
            // Same 12-entry catalog WhiteLabelController offers every other
            // tenant — the Master storefront (demo.fyndmytrip.in itself) had
            // no screen at all to pick one of these: WhiteLabelSettingsPage
            // only ever reaches tenants that show up as a row on the
            // Tenants page, and Master (the hierarchy root) never does —
            // TenantController::index() lists `parent_tenant_id = $parent`,
            // and Master has no parent. Without this, Master's template_id
            // stayed null forever and WhiteLabelController::current()'s
            // null-fallback silently kept it on the catalog's first entry
            // (Classic Navy) no matter what — "couldn't see the 10+
            // templates" on the main site traces to exactly this gap.
            'template_id' => ['nullable', 'integer', 'in:'.implode(',', $templateIds)],
            'legal_name' => ['nullable', 'string', 'max:255'],
            'logo_url' => ['nullable', 'string', 'max:500', 'url'],
            'support_email' => ['nullable', 'email', 'max:191'],
            'support_phone' => ['nullable', 'string', 'max:30'],
            'business_address' => ['nullable', 'string', 'max:2000'],
            'business_registration_no' => ['nullable', 'string', 'max:100'],
            'social_links' => ['nullable', 'array'],
            'social_links.facebook' => ['nullable', 'string', 'max:255', 'url'],
            'social_links.instagram' => ['nullable', 'string', 'max:255', 'url'],
            'social_links.twitter' => ['nullable', 'string', 'max:255', 'url'],
            'social_links.linkedin' => ['nullable', 'string', 'max:255', 'url'],
            'social_links.youtube' => ['nullable', 'string', 'max:255', 'url'],
            // Click-to-chat number for the floating WhatsApp button, not a
            // notification gateway (see NotificationGatewayConfigController
            // for the "send transactional messages via WhatsApp" settings —
            // unrelated). Digits only, international format, e.g.
            // "919841811880" — validated loosely since formats vary by
            // country and wa.me is forgiving; the frontend strips anything
            // that isn't a digit before building the wa.me link.
            'social_links.whatsapp_number' => ['nullable', 'string', 'max:20'],
            'app_links' => ['nullable', 'array'],
            'app_links.play_store_url' => ['nullable', 'string', 'max:500', 'url'],
            'app_links.app_store_url' => ['nullable', 'string', 'max:500', 'url'],
            // IATA today, kept as a small array (not a one-off boolean) so a
            // second certification doesn't need a schema change — add
            // another entry under the same `certifications` key whenever
            // there's a second real one to show.
            'certifications' => ['nullable', 'array'],
            'certifications.iata' => ['nullable', 'array'],
            'certifications.iata.accredited' => ['nullable', 'boolean'],
            'certifications.iata.agent_code' => ['nullable', 'string', 'max:100'],
            'certifications.iata.logo_url' => ['nullable', 'string', 'max:500'],
            'platform_settings' => ['nullable', 'array'],
            'platform_settings.default_currency' => ['nullable', 'string', 'size:3'],
            'platform_settings.default_timezone' => ['nullable', 'string', 'max:64'],
            'platform_settings.maintenance_mode' => ['nullable', 'boolean'],
        ]);

        $configPatch = [];

        foreach (['social_links', 'app_links', 'certifications', 'platform_settings'] as $key) {
            if (array_key_exists($key, $data)) {
                $configPatch[$key] = $data[$key];
                unset($data[$key]);
            }
        }

        if ($configPatch !== []) {
            $data['config'] = $tenant->withMergedConfig($configPatch);
        }

        $tenant->update($data);

        return response()->json(['data' => new TenantResource($tenant->fresh())]);
    }

    /**
     * No-auth read of just the safe-to-publish subset of the master
     * tenant's config — everything the storefront footer/WhatsApp button
     * need (app badges, certification logos, social handles, the WhatsApp
     * click-to-chat number), and nothing else (no contact *details* like
     * support_email/support_phone, no maintenance-mode flag, no internal
     * settings). social_links was already settable from the admin screen
     * before this — it just had no public read path, so the footer fell
     * back to the hardcoded SITE_CONFIG file instead. Separate from show()
     * above rather than relaxing that route's auth, since show() returns
     * the full admin-only profile.
     */
    public function publicSettings(Request $request)
    {
        $tenant = $this->masterTenant();

        return response()->json([
            'data' => [
                'social_links' => $tenant->config['social_links'] ?? null,
                'app_links' => $tenant->config['app_links'] ?? null,
                'certifications' => $tenant->config['certifications'] ?? null,
            ],
        ]);
    }

    protected function masterTenant(): Tenant
    {
        return Tenant::query()->where('type', Tenant::TYPE_MASTER)->firstOrFail();
    }

    protected function authorizeManage(User $user): void
    {
        abort_unless($user->isPlatformStaff(), 403, 'Only platform staff can manage platform settings.');
    }
}
