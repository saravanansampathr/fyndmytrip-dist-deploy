<?php

namespace App\Http\Controllers\Api;

use App\Domains\Payments\Models\PaymentTransaction;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Services\TenantService;
use App\Domains\Wallet\Models\Wallet;
use App\Http\Controllers\Concerns\FiltersByDateRangePreset;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Platform Roadmap Phase 4: "one payment history view... reused by every
 * role. What changes per role is scope, not layout" — the payment-side
 * counterpart to MyBookingsController (booking history) and WalletPage's
 * own statement (wallet ledger). Distinct from both: a booking's list row
 * shows the total charged but not how it was actually paid for (gateway,
 * method, wallet split, gateway status), and the wallet statement only
 * shows this user's own wallet ledger, not gateway-side payment attempts
 * (including ones that failed before ever touching the wallet).
 *
 * Every payment_transactions row is either tied to a booking (the normal
 * case — see PaymentGatewayService) or, for a wallet top-up, tied directly
 * to a wallet with no booking_id at all (see WalletController::topup()).
 * Scoping therefore checks both relations, mirroring MyBookingsController's
 * per-role split: an individual customer sees payments against bookings
 * THEY made plus top-ups into THEIR OWN wallet; agent/sub_agent/
 * partner_admin/staff_user see their tenant hierarchy's payments (same
 * resolveTenantIds() pattern as Reports/Support Tickets/MyBookings);
 * platform staff see everything unless they narrow with ?tenant_id=.
 */
class MyPaymentsController extends Controller
{
    use FiltersByDateRangePreset;

    public function __construct(protected TenantService $tenantService)
    {
    }

    public function index(Request $request)
    {
        $user = $request->user();

        [$from, $to] = $this->resolveDateRangePreset($request, default: 'custom');

        $baseQuery = PaymentTransaction::withoutTenantScope();

        if ($user->role === User::ROLE_CUSTOMER) {
            $baseQuery->where(function ($q) use ($user) {
                $q->whereHas('booking', fn ($b) => $b->where('created_by_user_id', $user->id))
                    ->orWhereHas('wallet', fn ($w) => $w->where('owner_type', Wallet::OWNER_USER)->where('owner_id', $user->id));
            });
        } else {
            $tenantIds = $this->resolveTenantIds($request, $user);
            $baseQuery->when($tenantIds !== null, fn ($q) => $q->whereIn('tenant_id', $tenantIds));
        }

        // Same "no date filter at all by default" choice as MyBookingsController.
        $dateFilterActive = $request->filled('range') || $request->filled('from') || $request->filled('to');
        if ($dateFilterActive) {
            $baseQuery->whereBetween('payment_transactions.created_at', [$from, $to]);
        }

        $query = $baseQuery
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('gateway'), fn ($q) => $q->where('gateway', $request->string('gateway')))
            ->when($request->filled('method'), fn ($q) => $q->where('method', $request->string('method')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%'.$request->string('search').'%';
                $q->where(function ($sub) use ($term) {
                    $sub->where('gateway_txn_id', 'like', $term)
                        ->orWhereHas('booking', fn ($b) => $b->where('booking_code', 'like', $term));
                });
            })
            ->with(['booking:id,booking_code,booking_reference,module'])
            ->orderByDesc('created_at');

        $payments = $query->paginate(min($request->integer('per_page', 25), 500));

        $payments->getCollection()->transform(function (PaymentTransaction $txn) {
            return [
                'id' => $txn->id,
                'purpose' => $txn->booking_id ? 'booking_payment' : 'topup',
                'booking_code' => $txn->booking?->booking_code,
                'booking_reference' => $txn->booking?->booking_reference,
                'module' => $txn->booking?->module,
                'gateway' => $txn->gateway,
                'gateway_txn_id' => $txn->gateway_txn_id,
                'method' => $txn->method,
                'amount' => (string) $txn->amount,
                'wallet_amount' => (string) $txn->wallet_amount,
                'currency' => $txn->currency,
                'charge_currency' => $txn->charge_currency,
                'charge_amount' => $txn->charge_amount !== null ? (string) $txn->charge_amount : null,
                'fx_rate' => $txn->fx_rate !== null ? (string) $txn->fx_rate : null,
                'status' => $txn->status,
                'created_at' => $txn->created_at,
            ];
        });

        return response()->json($payments->toArray());
    }

    /** Mirrors MyBookingsController::resolveTenantIds() — see its docblock. */
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
