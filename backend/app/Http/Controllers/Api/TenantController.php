<?php

namespace App\Http\Controllers\Api;

use App\Domains\Tenancy\Exceptions\MaxHierarchyDepthExceededException;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Services\TenantService;
use App\Http\Controllers\Controller;
use App\Http\Resources\TenantResource;
use Illuminate\Http\Request;

class TenantController extends Controller
{
    public function __construct(protected TenantService $tenantService) {}

    /**
     * Direct children of the acting user's own tenant — e.g. a White-Label
     * Partner listing the Agents it has onboarded. Super Admin/Staff (whose
     * TenantContext is bypassed) pass ?parent_id= to browse any node.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $parentId = $request->integer('parent_id') ?: $user->tenant_id;

        // Tenant itself carries no BelongsToTenant/TenantScope (it's the
        // hierarchy root, not a scoped row), so there is no tenant scope to
        // lift here — a plain query() is correct, unlike scoped models.
        $children = Tenant::query()
            ->where('parent_tenant_id', $parentId)
            ->orderBy('name')
            ->paginate($request->integer('per_page', 25));

        return TenantResource::collection($children);
    }

    public function show(Request $request, Tenant $tenant)
    {
        return new TenantResource($tenant);
    }

    /**
     * Create a sub-tenant beneath the acting user's own tenant — a
     * White-Label Partner onboarding an Agent (or Sub-Agent/API Partner),
     * or Super Admin/Staff onboarding a White-Label Partner/Agent/API
     * Partner under Master. See Master Brief §3. An Agent does NOT get
     * this: "Only Whitelabel users can have this privilege, Agent can
     * create sub users to book" — an Agent still creates additional LOGINS
     * under its own existing tenant via TenantUserController
     * (tenant.manage_users), just never a new tenant of its own; see
     * RolePermissionSeeder's grants map for the enforcement.
     */
    public function store(Request $request)
    {
        $user = $request->user();

        if (! $user->hasPermission('tenant.create')) {
            abort(403, 'Missing required permission: tenant.create');
        }

        $data = $request->validate([
            'type' => ['required', 'in:white_label,agent,sub_agent,api_partner'],
            'name' => ['required', 'string', 'max:191'],
            'legal_name' => ['nullable', 'string', 'max:255'],
            'subdomain' => ['nullable', 'string', 'max:100', 'unique:tenants,subdomain'],
            'max_sub_tenant_depth' => ['nullable', 'integer', 'min:0', 'max:20'],
        ]);

        $parent = Tenant::withoutTenantScope()->findOrFail($user->tenant_id);

        try {
            $tenant = $this->tenantService->createTenant($data, $parent);
        } catch (MaxHierarchyDepthExceededException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => new TenantResource($tenant)], 201);
    }
}
