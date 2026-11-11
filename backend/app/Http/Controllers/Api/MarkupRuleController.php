<?php

namespace App\Http\Controllers\Api;

use App\Domains\Booking\Models\Booking;
use App\Domains\Suppliers\Models\MarkupRule;
use App\Domains\Suppliers\Services\MarkupService;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Services\TenantService;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Margin (markup) rules CRM — "keep the markup for each Agent and
 * Whitelabel API, either fixed per PNR or PAX or Destination or percentage
 * wise" and "margins keeping options needed for all users including admins
 * and they can choose the users too or service".
 *
 * Who can manage: anyone holding markup.manage (super_admin implicitly;
 * staff, partner_admin, agent and api_user by default — see
 * RolePermissionSeeder). Sub-agents get markup.view only: they are logins
 * under their Agent's own tenant, so write access would let them change the
 * Agent's margins. Direct customers get neither.
 *
 * What a rule can target:
 *  - service: one module, or 'all';
 *  - audience (applies_to_role): b2c, agent, sub_agent, white_label,
 *    api_partner, staff, admin, or all;
 *  - optionally one specific user (user_id) and/or one specific account
 *    (target_tenant_id) — restricted to the rule owner's own tenant or its
 *    descendants, so an agent can only target its own sub-agents/customers;
 *  - optionally a supplier, a domestic/international scope, and a route.
 *
 * Platform staff can manage any tenant's rules (owner picked by
 * tenant_id); everyone else only their own tenant's. MarkupService is what
 * actually resolves these against a booking — /markup-rules/preview runs
 * that same resolution for a chosen user/account without booking anything.
 */
class MarkupRuleController extends Controller
{
    public const PERMISSION = 'markup.manage';

    /** Read-only access — sub-agents share their Agent's tenant, so they may see but not change its margins. */
    public const VIEW_PERMISSION = 'markup.view';

    public const MODULES = [
        Booking::MODULE_FLIGHT, Booking::MODULE_HOTEL, Booking::MODULE_BUS, Booking::MODULE_CAB,
        Booking::MODULE_HOLIDAY, Booking::MODULE_INSURANCE, Booking::MODULE_VISA, Booking::MODULE_CRUISE,
        Booking::MODULE_ACTIVITY,
    ];

    public const MODULE_LABELS = [
        MarkupRule::MODULE_ALL => 'All services',
        Booking::MODULE_FLIGHT => 'Flights',
        Booking::MODULE_HOTEL => 'Hotels',
        Booking::MODULE_BUS => 'Buses',
        Booking::MODULE_CAB => 'Cabs',
        Booking::MODULE_HOLIDAY => 'Holidays',
        Booking::MODULE_INSURANCE => 'Insurance',
        Booking::MODULE_VISA => 'Visa',
        Booking::MODULE_CRUISE => 'Cruises',
        Booking::MODULE_ACTIVITY => 'Activities',
    ];

    public const TYPE_LABELS = [
        MarkupRule::TYPE_PERCENTAGE => 'Percentage of net fare',
        MarkupRule::TYPE_FLAT_PER_PNR => 'Flat per booking (PNR)',
        MarkupRule::TYPE_FLAT_PER_PAX => 'Flat per passenger',
        MarkupRule::TYPE_FLAT_PER_DESTINATION => 'Flat per destination / route',
    ];

    public function __construct(
        protected TenantService $tenantService,
        protected MarkupService $markupService,
    ) {}

