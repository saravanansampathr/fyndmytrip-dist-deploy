<?php

namespace App\Http\Controllers\Api;

use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Services\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Resources\TenantResource;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The white-label onboarding screen the feature request asked for: "add
 * the domain to our whitelabel API users and need to give option of choose
 * templates... need a option to Map DNS and set their profile detail and
 * business details and complete information, Also we need a option to
 * choose what are all service we're giving in whitelabel like flights
 * hotel bus and all."
 *
 * A tenant manages its own white-label profile; platform staff can manage
 * any tenant's (onboarding a new White-Label Partner on their behalf).
 */
class WhiteLabelController extends Controller
{
    public function templates()
    {
        return response()->json([
            'data' => config('whitelabel_templates'),
            // So the frontend can preview "yourslug{suffix}" while the
            // tenant is still typing — ResolveTenant is what actually
            // matches this suffix against the request Host header.
            'subdomain_hosting_suffix' => config('tenancy.default_hosting_suffix'),
            // The curated Google Fonts catalog the "Brand theme" picker
            // offers — kept in config (not the DB) same as the templates
            // list above, since it's a platform-wide catalog that changes
            // at deploy time, not per-tenant data.
            'fonts' => config('whitelabel_fonts'),
        ]);
    }

    public function show(Request $request, Tenant $tenant)
    {
        $this->authorizeAccess($request->user(), $tenant);

        return response()->json(['data' => new TenantResource($tenant)]);
    }

    /**
     * No-auth: the identity a visitor should actually see right now — name,
     * logo, brand theme — for every screen that renders before (or without)
     * a logged-in user: the storefront header, and Login/Register/Forgot-
     * /Reset-Password. Those pages had no tenant-aware data source at all
     * before this, so they hardcoded "FyndMyTrip" and /logo.png regardless
     * of which White-Label Partner's domain or subdomain a visitor was
     * actually on — "Whitelable or API users customers no where know this
     * ticket or backend is happening thorough fyndmytrip" (Platform Roadmap).
     *
     * Reads TenantContext rather than taking a tenant param, because it's
     * meant to answer "who am I right now" for THIS request, and
     * ResolveTenant has already resolved that correctly before this method
     * runs — via X-Storefront-Host for a guest on a partner's own domain
     * (see ResolveTenant::resolveTenantFromHost()'s docblock), or via
     * $user->tenant for a logged-in session — the exact same context
     * BelongsToTenant's query scoping already trusts everywhere else.
     *
     * Deliberately a tiny, separate shape from TenantResource: nothing
     * here (name/logo/theme colors, icon style, About/Contact copy,
     * published support details) is more sensitive than what any visitor
     * to that tenant's own domain already sees rendered in their browser —
     * every one of these fields is meant to be public-facing content.
     *
     * `homepage_hero`/`homepage_flow` are resolved here (from the tenant's
     * `template_id` against config/whitelabel_templates.php) rather than
     * left for the frontend to look up, so HomePage.tsx never has to fetch
     * the full template catalog just to render itself — it reads these two
     * strings straight off this already-fetched branding object. A tenant
     * with no template_id set (never picked one, e.g. the Master/default
     * tenant) falls back to the catalog's first entry — today's existing
     * "classic hero + card sections" look, so nothing regresses.
     */
    public function current(TenantContext $context)
    {
        $tenant = $context->get();

        $domain = $tenant?->custom_domain
            ?: ($tenant?->subdomain ? $tenant->subdomain.config('tenancy.default_hosting_suffix') : null)
            ?: config('tenancy.default_hosting_suffix', 'fyndmytrip.com');

        $templates = config('whitelabel_templates');
        $template = null;
        if ($tenant?->template_id) {
            foreach ($templates as $tpl) {
                if ($tpl['id'] === $tenant->template_id) {
                    $template = $tpl;
                    break;
                }
            }
        }
        $template ??= $templates[0];

        return response()->json([
            'data' => [
                'name' => $tenant?->name ?: config('app.name'),
                'logo_url' => $tenant?->logo_url ?: null,
                'domain' => ltrim($domain, '.'),
                'theme' => $tenant?->config['theme'] ?? null,
                'icon_style' => $tenant?->config['icon_style'] ?? null,
                'about' => $tenant?->config['about_content'] ?? null,
                'contact' => $tenant?->config['contact_content'] ?? null,
                'support_email' => $tenant?->support_email ?: null,
                'support_phone' => $tenant?->support_phone ?: null,
                'business_address' => $tenant?->business_address ?: null,
                'template_id' => $template['id'],
                'homepage_hero' => $template['homepage_hero'],
                'homepage_flow' => $template['homepage_flow'],
                // The search-widget structure axis (see
                // config/whitelabel_templates.php's docblock) — resolved the
                // same way as the two keys above, straight off the chosen
                // template, so HomePage.tsx never needs a second fetch.
                'homepage_search_widget' => $template['homepage_search_widget'],
                // The page layouts the storefront (storefront/ in the repo)
                // renders for this template across the whole booking flow.
                'template_name' => $template['name'],
                'storefront_layout' => $template['storefront_layout'] ?? null,
            ],
        ]);
    }

