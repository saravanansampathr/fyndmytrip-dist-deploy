<?php

namespace App\Http\Middleware;

use App\Domains\Auth\Models\ApiPartnerCredential;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards /api/partner/v1/*. The caller holds a short-lived token from
 * POST /partner/v1/token, tied to one API credential. On every call this
 * checks the credential is still active, the browser Origin (when there
 * is one) is the credential's allowed domain or a subdomain of it, the
 * service is one the credential may use, and the per-minute rate limit.
 */
class AuthenticatePartnerApi
{
    public function handle(Request $request, Closure $next, ?string $module = null): Response
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();
        $credentialId = null;
        foreach ((array) ($token->abilities ?? []) as $ability) {
            if (str_starts_with($ability, 'partner-credential:')) {
                $credentialId = (int) substr($ability, strlen('partner-credential:'));
            }
        }
        if (! $credentialId) {
            return response()->json(['message' => 'Use a partner access token from POST /api/partner/v1/token.'], 401);
        }

        $credential = ApiPartnerCredential::withoutTenantScope()->find($credentialId);
        if (! $credential || ! $credential->is_active || (int) $credential->tenant_id !== (int) $user->tenant_id) {
            return response()->json(['message' => 'This API credential has been revoked.'], 401);
        }

        if (! self::originAllowed($request->headers->get('Origin'), $credential->allowed_domain)) {
            return response()->json(['message' => 'Requests from this origin are not allowed for this API credential.'], 403);
        }

        if ($module && ! in_array($module, (array) ($credential->allowed_scopes ?? []), true)) {
            return response()->json(['message' => "This API credential is not enabled for {$module}."], 403);
        }

        $key = 'partner-api:'.$credential->id;
        $limit = max(1, (int) $credential->rate_limit_per_min);
        if (RateLimiter::tooManyAttempts($key, $limit)) {
            return response()->json(['message' => 'Rate limit exceeded.'], 429)
                ->header('Retry-After', (string) RateLimiter::availableIn($key));
        }
        RateLimiter::hit($key, 60);

        $request->attributes->set('partner_credential', $credential);

        $response = $next($request);
        $response->headers->set('X-RateLimit-Limit', (string) $limit);
        $response->headers->set('X-RateLimit-Remaining', (string) max(0, RateLimiter::remaining($key, $limit)));

        return $response;
    }

    /** Server-to-server calls send no Origin and are allowed; browsers must come from the bound domain. */
    public static function originAllowed(?string $origin, ?string $domain): bool
    {
        if ($origin === null || $origin === '' || ! $domain) {
            return true;
        }
        $host = strtolower((string) parse_url($origin, PHP_URL_HOST));
        $domain = strtolower($domain);

        return $host === $domain || str_ends_with($host, '.'.$domain);
    }
}
