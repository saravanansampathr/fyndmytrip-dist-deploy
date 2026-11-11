<?php

namespace App\Http\Controllers\Api;

use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Services\TenantService;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * The missing piece flagged in the backlog: TenantController::store()
 * creates a Tenant row, but nothing ever created the login user(s) for it
 * — before this controller, the only way was an `artisan tinker` one-off
 * (see AuthController::register()'s docblock, which claims "Agent/Sub-Agent/
 * Staff accounts are created via TenantController's nested user endpoints" —
 * that comment was aspirational until now). No email/SMS gateway is wired
 * up yet (NotificationGatewayConfigController exists but nothing sends
 * through it), so a newly created or reset password is returned once in
 * the API response for the admin to hand to the new user directly, rather
 * than emailed.
 */
class TenantUserController extends Controller
{
    public function __construct(protected TenantService $tenantService) {}

    public function index(Request $request, Tenant $tenant)
    {
        $this->authorizeManage($request->user(), $tenant);

        $users = User::query()
            ->where('tenant_id', $tenant->id)
            ->orderBy('name')
            ->paginate($request->integer('per_page', 25));

        return UserResource::collection($users);
    }

    public function store(Request $request, Tenant $tenant)
    {
        $this->authorizeManage($request->user(), $tenant);

        $allowedRoles = User::assignableRolesFor($tenant->type);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'email' => [
                'required', 'email', 'max:191',
                Rule::unique('users')->where('tenant_id', $tenant->id),
            ],
            'phone' => ['nullable', 'string', 'max:20'],
            'role' => ['required', 'in:'.implode(',', $allowedRoles)],
        ]);

        $temporaryPassword = Str::password(12);

        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'password_hash' => Hash::make($temporaryPassword),
            'role' => $data['role'],
            'status' => User::STATUS_ACTIVE,
        ]);

        return response()->json([
            'data' => new UserResource($user),
            // Shown once — never re-derivable, not stored anywhere in
            // plaintext. The frontend surfaces this in a one-time "copy
            // this and share it with the user" banner.
            'temporary_password' => $temporaryPassword,
        ], 201);
    }

    public function update(Request $request, Tenant $tenant, User $user)
    {
        $this->authorizeManage($request->user(), $tenant);
        abort_if($user->tenant_id !== $tenant->id, 404);

        $allowedRoles = User::assignableRolesFor($tenant->type);

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:191'],
            'email' => [
                'sometimes', 'nullable', 'email', 'max:191',
                Rule::unique('users')->where('tenant_id', $tenant->id)->ignore($user->id),
            ],
            'phone' => ['sometimes', 'nullable', 'string', 'max:20'],
            'role' => ['sometimes', 'required', 'in:'.implode(',', $allowedRoles)],
            'status' => ['sometimes', 'required', 'in:'.implode(',', [
                User::STATUS_INVITED, User::STATUS_ACTIVE, User::STATUS_SUSPENDED, User::STATUS_DEACTIVATED,
            ])],
        ]);

        $user->update($data);

        return response()->json(['data' => new UserResource($user->fresh())]);
    }

    /**
     * Admin-triggered reset — sets a fresh temporary password immediately
     * rather than emailing a code (mirrors why store() returns one too).
     */
    public function resetPassword(Request $request, Tenant $tenant, User $user)
    {
        $this->authorizeManage($request->user(), $tenant);
        abort_if($user->tenant_id !== $tenant->id, 404);

        $temporaryPassword = Str::password(12);
        $user->forceFill(['password_hash' => Hash::make($temporaryPassword)])->save();
        $user->tokens()->delete();

        return response()->json([
            'message' => 'Password reset.',
            'temporary_password' => $temporaryPassword,
        ]);
    }

    /** Turn off two-factor login for a user who lost their phone. */
    public function resetTwoFactor(Request $request, Tenant $tenant, User $user)
    {
        $this->authorizeManage($request->user(), $tenant);
        abort_if($user->tenant_id !== $tenant->id, 404);

        $user->forceFill(['two_factor_secret' => null, 'two_factor_confirmed_at' => null, 'two_factor_recovery_codes' => null])->save();
        $user->tokens()->delete();

        return response()->json(['message' => 'Two-factor login turned off. The user can set it up again after signing in.']);
    }

    /**
     * Gate: the acting user must hold tenant.manage_users. Platform staff
     * (Super Admin/Staff) may manage any tenant's users, same as every
     * other admin screen in this codebase (e.g.
     * PaymentMethodRouteController::authorizeAccess()) — TenantsPage lists
     * every tenant to them via ?parent_id=, so they must be able to act on
     * whichever one they're looking at. Everyone else may manage their own
     * tenant, or a tenant in the subtree below it.
     */
    protected function authorizeManage(User $actingUser, Tenant $tenant): void
    {
        if (! $actingUser->hasPermission('tenant.manage_users')) {
            abort(403, 'Missing required permission: tenant.manage_users');
        }

        if ($actingUser->isPlatformStaff() || $actingUser->tenant_id === $tenant->id) {
            return;
        }

        $ownTenant = Tenant::query()->findOrFail($actingUser->tenant_id);

        abort_unless(
            $this->isWithinHierarchy($ownTenant, $tenant),
            403,
            'You cannot manage users for a tenant outside your own hierarchy.',
        );
    }

    /**
     * Whether $tenant sits at or below $ownTenant in the hierarchy.
     * TenantHierarchyClosure rows only exist for tenants created through
     * TenantService::createTenant() — a tenant seeded directly (as every
     * demo tenant in this environment was) has none, so getDescendantIds()
     * alone under-reports. Walking parent_tenant_id directly is the
     * fallback that works regardless of how the tenant was created.
     */
    protected function isWithinHierarchy(Tenant $ownTenant, Tenant $tenant): bool
    {
        if (in_array($tenant->id, $this->tenantService->getDescendantIds($ownTenant), true)) {
            return true;
        }

        $node = $tenant;

        for ($i = 0; $i < 25 && $node->parent_tenant_id; $i++) {
            if ($node->parent_tenant_id === $ownTenant->id) {
                return true;
            }

            $node = Tenant::query()->find($node->parent_tenant_id);

            if (! $node) {
                break;
            }
        }

        return false;
    }
}