    /**
     * "Usually we will map the domain our DNS, Either main domain or sub
     * domain" — a tenant picks exactly one: a subdomain of the platform's
     * own root domain (config('tenancy.default_hosting_suffix'), e.g.
     * ".fyndmytrip.com") — instant, no external DNS action, since
     * ResolveTenant already matches *that* suffix against the request Host
     * header for every request — or their own custom_domain (requires the
     * CNAME + Verify DNS flow below, because it's a domain we don't own).
     * Setting one clears the other so a tenant is never ambiguously mapped
     * to both at once.
     */
    public function update(Request $request, Tenant $tenant)
    {
        $this->authorizeAccess($request->user(), $tenant);

        $templateIds = array_column(config('whitelabel_templates'), 'id');

        $data = $request->validate([
            'domain_type' => ['nullable', 'in:custom,subdomain'],
            'custom_domain' => ['nullable', 'string', 'max:191', 'unique:tenants,custom_domain,'.$tenant->id],
            'subdomain' => [
                'nullable', 'string', 'max:63', 'min:3',
                'regex:/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/',
                'unique:tenants,subdomain,'.$tenant->id,
                Rule::notIn(['www', 'api', 'admin', 'app', 'demo', 'whitelabel', 'mail', 'ftp']),
            ],
            'template_id' => ['nullable', 'integer', 'in:'.implode(',', $templateIds)],
            'logo_url' => ['nullable', 'string', 'max:500', 'url'],
            'support_email' => ['nullable', 'email', 'max:191'],
            'support_phone' => ['nullable', 'string', 'max:30'],
            'business_address' => ['nullable', 'string', 'max:2000'],
            'business_registration_no' => ['nullable', 'string', 'max:100'],
            'enabled_modules' => ['nullable', 'array'],
            'enabled_modules.*' => ['in:'.implode(',', Tenant::ALL_MODULES)],
            'social_links' => ['nullable', 'array'],
            'social_links.facebook' => ['nullable', 'string', 'max:255', 'url'],
            'social_links.instagram' => ['nullable', 'string', 'max:255', 'url'],
            'social_links.twitter' => ['nullable', 'string', 'max:255', 'url'],
            'social_links.linkedin' => ['nullable', 'string', 'max:255', 'url'],
            'social_links.youtube' => ['nullable', 'string', 'max:255', 'url'],
            // Platform Roadmap Phase 5 — the shared PDF branding strip
            // (App\Support\PdfBranding) reads these two so a tenant's own
            // mobile-app links appear on their tickets/vouchers instead of
            // the platform's; unset here just falls back to the platform's
            // own (config('services.app_links')).
            'app_links' => ['nullable', 'array'],
            'app_links.play_store_url' => ['nullable', 'string', 'max:500', 'url'],
            'app_links.app_store_url' => ['nullable', 'string', 'max:500', 'url'],
            // "Fully custom" theming (Platform Roadmap's theming-engine ask) —
            // a real font/color/button-shape override per tenant, replacing
            // the old 12-preset template_id as the thing that actually
            // renders (template_id is kept only as a one-click starting
            // point; this is what ThemeProvider on the frontend applies as
            // CSS custom-property overrides at runtime). Same free-form
            // config-column pattern as social_links/app_links above — no
            // migration needed.
            'theme' => ['nullable', 'array'],
            'theme.primary_color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'theme.accent_color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'theme.font_family' => ['nullable', 'string', Rule::in(array_keys(config('whitelabel_fonts')))],
            'theme.button_style' => ['nullable', 'in:rounded,pill,square'],
            // Which icon set (lib/moduleIcons.ts on the frontend) renders
            // the 9 travel-module icons on this tenant's storefront header
            // and B2B top bar — "need different icons to choose to
            // whitelbale users" (Platform Roadmap). Unset means 'outline',
            // today's existing look, so nothing changes for a tenant who
            // never picks one.
            'icon_style' => ['nullable', 'in:outline,filled,duotone'],
            // Per-tenant About Us content, replacing the platform's static
            // default on that tenant's storefront (AboutUsPage.tsx) — "About
            // Us and Contact Us pages need Whitelable users need options to
            // change in their login." Deliberately a short structured shape
            // (headline/body/highlights), not free-form rich text/HTML —
            // same convention as every other Tenant::config sub-shape here.
            'about_content' => ['nullable', 'array'],
            'about_content.headline' => ['nullable', 'string', 'max:191'],
            'about_content.body' => ['nullable', 'string', 'max:4000'],
            'about_content.highlights' => ['nullable', 'array', 'max:8'],
            'about_content.highlights.*' => ['string', 'max:300'],
            // Per-tenant Contact Us intro copy — support_email/
            // support_phone/business_address above already cover the actual
            // contact details, so this is just the surrounding text.
            'contact_content' => ['nullable', 'array'],
            'contact_content.intro' => ['nullable', 'string', 'max:1000'],
            'contact_content.note' => ['nullable', 'string', 'max:1000'],
        ]);

        $configPatch = [];
        if (array_key_exists('social_links', $data)) {
            $configPatch['social_links'] = $data['social_links'];
            unset($data['social_links']);
        }
        if (array_key_exists('app_links', $data)) {
            $configPatch['app_links'] = $data['app_links'];
            unset($data['app_links']);
        }
        if (array_key_exists('theme', $data)) {
            $configPatch['theme'] = $data['theme'];
            unset($data['theme']);
        }
        if (array_key_exists('icon_style', $data)) {
            $configPatch['icon_style'] = $data['icon_style'];
            unset($data['icon_style']);
        }
        if (array_key_exists('about_content', $data)) {
            $configPatch['about_content'] = $data['about_content'];
            unset($data['about_content']);
        }
        if (array_key_exists('contact_content', $data)) {
            $configPatch['contact_content'] = $data['contact_content'];
            unset($data['contact_content']);
        }
        if (! empty($configPatch)) {
            $data['config'] = $tenant->withMergedConfig($configPatch);
        }

        $domainType = $data['domain_type'] ?? ($tenant->subdomain && ! $tenant->custom_domain ? 'subdomain' : 'custom');
        unset($data['domain_type']);

        if ($domainType === 'subdomain') {
            $data['subdomain'] = isset($data['subdomain']) ? strtolower($data['subdomain']) : $tenant->subdomain;
            $data['custom_domain'] = null;

            // Our own root domain — nothing for the tenant to configure at
            // their registrar, so it's live the moment it's saved.
            if ($data['subdomain'] && $data['subdomain'] !== $tenant->subdomain) {
                $data['dns_status'] = Tenant::DNS_VERIFIED;
                $data['dns_verified_at'] = now();
            }
        } else {
            $data['subdomain'] = null;

            // Changing the custom domain invalidates any previous DNS
            // verification — the new hostname hasn't been checked yet.
            if (array_key_exists('custom_domain', $data) && $data['custom_domain'] !== $tenant->custom_domain) {
                $data['dns_status'] = Tenant::DNS_NOT_CONFIGURED;
                $data['dns_verified_at'] = null;
            }
        }

        $tenant->update($data);

        return response()->json(['data' => new TenantResource($tenant->fresh())]);
    }