    public function index(Request $request)
    {
        $user = $this->authorizeView($request);

        $filters = $request->validate([
            'tenant_id' => ['nullable', 'integer'],
            'module' => ['nullable', 'string', 'max:20'],
            'applies_to_role' => ['nullable', 'string', 'max:20'],
            'user_id' => ['nullable', 'integer'],
            'target_tenant_id' => ['nullable', 'integer'],
        ]);

        $rules = MarkupRule::withoutTenantScope()
            ->with(['user:id,name,email,role,tenant_id', 'targetTenant:id,name,type', 'owner:id,name,type'])
            ->when(! $user->isPlatformStaff(), fn ($q) => $q->where('tenant_id', $user->tenant_id))
            ->when($user->isPlatformStaff() && ! empty($filters['tenant_id']), fn ($q) => $q->where('tenant_id', $filters['tenant_id']))
            ->when(! empty($filters['module']), fn ($q) => $q->where('module', $filters['module']))
            ->when(! empty($filters['applies_to_role']), fn ($q) => $q->where('applies_to_role', $filters['applies_to_role']))
            ->when(! empty($filters['user_id']), fn ($q) => $q->where('user_id', $filters['user_id']))
            ->when(! empty($filters['target_tenant_id']), fn ($q) => $q->where('target_tenant_id', $filters['target_tenant_id']))
            ->orderBy('module')
            ->orderBy('applies_to_role')
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['data' => $rules]);
    }

    public function store(Request $request)
    {
        $user = $this->authorizeManage($request);
        $data = $this->validated($request, $user);

        $rule = MarkupRule::withoutTenantScope()->create($data);

        return response()->json(['data' => $this->present($rule)], 201);
    }

    public function update(Request $request, MarkupRule $markupRule)
    {
        $user = $this->authorizeManage($request);
        $this->authorizeAccess($user, $markupRule);

        $data = $this->validated($request, $user, $markupRule);
        $markupRule->update($data);

        return response()->json(['data' => $this->present($markupRule->fresh())]);
    }

    public function destroy(Request $request, MarkupRule $markupRule)
    {
        $user = $this->authorizeManage($request);
        $this->authorizeAccess($user, $markupRule);

        $markupRule->delete();

        return response()->json(null, 204);
    }

    /**
     * Everything the margin form needs: services, audiences, rule types,
     * scopes, which tenants the caller may own rules for, and which
     * accounts / users it may target (searchable with ?q=).
     */
    public function options(Request $request)
    {
        $user = $this->authorizeView($request);
        $q = trim((string) $request->query('q', ''));

        $scopeIds = $this->manageableTenantIds($user);

        $tenants = Tenant::query()
            ->when($scopeIds !== null, fn ($query) => $query->whereIn('id', $scopeIds))
            ->when($q !== '', fn ($query) => $query->where('name', 'like', "%{$q}%"))
            ->orderBy('name')
            ->limit(200)
            ->get(['id', 'name', 'type', 'parent_tenant_id']);

        $users = User::query()
            ->when($scopeIds !== null, fn ($query) => $query->whereIn('tenant_id', $scopeIds))
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w
                ->where('name', 'like', "%{$q}%")
                ->orWhere('email', 'like', "%{$q}%")))
            ->with('tenant:id,name,type')
            ->orderBy('name')
            ->limit(200)
            ->get(['id', 'name', 'email', 'role', 'tenant_id']);

        $ownerTenants = $user->isPlatformStaff()
            ? $tenants->map->only(['id', 'name', 'type'])->values()
            : collect([$user->tenant?->only(['id', 'name', 'type'])])->filter()->values();

        return response()->json(['data' => [
            'modules' => collect(self::MODULE_LABELS)->map(fn ($label, $value) => compact('value', 'label'))->values(),
            'audiences' => collect(MarkupRule::ROLE_LABELS)->map(fn ($label, $value) => compact('value', 'label'))->values(),
            'rule_types' => collect(self::TYPE_LABELS)->map(fn ($label, $value) => compact('value', 'label'))->values(),
            'booking_scopes' => MarkupRule::BOOKING_SCOPES,
            'can_manage' => $user->hasPermission(self::PERMISSION),
            'can_choose_owner' => $user->isPlatformStaff(),
            'owner_tenants' => $ownerTenants,
            'target_tenants' => $tenants->map->only(['id', 'name', 'type', 'parent_tenant_id'])->values(),
            'users' => $users->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'role' => $u->role,
                'tenant_id' => $u->tenant_id,
                'tenant_name' => $u->tenant?->name,
            ])->values(),
        ]]);
    }

    /**
     * Dry-run the margin a chosen user / account would get on a given net
     * amount — "what will agent X pay on a ₹10,000 flight?" — without
     * creating a booking.
     */
    public function preview(Request $request)
    {
        $user = $this->authorizeView($request);

        $data = $request->validate([
            'module' => ['required', 'in:'.implode(',', self::MODULES)],
            'net_amount' => ['required', 'numeric', 'min:0'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'tenant_id' => ['nullable', 'integer', 'exists:tenants,id'],
            'passenger_count' => ['nullable', 'integer', 'min:1', 'max:99'],
            'origin' => ['nullable', 'string', 'max:10'],
            'destination' => ['nullable', 'string', 'max:10'],
            'is_international' => ['nullable', 'boolean'],
            'supplier_code' => ['nullable', 'string', 'max:40'],
        ]);

        $scopeIds = $this->manageableTenantIds($user);
        $subject = isset($data['user_id']) ? User::query()->findOrFail($data['user_id']) : null;
        $tenant = $subject?->tenant
            ?? (isset($data['tenant_id']) ? Tenant::query()->findOrFail($data['tenant_id']) : $user->tenant);

        abort_if($scopeIds !== null && ! in_array((int) $tenant->id, $scopeIds, true), 403, 'That user or account is outside your hierarchy.');

        $result = $this->markupService->resolve(
            $tenant,
            $data['module'],
            $this->markupService->legacyRoleFor($subject),
            number_format((float) $data['net_amount'], 2, '.', ''),
            [
                'user' => $subject,
                'passenger_count' => $data['passenger_count'] ?? 1,
                'origin' => $data['origin'] ?? null,
                'destination' => $data['destination'] ?? null,
                'is_international' => (bool) ($data['is_international'] ?? false),
                'supplier_code' => $data['supplier_code'] ?? null,
            ],
        );

        return response()->json(['data' => [
            'net_amount' => number_format((float) $data['net_amount'], 2, '.', ''),
            'markup_amount' => $result['markup_amount'],
            'gross_amount' => $result['gross_amount'],
            'audience' => $result['audience'],
            'applied_value' => $result['value'],
            'capped_by_parent' => $result['capped'],
            'rule' => $result['rule'] ? $this->present($result['rule']) : null,
            'tenant' => $tenant->only(['id', 'name', 'type']),
        ]]);
    }

    protected function present(MarkupRule $rule): MarkupRule
    {
        return $rule->load(['user:id,name,email,role,tenant_id', 'targetTenant:id,name,type', 'owner:id,name,type']);
    }

    protected function authorizeView(Request $request): User
    {
        $user = $request->user();
        abort_unless($user && $user->hasAnyPermission([self::PERMISSION, self::VIEW_PERMISSION]), 403, 'Missing required permission: '.self::VIEW_PERMISSION);

        return $user;
    }

    protected function authorizeManage(Request $request): User
    {
        $user = $request->user();
        abort_unless($user && $user->hasPermission(self::PERMISSION), 403, 'Missing required permission: '.self::PERMISSION);

        return $user;
    }

    protected function authorizeAccess(User $user, MarkupRule $rule): void
    {
        if ($user->isPlatformStaff()) {
            return;
        }

        abort_if($rule->tenant_id !== $user->tenant_id, 403, 'You cannot manage another tenant\'s markup rule.');
    }

    /**
     * Tenant ids the caller may see/target: null = everything (platform
     * staff), otherwise its own tenant plus every descendant.
     *
     * @return array<int>|null
     */
    protected function manageableTenantIds(User $user): ?array
    {
        if ($user->isPlatformStaff()) {
            return null;
        }

        return array_map('intval', $this->tenantService->getDescendantIdsRobust($user->tenant));
    }

    protected function validated(Request $request, User $user, ?MarkupRule $existing = null): array
    {
        $modules = array_merge([MarkupRule::MODULE_ALL], self::MODULES);

        $data = $request->validate([
            'tenant_id' => ['nullable', 'integer', 'exists:tenants,id'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'target_tenant_id' => ['nullable', 'integer', 'exists:tenants,id'],
            'name' => ['nullable', 'string', 'max:191'],
            'module' => ['required', 'in:'.implode(',', $modules)],
            'rule_type' => ['required', 'in:'.implode(',', MarkupRule::TYPES)],
            'applies_to_role' => ['required', 'in:'.implode(',', MarkupRule::ROLES)],
            'percentage' => ['required_if:rule_type,percentage', 'nullable', 'numeric', 'min:0', 'max:100'],
            'amount' => ['required_unless:rule_type,percentage', 'nullable', 'numeric', 'min:0'],
            'origin' => ['nullable', 'string', 'max:10'],
            'destination' => ['required_if:rule_type,flat_per_destination', 'nullable', 'string', 'max:10'],
            'booking_scope' => ['nullable', 'in:'.implode(',', MarkupRule::BOOKING_SCOPES)],
            'supplier_code' => ['nullable', 'string', 'max:40'],
            'is_active' => ['nullable', 'boolean'],
            'valid_from' => ['nullable', 'date'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:valid_from'],
        ]);

        $config = $data['rule_type'] === MarkupRule::TYPE_PERCENTAGE
            ? ['percentage' => number_format((float) $data['percentage'], 2, '.', '')]
            : ['amount' => number_format((float) $data['amount'], 2, '.', '')];

        $result = [
            'module' => $data['module'],
            'rule_type' => $data['rule_type'],
            'applies_to_role' => $data['applies_to_role'],
            'config' => $config,
            'origin' => $data['rule_type'] === MarkupRule::TYPE_FLAT_PER_DESTINATION
                ? strtoupper($data['origin'] ?? '') ?: null
                : null,
            'destination' => $data['rule_type'] === MarkupRule::TYPE_FLAT_PER_DESTINATION
                ? strtoupper($data['destination']) : null,
            'name' => $data['name'] ?? null,
            'booking_scope' => $data['booking_scope'] ?? MarkupRule::SCOPE_BOTH,
            'supplier_code' => isset($data['supplier_code']) && trim($data['supplier_code']) !== ''
                ? strtoupper(trim($data['supplier_code'])) : null,
            'is_active' => $data['is_active'] ?? true,
            'valid_from' => $data['valid_from'] ?? null,
            // An end date without a time means "to the end of that day".
            'valid_until' => isset($data['valid_until']) ? (strlen($data['valid_until']) <= 10 ? \Carbon\Carbon::parse($data['valid_until'])->endOfDay() : $data['valid_until']) : null,
            'user_id' => $data['user_id'] ?? null,
            'target_tenant_id' => $data['target_tenant_id'] ?? null,
        ];

        // Non-platform-staff only ever manage their own tenant's rules;
        // platform staff pick the owner (or keep the existing one).
        $result['tenant_id'] = $user->isPlatformStaff()
            ? ($data['tenant_id'] ?? $existing->tenant_id ?? $user->tenant_id)
            : $user->tenant_id;

        // A target must sit at or below the rule's owner — otherwise the
        // rule could never match (MarkupService only walks UP the chain).
        $owner = Tenant::query()->findOrFail($result['tenant_id']);
        $ownerScope = array_map('intval', $this->tenantService->getDescendantIdsRobust($owner));

        if ($result['target_tenant_id'] !== null && ! in_array((int) $result['target_tenant_id'], $ownerScope, true)) {
            throw ValidationException::withMessages(['target_tenant_id' => ['The chosen account is not under the rule owner\'s hierarchy.']]);
        }

        if ($result['user_id'] !== null) {
            $target = User::query()->findOrFail($result['user_id']);

            if (! in_array((int) $target->tenant_id, $ownerScope, true)) {
                throw ValidationException::withMessages(['user_id' => ['The chosen user is not under the rule owner\'s hierarchy.']]);
            }

            if ($result['target_tenant_id'] !== null && (int) $target->tenant_id !== (int) $result['target_tenant_id']) {
                throw ValidationException::withMessages(['user_id' => ['The chosen user does not belong to the chosen account.']]);
            }
        }

        return $result;
    }
}
