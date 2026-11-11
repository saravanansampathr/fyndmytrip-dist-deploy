<?php

namespace App\Http\Controllers\Api;

use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Services\TenantService;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Google Analytics, Google Ads, Google Tag Manager and Meta (Facebook)
 * Pixel IDs, per website. Only IDs are stored (never script code), and the
 * storefront loads the standard tag for each ID it is given.
 */
class MarketingTagsController extends Controller
{
    public const RULES = [
        'ga4_id' => ['nullable', 'regex:/^G-[A-Z0-9]{4,15}$/'],
        'gtm_id' => ['nullable', 'regex:/^GTM-[A-Z0-9]{4,10}$/'],
        'google_ads_id' => ['nullable', 'regex:/^AW-\d{6,12}$/'],
        'google_ads_purchase_label' => ['nullable', 'regex:/^[A-Za-z0-9_-]{4,40}$/'],
        'meta_pixel_id' => ['nullable', 'regex:/^\d{10,20}$/'],
    ];

    public function show(Request $request)
    {
        $t = $this->tenant($request);

        return response()->json(['data' => array_merge(array_fill_keys(array_keys(self::RULES), null), (array) (($t->config ?? [])['marketing'] ?? [])), 'tenant' => ['id' => $t->id, 'name' => $t->name]]);
    }

    public function update(Request $request)
    {
        $t = $this->tenant($request);
        $d = $request->validate(self::RULES, ['regex' => 'This does not look like a valid ID. Copy it exactly from the provider.']);
        $config = $t->config ?? [];
        $config['marketing'] = array_filter(array_map(fn ($v) => $v ? trim($v) : null, $d));
        $t->forceFill(['config' => $config])->save();

        return response()->json(['data' => $config['marketing'], 'message' => 'Saved. Your website loads these tags from the next page visit.']);
    }

    /** Staff edit the main site (or any site with ?tenant_id); a partner edits their own. */
    protected function tenant(Request $request): Tenant
    {
        $u = $request->user();
        if ($u->isPlatformStaff()) {
            return $request->input('tenant_id') ? Tenant::query()->findOrFail($request->input('tenant_id')) : $u->tenant;
        }
        abort_unless($u->role === User::ROLE_PARTNER_ADMIN, 403);
        $id = (int) ($request->input('tenant_id') ?: $u->tenant_id);
        abort_unless($id === $u->tenant_id || in_array($id, app(TenantService::class)->getDescendantIdsRobust($u->tenant), true), 403);

        return Tenant::query()->findOrFail($id);
    }

    /** What the storefront needs: the nearest site in the chain that set tags. */
    public static function forSite(?Tenant $tenant): array
    {
        $t = $tenant;
        while ($t) {
            $m = array_filter((array) (($t->config ?? [])['marketing'] ?? []));
            if ($m) {
                return $m;
            }
            $t = $t->parent;
        }

        return [];
    }
}
