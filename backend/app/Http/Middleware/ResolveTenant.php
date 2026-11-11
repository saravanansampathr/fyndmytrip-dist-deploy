<?php

namespace App\Http\Middleware;

use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Services\TenantContext;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves "which tenant is this request acting as" and binds it into the
 * shared TenantContext before any tenant-scoped query runs — see the System
 * Architecture Document's tenant-context middleware description and
 * Database Design Document §3.5 (the closure table this middleware's
 * cross-tenant bypass decision ultimately gates access to).
 */
class ResolveTenant
{
    public function __construct(protected TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = $request->user();

        if ($user) {
            $tenant = $user->tenant;

            if ($tenant) {
                $this->context->set($tenant);

                // Super Admin and platform Staff operate the Master Admin
                // Console across every tenant by default; scoping them down
                // to a single tenant is done explicitly per-endpoint via
                // TenantContext::set(), not by the middleware.
                if (in_array($user->role, [User::ROLE_SUPER_ADMIN, User::ROLE_STAFF], true)) {
                    $this->context->bypass();
                }
            }
        } else {
            $tenant = $this->resolveTenantFromHost($request);

            if ($tenant) {
                $this->context->set($tenant);
            }
        }

        if (isset($tenant) && $tenant && ! $tenant->isActive() && ! $request->routeIs('auth.*')) {
            abort(403, 'This tenant is not currently active.');
        }

        // Maintenance mode and the services this account may sell.
        if ($blocked = app(\App\Domains\Settings\Services\SiteControls::class)->check($request, $tenant ?? null)) {
            return $blocked;
        }

        return $next($request);
    }

    protected function resolveTenantFromHost(Request $request): ?Tenant
    {
        // The storefront (e.g. demo.fyndmytrip.in, or a White-Label Partner's
        // own custom_domain) and the API (api-demo.fyndmytrip.in) are
        // deliberately separate origins in this deployment — see
        // config/cors.php. That means $request->getHost() here is *always*
        // the API's own hostname, never the domain a shopper actually typed
        // into their browser, so resolving a tenant by Host alone silently
        // falls back to the Master tenant for every White-Label domain.
        //
        // The frontend (api/client.ts) sends the real storefront hostname in
        // this header on every request so cross-domain white-labeling can
        // work at all. Trusting a client-supplied header is safe here only
        // because it's used exactly the way getHost() was already being
        // used: to look up a *public* storefront's branding/config, the same
        // information that domain's own visitors already see — it does not
        // grant access to any other tenant's authenticated/private data,
        // and a header naming an unknown domain just falls through to the
        // Master tenant below, same as today.
        $host = $request->header('X-Storefront-Host') ?: $request->getHost();

        $tenant = Tenant::query()->where('custom_domain', $host)->first();

        if ($tenant) {
            return $tenant;
        }

        $suffix = config('tenancy.default_hosting_suffix');

        if ($suffix && str_ends_with($host, $suffix)) {
            $subdomain = substr($host, 0, -strlen($suffix));

            return Tenant::query()->where('subdomain', $subdomain)->first();
        }

        // No custom domain / subdomain match — fall back to the Master
        // tenant so the default fyndmytrip.com B2C storefront still works.
        return Tenant::query()->where('type', Tenant::TYPE_MASTER)->first();
    }
}
