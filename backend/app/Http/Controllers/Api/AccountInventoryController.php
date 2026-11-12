<?php

namespace App\Http\Controllers\Api;

use App\Domains\Settings\Services\SettingsService;
use App\Domains\Settings\Services\SiteControls;
use App\Domains\Suppliers\Services\CircuitBreaker;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Services\InventoryAccessService;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Accounts & inventory: one screen where the master account (and a
 * white-label partner, for the accounts below it) sees every account,
 * shares inventories and services with each one, and watches how each
 * account is selling.
 */
class AccountInventoryController extends Controller
{
    public const TYPE_LABELS = [
        Tenant::TYPE_WHITE_LABEL => 'White-label partner',
        Tenant::TYPE_AGENT => 'Agent (B2B)',
        Tenant::TYPE_SUB_AGENT => 'Sub-agent',
        Tenant::TYPE_API_PARTNER => 'API partner',
    ];

    protected const SOLD = ['confirmed', 'ticketed'];

    protected const CANCELLED = ['cancelled', 'refunded', 'refund_processing'];

    public function __construct(protected InventoryAccessService $access) {}

    /** Every account the signed-in admin looks after, with its inventories and sales. */
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user->hasPermission('tenant.view') && in_array($user->role, [User::ROLE_SUPER_ADMIN, User::ROLE_STAFF, User::ROLE_PARTNER_ADMIN], true), 403);

        $days = max(1, min(365, $request->integer('days', 30)));
        $since = now()->subDays($days);

        $q = $this->visibleAccounts($user)->with('parent:id,name,type');
        if ($t = $request->query('type')) {
            $q->where('type', $t);
        }
        if ($s = $request->query('status')) {
            $q->where('status', $s);
        }
        if ($term = trim((string) $request->query('q', ''))) {
            $q->where(fn ($w) => $w->where('name', 'like', "%{$term}%")->orWhere('custom_domain', 'like', "%{$term}%")->orWhere('subdomain', 'like', "%{$term}%"));
        }
        $accounts = $q->orderBy('type')->orderBy('name')->get();
        $ids = $accounts->pluck('id')->all();

        $own = $this->bookingStats($ids, $since);
        $network = $this->networkStats($ids, $since);
        $users = DB::table('users')->whereIn('tenant_id', $ids)->whereNull('deleted_at')->where('is_guest', false)
            ->groupBy('tenant_id')->pluck(DB::raw('count(*)'), 'tenant_id');
        $wallets = DB::table('wallets')->whereIn('tenant_id', $ids)->where('owner_type', 'tenant')
            ->groupBy('tenant_id')->pluck(DB::raw('sum(balance)'), 'tenant_id');

        $catalog = $this->catalogFor($user);
        $controls = app(SiteControls::class);

        $rows = $accounts->map(function (Tenant $t) use ($own, $network, $users, $wallets, $catalog, $controls, $user) {
            $o = $own[$t->id] ?? null;
            $n = $network[$t->id] ?? null;

            return [
                'id' => $t->id,
                'name' => $t->name,
                'type' => $t->type,
                'type_label' => self::TYPE_LABELS[$t->type] ?? $t->type,
                'parent' => $t->parent ? ['id' => $t->parent->id, 'name' => $t->parent->name, 'type' => $t->parent->type] : null,
                'status' => $t->status,
                'domain' => $this->domainOf($t),
                'dns_status' => $t->dns_status,
                'services' => array_values(array_filter(Tenant::ALL_MODULES, fn ($m) => $controls->moduleEnabled($t, $m))),
                'services_set' => $t->enabled_modules ?: null,
                'inventories' => $this->access->statusFor($t, $catalog),
                'can_manage' => $this->access->canManage($user, $t),
                'users' => (int) ($users[$t->id] ?? 0),
                'wallet_balance' => round((float) ($wallets[$t->id] ?? 0), 2),
                'bookings' => (int) ($o->bookings ?? 0),
                'sales' => round((float) ($o->sales ?? 0), 2),
                'cancelled' => (int) ($o->cancelled ?? 0),
                'last_booking_at' => $o->last_booking_at ?? null,
                'network_bookings' => (int) ($n->bookings ?? 0),
                'network_sales' => round((float) ($n->sales ?? 0), 2),
                'created_at' => $t->created_at?->toIso8601String(),
            ];
        });

        $summary = [];
        foreach (self::TYPE_LABELS as $type => $label) {
            $of = $rows->where('type', $type);
            $summary[] = [
                'type' => $type, 'label' => $label, 'accounts' => $of->count(),
                'active' => $of->where('status', Tenant::STATUS_ACTIVE)->count(),
                'bookings' => $of->sum('bookings'), 'sales' => round($of->sum('sales'), 2),
            ];
        }

        return response()->json([
            'data' => $rows->values(),
            'meta' => [
                'days' => $days,
                'catalog' => $catalog,
                'services' => Tenant::ALL_MODULES,
                'types' => collect(self::TYPE_LABELS)->map(fn ($label, $value) => compact('value', 'label'))->values(),
                'default_access' => $this->access->defaultAccess(),
                'summary' => $summary,
                'can_assign' => $user->hasPermission('inventory.assign'),
                'can_set_default' => $user->isPlatformStaff() && $user->hasPermission('inventory.assign'),
                'inventory_usage' => $this->inventoryUsage($catalog, $accounts, $since, $ids, $user),
            ],
        ]);
    }

    /** One account: its inventories and services, and what it sold from each. */
    public function show(Request $request, Tenant $tenant)
    {
        $user = $request->user();
        $this->authorizeView($user, $tenant);

        $days = max(1, min(365, $request->integer('days', 30)));
        $since = now()->subDays($days);
        $catalog = $this->catalogFor($user);

        $scope = fn () => DB::table('bookings')->where('tenant_id', $tenant->id)->where('created_at', '>=', $since)->whereNull('deleted_at');
        $bySupplier = $scope()->groupBy('supplier_code')->select('supplier_code', DB::raw('count(*) as bookings'),
            DB::raw("sum(case when status in ('confirmed','ticketed') then gross_amount else 0 end) as sales"))->get();
        $byService = $scope()->groupBy('module')->select('module', DB::raw('count(*) as bookings'),
            DB::raw("sum(case when status in ('confirmed','ticketed') then gross_amount else 0 end) as sales"))->get();
        $recent = DB::table('bookings')->where('tenant_id', $tenant->id)->whereNull('deleted_at')->orderByDesc('id')->limit(10)
            ->get(['id', 'booking_code', 'module', 'supplier_code', 'status', 'gross_amount', 'currency', 'created_at']);
        $children = Tenant::query()->where('parent_tenant_id', $tenant->id)->orderBy('name')->get(['id', 'name', 'type', 'status']);
        $usersByRole = DB::table('users')->where('tenant_id', $tenant->id)->whereNull('deleted_at')->where('is_guest', false)
            ->groupBy('role')->pluck(DB::raw('count(*)'), 'role');

        return response()->json(['data' => [
            'id' => $tenant->id,
            'name' => $tenant->name,
            'type' => $tenant->type,
            'type_label' => self::TYPE_LABELS[$tenant->type] ?? $tenant->type,
            'status' => $tenant->status,
            'domain' => $this->domainOf($tenant),
            'parent' => $tenant->parent ? ['id' => $tenant->parent->id, 'name' => $tenant->parent->name] : null,
            'services' => array_values(array_filter(Tenant::ALL_MODULES, fn ($m) => app(SiteControls::class)->moduleEnabled($tenant, $m))),
            'services_set' => $tenant->enabled_modules ?: null,
            'inventories' => $this->access->statusFor($tenant, $catalog),
            'can_manage' => $this->access->canManage($user, $tenant),
            'by_inventory' => $bySupplier,
            'by_service' => $byService,
            'recent_bookings' => $recent,
            'sub_accounts' => $children,
            'users_by_role' => $usersByRole,
            'days' => $days,
        ]]);
    }

    /**
     * Share inventories and services with one account, and suspend or
     * reactivate it. inventories: {key: true|false|null}; null puts the
     * inventory back to the default. services: list, or null for all.
     */
    public function update(Request $request, Tenant $tenant)
    {
        $user = $request->user();
        abort_unless($this->access->canManage($user, $tenant), 403, 'You cannot change this account.');

        $data = $this->validateChanges($request, $user, true);
        $this->apply($tenant, $data, $user);

        return $this->show($request, $tenant->fresh());
    }

    /** The same change for many accounts at once. */
    public function bulk(Request $request)
    {
        $user = $request->user();
        abort_unless($user->hasPermission('inventory.assign'), 403);

        $request->validate([
            'tenant_ids' => ['required', 'array', 'min:1', 'max:500'],
            'tenant_ids.*' => ['integer'],
        ]);
        $data = $this->validateChanges($request, $user, false);

        $done = 0;
        foreach (Tenant::query()->whereIn('id', $request->input('tenant_ids'))->get() as $tenant) {
            if (! $this->access->canManage($user, $tenant)) {
                continue;
            }
            $this->apply($tenant, $data, $user);
            $done++;
        }

        return response()->json(['data' => ['updated' => $done]]);
    }

    /** What an account with nothing set gets: every inventory, or none. */
    public function setDefault(Request $request)
    {
        $user = $request->user();
        abort_unless($user->isPlatformStaff() && $user->hasPermission('inventory.assign'), 403);
        $data = $request->validate(['default_access' => ['required', Rule::in(['all', 'none'])]]);

        app(SettingsService::class)->put('inventory', $data, $user->id);
        $this->access->bumpVersion();

        return response()->json(['data' => ['default_access' => $data['default_access']]]);
    }

    /**
     * For the signed-in account (portal, partner app, API partner) or the
     * site being visited: which services and inventories it can sell.
     */
    public function mine(Request $request, \App\Domains\Tenancy\Services\TenantContext $context)
    {
        $tenant = auth('sanctum')->user()?->tenant ?? $context->get();
        $controls = app(SiteControls::class);

        return response()->json(['data' => [
            'account' => $tenant ? ['id' => $tenant->id, 'name' => $tenant->name, 'type' => $tenant->type] : null,
            'services' => $tenant ? array_values(array_filter(Tenant::ALL_MODULES, fn ($m) => $controls->moduleEnabled($tenant, $m))) : Tenant::ALL_MODULES,
            'inventories' => $tenant ? collect($this->access->catalog())
                ->filter(fn ($inv) => $this->access->allows($tenant, $inv['key']))
                ->map(fn ($inv) => ['key' => $inv['key'], 'name' => $inv['name'], 'services' => $inv['modules']])->values() : [],
        ]]);
    }

    // ---------------------------------------------------------------

    protected function validateChanges(Request $request, User $user, bool $allowStatus): array
    {
        $keys = array_column($this->catalogFor($user), 'key');

        $data = $request->validate([
            'inventories' => ['sometimes', 'array'],
            'inventories.*' => ['nullable', 'boolean'],
            'services' => ['sometimes', 'nullable', 'array'],
            'services.*' => [Rule::in(Tenant::ALL_MODULES)],
            'status' => $allowStatus ? ['sometimes', Rule::in([Tenant::STATUS_ACTIVE, Tenant::STATUS_SUSPENDED])] : ['prohibited'],
        ]);

        foreach (array_keys($data['inventories'] ?? []) as $k) {
            if (! in_array($k, $keys, true)) {
                abort(422, "Unknown inventory '{$k}'.");
            }
        }

        return $data;
    }

    protected function apply(Tenant $tenant, array $data, User $user): void
    {
        DB::transaction(function () use ($tenant, $data, $user) {
            if (array_key_exists('inventories', $data)) {
                $this->access->set($tenant, $data['inventories'], $user);
            }
            if (array_key_exists('services', $data)) {
                $list = $data['services'];
                $tenant->enabled_modules = ($list === null || count(array_diff(Tenant::ALL_MODULES, $list)) === 0) ? null : array_values(array_unique($list));
            }
            if (array_key_exists('status', $data)) {
                $tenant->status = $data['status'];
            }
            if ($tenant->isDirty()) {
                $tenant->save();
            }
        });
    }

    /** Platform staff see every inventory; a partner admin only those their own account has. */
    protected function catalogFor(User $user): array
    {
        $catalog = $this->access->catalog();
        if ($user->isPlatformStaff()) {
            return $catalog;
        }
        $own = $user->tenant;

        return array_values(array_filter($catalog, fn ($inv) => $own && $this->access->allows($own, $inv['key'])));
    }

    protected function visibleAccounts(User $user)
    {
        $q = Tenant::query()->where('type', '!=', Tenant::TYPE_MASTER);
        if (! $user->isPlatformStaff()) {
            $q->whereIn('id', DB::table('tenant_hierarchy_closure')->where('ancestor_tenant_id', $user->tenant_id)->where('depth', '>', 0)->select('descendant_tenant_id'));
        }

        return $q;
    }

    protected function authorizeView(User $user, Tenant $tenant): void
    {
        abort_if($tenant->type === Tenant::TYPE_MASTER, 404);
        abort_unless($user->hasPermission('tenant.view'), 403);
        abort_unless($user->isPlatformStaff() || ($user->role === User::ROLE_PARTNER_ADMIN && $this->access->isBelow($tenant, (int) $user->tenant_id)), 403);
    }

    protected function bookingStats(array $ids, $since)
    {
        return DB::table('bookings')->whereIn('tenant_id', $ids)->where('created_at', '>=', $since)->whereNull('deleted_at')
            ->groupBy('tenant_id')->select('tenant_id', DB::raw('count(*) as bookings'),
                DB::raw("sum(case when status in ('confirmed','ticketed') then gross_amount else 0 end) as sales"),
                DB::raw("sum(case when status in ('cancelled','refunded','refund_processing') then 1 else 0 end) as cancelled"),
                DB::raw('max(created_at) as last_booking_at'))
            ->get()->keyBy('tenant_id');
    }

    /** The account plus every account below it. */
    protected function networkStats(array $ids, $since)
    {
        return DB::table('tenant_hierarchy_closure as c')->join('bookings as b', 'b.tenant_id', '=', 'c.descendant_tenant_id')
            ->whereIn('c.ancestor_tenant_id', $ids)->where('b.created_at', '>=', $since)->whereNull('b.deleted_at')
            ->groupBy('c.ancestor_tenant_id')->select('c.ancestor_tenant_id', DB::raw('count(*) as bookings'),
                DB::raw("sum(case when b.status in ('confirmed','ticketed') then b.gross_amount else 0 end) as sales"))
            ->get()->keyBy('ancestor_tenant_id');
    }

    /** Per inventory: how many accounts have it, what they sold, and whether the supplier is paused. */
    protected function inventoryUsage(array $catalog, $accounts, $since, array $ids, User $user): array
    {
        $sold = DB::table('bookings')->whereIn('tenant_id', $ids ?: [0])->where('created_at', '>=', $since)->whereNull('deleted_at')
            ->groupBy('supplier_code')->select('supplier_code', DB::raw('count(*) as bookings'),
                DB::raw("sum(case when status in ('confirmed','ticketed') then gross_amount else 0 end) as sales"))
            ->get()->keyBy(fn ($r) => strtoupper((string) $r->supplier_code));
        $breaker = app(CircuitBreaker::class);

        return array_map(function ($inv) use ($accounts, $sold, $breaker) {
            $with = $accounts->filter(fn ($t) => $this->access->allows($t, $inv['key']))->count();
            $s = $sold[strtoupper($inv['key'])] ?? null;

            return [
                'key' => $inv['key'],
                'name' => $inv['name'],
                'modules' => $inv['modules'],
                'accounts_with_access' => $with,
                'accounts_without' => $accounts->count() - $with,
                'bookings' => (int) ($s->bookings ?? 0),
                'sales' => round((float) ($s->sales ?? 0), 2),
                'paused' => $inv['kind'] === 'supplier' && $breaker->isOpen($inv['key']),
            ];
        }, $catalog);
    }

    protected function domainOf(Tenant $t): ?string
    {
        if ($t->custom_domain) {
            return $t->custom_domain;
        }

        return $t->subdomain ? ltrim($t->subdomain.config('tenancy.default_hosting_suffix'), '.') : null;
    }
}