    /**
     * A real DNS lookup (not a rubber-stamp button): resolves the tenant's
     * custom_domain's A record(s) and checks whether any of them point at
     * this server's own IP. Marks 'verified' only on an actual match;
     * otherwise 'pending' with the records found (or a lookup error) so
     * the admin can see exactly what to fix at their registrar.
     *
     * This used to check for a CNAME pointing at a fixed platform hostname
     * (Tenant::DNS_CNAME_TARGET, 'whitelabel.fyndmytrip.com') — a model
     * that fits a CDN/load-balancer that terminates many partner hostnames
     * at once, the way Shopify points merchants at shops.myshopify.com.
     * This deployment is a single cPanel server, not that: there was no
     * real DNS answering at that target hostname, so no custom domain
     * could ever actually verify. On shared cPanel hosting, a White-Label
     * Partner's domain has to be added as its own Alias/Addon Domain in
     * cPanel (sharing demo.fyndmytrip.in's document root) *and* pointed at
     * this server's IP via a plain A record — there's no CNAME target that
     * makes sense here, so this checks the thing that actually has to be
     * true for the domain to work at all: does it resolve to this server.
     */
    public function verifyDns(Request $request, Tenant $tenant)
    {
        $this->authorizeAccess($request->user(), $tenant);

        if (! $tenant->custom_domain) {
            return response()->json(['message' => 'Set a custom domain before verifying DNS.'], 422);
        }

        $domain = $tenant->custom_domain;
        $expectedIp = $this->serverIp($request);
        $found = [];
        $matched = false;
        $lookupError = null;

        try {
            $records = @dns_get_record($domain, DNS_A) ?: [];
            foreach ($records as $record) {
                $ip = $record['ip'] ?? null;

                if ($ip) {
                    $found[] = $ip;

                    if ($expectedIp && $ip === $expectedIp) {
                        $matched = true;
                    }
                }
            }
        } catch (\Throwable $e) {
            $lookupError = $e->getMessage();
        }

        $tenant->update([
            'dns_status' => $matched ? Tenant::DNS_VERIFIED : Tenant::DNS_PENDING,
            'dns_verified_at' => $matched ? now() : null,
        ]);

        return response()->json([
            'data' => new TenantResource($tenant->fresh()),
            'expected_a_record' => $expectedIp,
            'records_found' => $found,
            'lookup_error' => $lookupError,
        ]);
    }

    /**
     * This server's own IP — what every White-Label Partner's custom
     * domain needs an A record pointing at. SERVER_ADDR is set by the web
     * server itself (Apache/LiteSpeed on cPanel) to the address the current
     * request physically arrived on, so this needs no hardcoded IP and
     * keeps working if the box is ever migrated. Falls back to resolving
     * this very request's own API hostname (api-demo.fyndmytrip.in) when
     * SERVER_ADDR isn't populated (e.g. behind some proxy configurations) —
     * that hostname has to resolve to this same server by definition.
     */
    protected function serverIp(Request $request): ?string
    {
        $addr = $request->server('SERVER_ADDR');

        if ($addr) {
            return $addr;
        }

        $resolved = gethostbyname($request->getHost());

        return $resolved !== $request->getHost() ? $resolved : null;
    }

    protected function authorizeAccess($user, Tenant $tenant): void
    {
        if ($user->isPlatformStaff()) {
            return;
        }

        abort_if($tenant->id !== $user->tenant_id, 403, 'You cannot manage another tenant\'s white-label profile.');
    }
}
