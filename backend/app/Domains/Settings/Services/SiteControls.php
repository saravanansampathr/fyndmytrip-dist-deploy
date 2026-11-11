<?php

namespace App\Domains\Settings\Services;

use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

/**
 * Switches admins flip without a release: maintenance mode (stops new
 * bookings and payments for everyone except platform staff) and which
 * services an account may sell (Tenants → White label → services, enforced
 * here for the account and every account above it).
 */
class SiteControls
{
    /** First path segment (after /api) → service. */
    public const MODULE_PATHS = [
        'flights' => 'flight', 'hotels' => 'hotel', 'bus' => 'bus', 'cabs' => 'cab',
        'packages' => 'holiday', 'holiday-bookings' => 'holiday', 'insurance' => 'insurance',
        'visa' => 'visa', 'visa-bookings' => 'visa', 'cruises' => 'cruise', 'activities' => 'activity',
    ];

    /** Paths that keep working during maintenance. */
    protected const MAINTENANCE_OPEN = ['auth/*', 'payments/webhooks/*', 'health', 'newsletter*', 'support-tickets*', 'admin/*', 'platform-settings*'];

    public function __construct(protected SettingsService $settings) {}

    public function maintenance(): array
    {
        $m = (array) $this->settings->value('platform', 'maintenance', []);
        // The older switch on Platform settings still works too.
        $legacy = Cache::remember('fyndmytrip:maintenance:legacy', now()->addMinutes(5), fn () => (bool) data_get(
            Tenant::query()->where('type', Tenant::TYPE_MASTER)->first()?->config, 'platform_settings.maintenance_mode', false
        ));

        return ['enabled' => (bool) ($m['enabled'] ?? false) || $legacy, 'message' => (string) ($m['message'] ?? '')];
    }

    /** A response that blocks the request, or null to let it through. */
    public function check(Request $request, ?Tenant $tenant): ?\Symfony\Component\HttpFoundation\Response
    {
        $path = ltrim(preg_replace('#^api/#', '', $request->path()), '/');
        $user = Auth::guard('sanctum')->user();
        $staff = $user instanceof User && $user->isPlatformStaff();

        if (! $staff && ! $request->isMethodSafe()) {
            $open = false;
            foreach (self::MAINTENANCE_OPEN as $p) {
                $open = $open || \Illuminate\Support\Str::is($p, $path);
            }
            if (! $open && ($m = $this->maintenance())['enabled']) {
                return response()->json(['message' => $m['message'] ?: 'The site is under maintenance. Please try again shortly.', 'maintenance' => true], 503);
            }
        }

        $segments = explode('/', $path);
        if (($segments[0] ?? '') === 'partner' && ($segments[1] ?? '') === 'v1') {
            $segments = array_slice($segments, 2);
        }
        $module = self::MODULE_PATHS[$segments[0] ?? ''] ?? null;
        if ($module && ! $staff && $tenant && ! $this->moduleEnabled($tenant, $module)) {
            return response()->json(['message' => 'This service is not available on this site.', 'module' => $module], 403);
        }

        return null;
    }

    public function moduleEnabled(Tenant $tenant, string $module): bool
    {
        $seen = 0;
        for ($t = $tenant; $t && $seen < 10; $t = $t->parent_tenant_id ? Tenant::query()->find($t->parent_tenant_id) : null, $seen++) {
            if (! empty($t->enabled_modules) && ! in_array($module, (array) $t->enabled_modules, true)) {
                return false;
            }
        }

        return true;
    }
}
