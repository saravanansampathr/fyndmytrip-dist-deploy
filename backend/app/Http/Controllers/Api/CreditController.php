<?php

namespace App\Http\Controllers\Api;

use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Services\TenantService;
use App\Domains\Wallet\Models\Wallet;
use App\Domains\Wallet\Models\WalletCreditAuditLog;
use App\Domains\Wallet\Services\WalletNotificationService;
use App\Http\Controllers\Controller;
use App\Http\Resources\WalletCreditAuditLogResource;
use App\Http\Resources\WalletResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * "Enable credit system to partners, Agents, API, Whitelable, Sub Agents,
 * Corporates ... fix the limit by giving every week payment terms ... can
 * be cancelled or revoked or increased or decreased." Credit is modeled as
 * an extension of the SAME wallet a user already has (Wallet::debitFloor())
 * rather than a parallel ledger — see the 2024_02_01_000039 migration's
 * docblock and WalletService::postEntry(). This controller is the one path
 * that edits those credit fields; every change is journaled to
 * wallet_credit_audit_logs and emailed to the account owner
 * (WalletNotificationService::notifyCreditChange()).
 *
 * Deliberately per-USER, not per-tenant: booking payments always debit the
 * individual user's own OWNER_USER wallet (FlightBookingController::
 * confirm()), never a shared tenant wallet, so that's the wallet credit has
 * to live on for a debit to ever actually draw on it.
 */
class CreditController extends Controller
{
    public function __construct(protected WalletNotificationService $notifier, protected TenantService $tenantService) {}

    /**
     * List every user-owned wallet in the caller's tenant hierarchy (staff
     * see everything, or scope with ?tenant_id=), for the Credit admin
     * screen. ?with_credit_only=1 narrows to accounts that currently have
     * any credit granted.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $this->authorizeManage($user);

        $tenantIds = $this->resolveTenantIds($request, $user);

        $query = Wallet::withoutTenantScope()
            ->where('owner_type', Wallet::OWNER_USER)
            ->when($tenantIds !== null, fn ($q) => $q->whereIn('tenant_id', $tenantIds))
            ->when($request->boolean('with_credit_only'), fn ($q) => $q->where('credit_limit', '>', 0))
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%'.$request->string('search').'%';
                $q->whereHasMorph('owner', [User::class], fn ($u) => $u->where('name', 'like', $term)->orWhere('email', 'like', $term));
            })
            ->with(['owner' => fn ($q) => $q->select('id', 'name', 'email', 'role', 'tenant_id')])
            ->orderByDesc('credit_limit');

        $wallets = $query->paginate($request->integer('per_page', 25));

        return WalletResource::collection($wallets);
    }

    public function show(Request $request, User $targetUser)
    {
        $this->authorizeManage($request->user());

        $wallet = $this->resolveWallet($targetUser);

        return (new WalletResource($wallet))->additional([
            'credit_audit_logs' => WalletCreditAuditLogResource::collection($wallet->creditAuditLogs()->with('changedBy:id,name')->limit(20)->get()),
        ]);
    }

    public function update(Request $request, User $targetUser)
    {
        $this->authorizeManage($request->user());

        $data = $request->validate([
            'credit_limit' => ['required', 'numeric', 'gte:0'],
            'credit_terms' => ['required', Rule::in(Wallet::CREDIT_TERMS)],
            'credit_next_due_at' => ['nullable', 'date'],
            'credit_status' => ['required', Rule::in([Wallet::CREDIT_STATUS_ACTIVE, Wallet::CREDIT_STATUS_FROZEN])],
            'remarks' => ['nullable', 'string', 'max:255'],
        ]);

        $wallet = $this->resolveWallet($targetUser);
        $before = $this->notifier->snapshotCredit($wallet);

        $wallet = DB::transaction(function () use ($wallet, $data, $before) {
            $wallet->update([
                'credit_limit' => $data['credit_limit'],
                'credit_terms' => $data['credit_terms'],
                'credit_next_due_at' => $data['credit_next_due_at'] ?? null,
                'credit_status' => $data['credit_status'],
            ]);

            WalletCreditAuditLog::query()->create([
                'wallet_id' => $wallet->id,
                'tenant_id' => $wallet->tenant_id,
                'changed_by_user_id' => request()->user()->id,
                'old_credit_limit' => $before['credit_limit'],
                'new_credit_limit' => $wallet->credit_limit,
                'old_credit_terms' => $before['credit_terms'],
                'new_credit_terms' => $wallet->credit_terms,
                'old_credit_next_due_at' => $before['credit_next_due_at'],
                'new_credit_next_due_at' => $wallet->credit_next_due_at,
                'old_credit_status' => $before['credit_status'],
                'new_credit_status' => $wallet->credit_status,
                'remarks' => $data['remarks'] ?? null,
            ]);

            return $wallet;
        });

        // Outside the transaction and best-effort — same rationale as
        // WalletService::postEntry()'s identical placement.
        $this->notifier->notifyCreditChange($wallet, $before, $data['remarks'] ?? null);

        return new WalletResource($wallet);
    }

    protected function resolveWallet(User $targetUser): Wallet
    {
        return Wallet::withoutTenantScope()->firstOrCreate([
            'tenant_id' => $targetUser->tenant_id,
            'owner_type' => Wallet::OWNER_USER,
            'owner_id' => $targetUser->id,
        ]);
    }

    protected function authorizeManage(User $user): void
    {
        abort_unless($user->hasPermission('wallet.credit.manage'), 403, 'Missing required permission: wallet.credit.manage');
    }

    /** Same hierarchy-scoping pattern as SupportTicketController::resolveTenantIds(). */
    protected function resolveTenantIds(Request $request, User $user): ?array
    {
        $requestedTenantId = $request->filled('tenant_id') ? $request->integer('tenant_id') : null;

        if ($user->isPlatformStaff()) {
            if ($requestedTenantId === null) {
                return null;
            }

            $tenant = Tenant::query()->findOrFail($requestedTenantId);

            return $this->tenantService->getDescendantIdsRobust($tenant);
        }

        $ownTenant = Tenant::query()->findOrFail($user->tenant_id);

        return $this->tenantService->getDescendantIdsRobust($ownTenant);
    }
}
