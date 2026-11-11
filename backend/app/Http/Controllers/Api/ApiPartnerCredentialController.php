<?php

namespace App\Http\Controllers\Api;

use App\Domains\Auth\Models\ApiPartnerCredential;
use App\Domains\Tenancy\Models\Tenant;
use App\Http\Controllers\Controller;
use App\Http\Resources\ApiPartnerCredentialResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Credential management for an API Partner tenant's own "FyndMyTrip
 * Connect API" clients — the piece that was missing from
 * ApiPartnerCredential (scaffolded early with the table/model, never a
 * controller or UI): "For API users we will generate API for Specific
 * domain and they will integrate in their domain" (Platform Roadmap). A
 * White-Label Partner gets a storefront (WhiteLabelController); an API
 * Partner gets credentials instead — they never get a "White-label
 * settings" screen, because they have no storefront to theme (see
 * TenantsPage.tsx's per-type action link).
 *
 * This manages credentials only — issuing/listing/revoking a client_id +
 * client_secret pair bound to one domain, a set of allowed Product
 * Modules, and a requests-per-minute cap. It does NOT wire up the actual
 * inbound API gateway that would authenticate a real external call against
 * these (checking the Authorization header's client_id/secret, matching
 * the request's Origin against allowed_domain, enforcing the rate limit)
 * — that's the separate, larger "B2B/API-Partner booking API for outside
 * clients" build still on the roadmap. Until that exists, these
 * credentials are provisioned and ready but nothing yet accepts them.
 */
class ApiPartnerCredentialController extends Controller
{
    public function index(Request $request, Tenant $tenant)
    {
        $this->authorizeAccess($request->user(), $tenant);

        $credentials = ApiPartnerCredential::withoutTenantScope()
            ->where('tenant_id', $tenant->id)
            ->orderByDesc('created_at')
            ->get();

        return ApiPartnerCredentialResource::collection($credentials);
    }

    /**
     * Issues a new client_id/client_secret pair. The plaintext secret is
     * returned in THIS response only — only its bcrypt hash is ever
     * stored, so there is no "view secret again" later, only
     * regenerateSecret() (a new secret, same client_id).
     */
    public function store(Request $request, Tenant $tenant)
    {
        $this->authorizeAccess($request->user(), $tenant);

        $data = $request->validate([
            'label' => ['nullable', 'string', 'max:191'],
            'allowed_domain' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/i'],
            'allowed_scopes' => ['nullable', 'array'],
            'allowed_scopes.*' => ['in:'.implode(',', Tenant::ALL_MODULES)],
            'rate_limit_per_min' => ['nullable', 'integer', 'min:1', 'max:6000'],
        ]);

        $plainSecret = Str::random(40);

        $credential = ApiPartnerCredential::create([
            'tenant_id' => $tenant->id,
            'label' => $data['label'] ?? null,
            'allowed_domain' => strtolower($data['allowed_domain']),
            'allowed_scopes' => $data['allowed_scopes'] ?? Tenant::ALL_MODULES,
            'rate_limit_per_min' => $data['rate_limit_per_min'] ?? 60,
            'client_secret_hash' => Hash::make($plainSecret),
            'is_active' => true,
        ]);

        return response()->json([
            'data' => new ApiPartnerCredentialResource($credential),
            // Shown once, right now — the UI must tell the partner to copy
            // it immediately; it is unrecoverable after this response.
            'client_secret' => $plainSecret,
        ], 201);
    }

    /**
     * Everything EXCEPT the secret: rename, change the bound domain/scopes/
     * rate limit, or flip is_active (the "Revoke" / "Reactivate" action —
     * a revoked credential's row is kept, not deleted, so past usage stays
     * attributable once the gateway that checks is_active exists).
     */
    public function update(Request $request, Tenant $tenant, ApiPartnerCredential $credential)
    {
        $this->authorizeAccess($request->user(), $tenant);
        $this->authorizeCredential($tenant, $credential);

        $data = $request->validate([
            'label' => ['nullable', 'string', 'max:191'],
            'allowed_domain' => ['sometimes', 'required', 'string', 'max:255', 'regex:/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/i'],
            'allowed_scopes' => ['nullable', 'array'],
            'allowed_scopes.*' => ['in:'.implode(',', Tenant::ALL_MODULES)],
            'rate_limit_per_min' => ['nullable', 'integer', 'min:1', 'max:6000'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        if (array_key_exists('allowed_domain', $data)) {
            $data['allowed_domain'] = strtolower($data['allowed_domain']);
        }

        $credential->update($data);

        return response()->json(['data' => new ApiPartnerCredentialResource($credential->fresh())]);
    }

    /** A new secret for the same client_id — for when a partner suspects theirs leaked, without having to re-integrate a new client_id on their end. */
    public function regenerateSecret(Request $request, Tenant $tenant, ApiPartnerCredential $credential)
    {
        $this->authorizeAccess($request->user(), $tenant);
        $this->authorizeCredential($tenant, $credential);

        $plainSecret = Str::random(40);
        $credential->update(['client_secret_hash' => Hash::make($plainSecret)]);

        return response()->json([
            'data' => new ApiPartnerCredentialResource($credential->fresh()),
            'client_secret' => $plainSecret,
        ]);
    }

    public function destroy(Request $request, Tenant $tenant, ApiPartnerCredential $credential)
    {
        $this->authorizeAccess($request->user(), $tenant);
        $this->authorizeCredential($tenant, $credential);

        $credential->delete();

        return response()->json(null, 204);
    }

    /** Same rule as WhiteLabelController::authorizeAccess() — platform staff may manage any tenant's own credentials; otherwise only a user of that exact tenant (an api_partner tenant's only assignable role, api_user — see User::assignableRolesFor()). */
    protected function authorizeAccess($user, Tenant $tenant): void
    {
        if ($user->isPlatformStaff()) {
            return;
        }

        abort_if($tenant->id !== $user->tenant_id, 403, 'You cannot manage another tenant\'s API credentials.');
    }

    /** Route-model-bound {credential} could belong to any tenant — confirm it's actually this {tenant}'s before acting on it. */
    protected function authorizeCredential(Tenant $tenant, ApiPartnerCredential $credential): void
    {
        abort_if($credential->tenant_id !== $tenant->id, 404);
    }
}
