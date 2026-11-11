<?php

namespace App\Http\Controllers\Api;

use App\Domains\Booking\Models\Booking;
use App\Domains\Booking\Services\BookingStatusService;
use App\Domains\Cashback\Services\CashbackService;
use App\Domains\Commission\Services\CommissionService;
use App\Domains\Payments\Models\PaymentMethodRoute;
use App\Domains\Payments\Models\PaymentTransaction;
use App\Domains\Payments\Services\PaymentGatewayService;
use App\Domains\Wallet\Exceptions\InsufficientBalanceException;
use App\Domains\Wallet\Models\Wallet;
use App\Domains\Wallet\Models\WalletLedgerEntry;
use App\Domains\Wallet\Services\WalletService;
use App\Http\Controllers\Controller;
use App\Http\Resources\BookingResource;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Payment for bookings sold from the platform's own catalogue: holiday
 * packages and visa applications. HolidayBookingController::store() only
 * ever held a package booking: there was no way for the traveller to pay
 * for it, so every package booking stayed "held" with nothing collected.
 *
 * Same wallet / gateway / wallet+gateway split as every other module's
 * confirm()+payGateway(). A paid package booking becomes CONFIRMED (the
 * operations team then issues the vouchers); there is no supplier ticketing
 * call because packages are sold from the platform's own catalogue.
 */
class CatalogBookingPaymentController extends Controller
{
    public function __construct(
        protected WalletService $walletService,
        protected BookingStatusService $statusService,
        protected PaymentGatewayService $paymentGatewayService,
        protected CashbackService $cashbackService,
        protected CommissionService $commissionService,
    ) {}

    public function confirm(Request $request, Booking $booking)
    {
        $user = $request->user();
        $this->guard($booking, $user);

        if ($booking->status !== Booking::STATUS_HELD) {
            return response()->json(['message' => "Booking must be 'held' to pay; it is currently '{$booking->status}'."], 409);
        }

        $data = $request->validate([
            'wallet_amount' => ['nullable', 'numeric', 'min:0'],
        ]);

        $payable = BigDecimal::of($booking->recalculateTotal());
        $requested = BigDecimal::of((string) ($data['wallet_amount'] ?? '0'));

        if ($requested->isGreaterThan($payable)) {
            return response()->json(['message' => 'Wallet amount cannot exceed the payable amount.'], 422);
        }

        $wallet = Wallet::query()->firstOrCreate([
            'tenant_id' => $booking->tenant_id,
            'owner_type' => Wallet::OWNER_USER,
            'owner_id' => $user->id,
        ]);

        if ($requested->isGreaterThan(0) && $requested->isGreaterThan(BigDecimal::of($wallet->balance))) {
            return response()->json([
                'message' => 'Wallet amount exceeds your available balance.',
                'wallet_balance' => (string) $wallet->balance,
            ], 402);
        }

        $walletAmount = (string) $requested->toScale(2, RoundingMode::HALF_UP);
        $remainder = $payable->minus($requested)->toScale(2, RoundingMode::HALF_UP);

        if (! $remainder->isPositive()) {
            try {
                DB::transaction(function () use ($booking, $wallet, $payable, $walletAmount, $user) {
                    $this->walletService->debit($wallet, $walletAmount, WalletLedgerEntry::REF_BOOKING_PAYMENT, $booking->id, "Payment for booking {$booking->booking_reference}", $user->id);
                    $booking->total_amount = (string) $payable;
                    $booking->save();
                });
            } catch (InsufficientBalanceException $e) {
                return response()->json(['message' => $e->getMessage()], 402);
            }

            return $this->finalize($booking, $user, 'Wallet payment captured.');
        }

        $transaction = $this->paymentGatewayService->initiate($booking, (string) $remainder, $walletAmount, $requested->isGreaterThan(0) ? $wallet : null);

        return response()->json([
            'requires_payment' => true,
            'data' => new BookingResource($booking->fresh(['passengers', 'segments'])),
            'payment' => [
                'transaction_id' => $transaction->id,
                'gateway' => $transaction->gateway,
                'amount' => $transaction->amount,
                'wallet_amount_applied' => $walletAmount,
                'currency' => $transaction->currency,
                'available_methods' => $this->paymentGatewayService->availableMethods($booking->tenant),
            ],
        ]);
    }

    public function payGateway(Request $request, Booking $booking)
    {
        $user = $request->user();
        $this->guard($booking, $user);

        if ($booking->status !== Booking::STATUS_HELD) {
            return response()->json(['message' => "Booking must be 'held' to pay; it is currently '{$booking->status}'."], 409);
        }

        $transaction = PaymentTransaction::query()
            ->where('booking_id', $booking->id)
            ->where('status', PaymentTransaction::STATUS_INITIATED)
            ->latest()
            ->first();

        if (! $transaction) {
            return response()->json(['message' => 'No pending payment was found for this booking. Call confirm first.'], 409);
        }

        $method = $request->validate([
            'method' => ['required', 'string', 'in:'.implode(',', PaymentMethodRoute::METHODS)],
        ])['method'];
        $payable = $booking->recalculateTotal();

        try {
            DB::transaction(function () use ($booking, $transaction, $payable, $user, $method) {
                $walletAmount = BigDecimal::of((string) $transaction->wallet_amount);
                if ($walletAmount->isGreaterThan(0)) {
                    $wallet = $transaction->wallet_id ? Wallet::withoutTenantScope()->find($transaction->wallet_id) : null;
                    abort_if(! $wallet, 500, 'Wallet leg on this payment could not be resolved.');
                    $this->walletService->debit($wallet, (string) $walletAmount, WalletLedgerEntry::REF_BOOKING_PAYMENT, $booking->id, "Payment for booking {$booking->booking_reference}", $user->id);
                }

                $this->paymentGatewayService->settle($transaction, $booking->tenant, $method);
                $booking->total_amount = $payable;
                $booking->save();
            });
        } catch (InsufficientBalanceException $e) {
            return response()->json(['message' => $e->getMessage()], 402);
        }

        return $this->finalize($booking, $user, "Payment gateway ({$transaction->gateway}) + wallet captured.");
    }

    protected function finalize(Booking $booking, User $user, string $note)
    {
        $this->statusService->transition($booking, Booking::STATUS_CONFIRMED, $user, $note);
        $this->cashbackService->applyCashback($booking, $user);
        $this->commissionService->applyCommission($booking);

        return new BookingResource($booking->fresh(['passengers', 'segments']));
    }

    /** Only the traveller who booked it (or platform staff) pays for it. */
    protected function guard(Booking $booking, User $user): void
    {
        abort_unless(in_array($booking->module, [Booking::MODULE_HOLIDAY, Booking::MODULE_VISA], true), 404);
        abort_unless(
            (int) $booking->created_by_user_id === (int) $user->id || in_array($user->role, [User::ROLE_SUPER_ADMIN, User::ROLE_STAFF], true),
            403,
            'You can only pay for your own booking.'
        );
    }
}
