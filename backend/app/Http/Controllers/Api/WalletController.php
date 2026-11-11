<?php

namespace App\Http\Controllers\Api;

use App\Domains\Payments\Models\PaymentTransaction;
use App\Domains\Wallet\Models\Wallet;
use App\Domains\Wallet\Models\WalletLedgerEntry;
use App\Domains\Wallet\Services\WalletService;
use App\Http\Controllers\Controller;
use App\Http\Resources\WalletResource;
use App\Models\User;
use Illuminate\Http\Request;

class WalletController extends Controller
{
    public function __construct(protected WalletService $walletService) {}

    public function me(Request $request)
    {
        return new WalletResource($this->resolveOwnWallet($request));
    }

    public function statement(Request $request)
    {
        $wallet = $this->resolveOwnWallet($request);

        $entries = WalletLedgerEntry::query()
            ->where('wallet_id', $wallet->id)
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 25));

        return response()->json($entries);
    }

    /**
     * Platform Roadmap Phase 7 — CommissionService credits an
     * OWNER_TENANT wallet (the reseller tenant itself, not any one user in
     * it) for commission earned on bookings it sells. Before this there was
     * no way for anyone to see that wallet at all — resolveOwnWallet() only
     * ever resolves an OWNER_USER wallet. A customer's tenant_id is just
     * whichever storefront they registered under, so customers are the one
     * role excluded here: it isn't their money to view.
     */
    public function tenantWallet(Request $request)
    {
        $this->authorizeTenantWalletAccess($request->user());

        return new WalletResource($this->resolveTenantWallet($request));
    }

    public function tenantStatement(Request $request)
    {
        $this->authorizeTenantWalletAccess($request->user());

        $wallet = $this->resolveTenantWallet($request);

        $entries = WalletLedgerEntry::query()
            ->where('wallet_id', $wallet->id)
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 25));

        return response()->json($entries);
    }

    /**
     * Demo top-up endpoint: records a successful payment_transaction (as if
     * a payment gateway webhook had just confirmed it — see the Payments
     * module specification's payment flow) and credits the wallet. Real
     * gateway callback handling replaces the "assume success" step once
     * Razorpay/PayU/Stripe credentials are configured.
     */
    public function topup(Request $request)
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
        ]);

        $wallet = $this->resolveOwnWallet($request);
        $amount = number_format((float) $data['amount'], 2, '.', '');

        $transaction = PaymentTransaction::create([
            'tenant_id' => $wallet->tenant_id,
            'wallet_id' => $wallet->id,
            'gateway' => config('services.payment_default.gateway'),
            'amount' => $amount,
            'status' => PaymentTransaction::STATUS_SUCCESS,
            'method' => 'wallet',
        ]);

        $entry = $this->walletService->credit(
            $wallet,
            $amount,
            WalletLedgerEntry::REF_TOPUP,
            $transaction->id,
            'Wallet top-up',
            $request->user()->id,
        );

        return response()->json([
            'wallet' => new WalletResource($wallet->fresh()),
            'ledger_entry_id' => $entry->id,
        ]);
    }

    protected function resolveOwnWallet(Request $request): Wallet
    {
        $user = $request->user();

        return Wallet::query()->firstOrCreate([
            'tenant_id' => $user->tenant_id,
            'owner_type' => Wallet::OWNER_USER,
            'owner_id' => $user->id,
        ]);
    }

    protected function authorizeTenantWalletAccess(User $user): void
    {
        abort_if($user->role === User::ROLE_CUSTOMER, 403, 'Customers do not have a tenant commission wallet.');
    }

    /**
     * Platform staff may pass ?tenant_id= to look up any tenant's
     * commission wallet (support/reporting), the same override
     * ReportsController's resolveTenantIds() grants them — everyone else
     * only ever resolves their own tenant's wallet, scoped by the normal
     * TenantScope global scope (their own TenantContext already matches).
     */
    protected function resolveTenantWallet(Request $request): Wallet
    {
        $user = $request->user();
        $tenantId = $user->tenant_id;
        $query = Wallet::query();

        if ($user->isPlatformStaff() && $request->filled('tenant_id')) {
            $tenantId = $request->integer('tenant_id');
            $query = Wallet::withoutTenantScope();
        }

        return $query->firstOrCreate([
            'tenant_id' => $tenantId,
            'owner_type' => Wallet::OWNER_TENANT,
            'owner_id' => $tenantId,
        ]);
    }
}
