<?php

namespace App\Http\Controllers\Api;

use App\Domains\Booking\Models\Booking;
use App\Domains\Booking\Models\BookingEvent;
use App\Domains\Booking\Models\RefundTransaction;
use App\Domains\Booking\Services\BookingStatusService;
use App\Domains\Booking\Services\CancellationPolicyService;
use App\Domains\Booking\Services\RefundService;
use App\Domains\Cashback\Models\CashbackCredit;
use App\Domains\Commission\Models\CommissionCredit;
use App\Domains\Suppliers\Services\SupplierOrchestrator;
use App\Domains\Suppliers\Services\BusSeatMapService;
use App\Domains\Suppliers\Services\FlightSeatMapService;
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
use Throwable;

/**
 * Self-service cancellation from My Trips, for the person who made the
 * booking (a customer, or an agent/partner for a booking they made).
 *
 * - An unpaid hold is simply released: nothing was charged.
 * - A paid booking is cancelled with the supplier, then the refund the
 *   cancellation policy allows goes straight back to the booker's wallet.
 *   Any cashback already credited for the booking is kept back from that
 *   refund, and commission paid to the selling account is reversed.
 *
 * Staff keep the fuller Reports flow (gateway / bank refunds, overrides).
 */
class MyBookingCancellationController extends Controller
{
    /** Statuses the booker can cancel from. */
    public const CANCELLABLE = [Booking::STATUS_HELD, Booking::STATUS_CONFIRMED, Booking::STATUS_TICKETED];

    public function __construct(
        protected CancellationPolicyService $policies,
        protected BookingStatusService $statusService,
        protected RefundService $refundService,
        protected WalletService $walletService,
        protected SupplierOrchestrator $orchestrator,
    ) {}

    public static function canCancel(Booking $booking, ?User $user): bool
    {
        if (! $user || (int) $booking->created_by_user_id !== (int) $user->id) {
            return false;
        }
        if (! in_array($booking->status, self::CANCELLABLE, true)) {
            return false;
        }

        // Once the trip has started it can no longer be cancelled online.
        return $booking->travel_date_start === null || ! $booking->travel_date_start->isBefore(now()->startOfDay());
    }

    public function quote(Request $request, int $booking)
    {
        $model = $this->ownBooking($request, $booking);

        return response()->json(['data' => $this->quoteFor($model)]);
    }

    public function cancel(Request $request, int $booking)
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);
        $user = $request->user();
        $model = $this->ownBooking($request, $booking);

        abort_unless(self::canCancel($model, $user), 422, $this->whyNot($model));

        $reason = 'Cancelled by the traveller from My Trips'.(! empty($data['reason']) ? ": {$data['reason']}" : '.');

        if ($model->status === Booking::STATUS_HELD) {
            $this->releaseWithSupplier($model, $user);
            $model = $this->statusService->transition($model, Booking::STATUS_CANCELLED, $user, $reason);

            return $this->respond($model, ['refund_amount' => '0.00', 'message' => 'Your booking hold has been cancelled. Nothing was charged.']);
        }

        $quote = $this->quoteFor($model);

        if (! $this->releaseWithSupplier($model, $user)) {
            return response()->json(['message' => 'We could not cancel this booking with the supplier right now. Please raise a support ticket and our team will cancel it for you.'], 422);
        }

        DB::transaction(function () use ($model, $user, $reason, $quote) {
            $this->statusService->transition($model, Booking::STATUS_CANCELLED, $user, $reason);
            $this->reverseCommission($model, $user);

            if (BigDecimal::of($quote['refund_amount'])->isGreaterThan(0)) {
                $this->refundService->initiate($model->fresh(), $user, RefundTransaction::METHOD_WALLET, $quote['refund_amount'], [
                    'notes' => "Self-service cancellation refund ({$quote['band_label']}).",
                ]);
            }
        });

        $refund = $quote['refund_amount'];
        $message = BigDecimal::of($refund)->isGreaterThan(0)
            ? "Booking cancelled. ₹{$refund} has been refunded to your wallet."
            : 'Booking cancelled. No refund is due under the cancellation policy.';

        return $this->respond($model->fresh(), ['refund_amount' => $refund, 'message' => $message]);
    }

    /**
     * The policy quote, less any cashback already credited for this booking.
     */
    protected function quoteFor(Booking $booking): array
    {
        if ($booking->status === Booking::STATUS_HELD) {
            return [
                'cancellable' => self::canCancel($booking, request()->user()),
                'paid' => false,
                'refund_amount' => '0.00',
                'fee_amount' => '0.00',
                'cashback_deducted' => '0.00',
                'band_label' => 'Not paid yet',
                'policy_name' => null,
                'currency' => $booking->currency ?? 'INR',
                'reason' => self::canCancel($booking, request()->user()) ? null : $this->whyNot($booking),
            ];
        }

        $booking->loadMissing('segments');
        $quote = $this->policies->quote($booking);
        $cashback = BigDecimal::of((string) (CashbackCredit::query()->where('booking_id', $booking->id)->sum('amount') ?: '0'));
        $refund = BigDecimal::of($quote['refund_amount'])->minus($cashback);
        if ($refund->isNegative()) {
            $refund = BigDecimal::zero();
        }
        $total = BigDecimal::of((string) $booking->total_amount);

        return array_merge($quote, [
            'cancellable' => self::canCancel($booking, request()->user()),
            'paid' => true,
            'cashback_deducted' => (string) $cashback->toScale(2, RoundingMode::HALF_UP),
            'refund_amount' => (string) $refund->toScale(2, RoundingMode::HALF_UP),
            'fee_amount' => (string) $total->minus($refund)->toScale(2, RoundingMode::HALF_UP),
            'reason' => self::canCancel($booking, request()->user()) ? null : $this->whyNot($booking),
        ]);
    }

    protected function ownBooking(Request $request, int $id): Booking
    {
        $booking = Booking::withoutTenantScope()->findOrFail($id);
        abort_unless((int) $booking->created_by_user_id === (int) $request->user()->id, 403, 'You can only cancel bookings you made.');

        return $booking;
    }

    protected function whyNot(Booking $booking): string
    {
        return match (true) {
            in_array($booking->status, [Booking::STATUS_CANCELLED, Booking::STATUS_REFUND_PROCESSING, Booking::STATUS_REFUNDED], true) => 'This booking is already cancelled.',
            $booking->status === Booking::STATUS_FAILED => 'This booking did not go through, so there is nothing to cancel.',
            in_array($booking->status, self::CANCELLABLE, true) => 'This trip has already started, so it can no longer be cancelled online. Please raise a support ticket.',
            default => 'This booking cannot be cancelled online.',
        };
    }

    /**
     * Cancel each supplier booking behind this one (one per flight leg).
     * Packages, visas and our own inventory have no external supplier
     * booking. Returns false when a supplier refuses or errors.
     */
    protected function releaseWithSupplier(Booking $booking, User $user): bool
    {
        if (in_array($booking->module, [Booking::MODULE_FLIGHT], true)) {
            app(FlightSeatMapService::class)->releaseForBooking($booking->id);
        }
        if ($booking->module === Booking::MODULE_BUS) {
            app(BusSeatMapService::class)->releaseForBooking($booking->id);
        }

        if (in_array($booking->module, [Booking::MODULE_HOLIDAY, Booking::MODULE_VISA], true)) {
            return true;
        }

        $refs = $booking->metadata['leg_supplier_refs'] ?? [];
        if (! $refs && $booking->supplier_code && $booking->supplier_booking_ref) {
            $refs = [['supplier_code' => $booking->supplier_code, 'supplier_booking_ref' => $booking->supplier_booking_ref]];
        }

        foreach ($refs as $ref) {
            if (empty($ref['supplier_code']) || empty($ref['supplier_booking_ref']) || $ref['supplier_code'] === 'OWN_INVENTORY') {
                continue;
            }
            try {
                $ok = $this->orchestrator->cancel($booking->tenant, $ref['supplier_code'], $ref['supplier_booking_ref']);
            } catch (Throwable $e) {
                $ok = false;
            }
            if (! $ok) {
                BookingEvent::log($booking->id, BookingEvent::TYPE_SUPPLIER_ERROR, "Supplier {$ref['supplier_code']} did not cancel {$ref['supplier_booking_ref']} for a self-service cancellation.", $user);

                return false;
            }
        }

        return true;
    }

    protected function reverseCommission(Booking $booking, User $user): void
    {
        $credit = CommissionCredit::query()->where('booking_id', $booking->id)->first();
        if (! $credit || BigDecimal::of((string) $credit->amount)->isLessThanOrEqualTo(0)) {
            return;
        }

        $wallet = Wallet::withoutTenantScope()->find(WalletLedgerEntry::query()->whereKey($credit->wallet_ledger_entry_id)->value('wallet_id'));
        try {
            if (! $wallet) {
                throw new \RuntimeException('wallet not found');
            }
            $this->walletService->debit($wallet, (string) $credit->amount, WalletLedgerEntry::REF_ADJUSTMENT, $booking->id, "Commission reversed: booking {$booking->booking_reference} was cancelled", $user->id);
            $booking->update(['commission_amount' => 0]);
        } catch (Throwable $e) {
            BookingEvent::log($booking->id, BookingEvent::TYPE_MANUAL_NOTE, "Commission of {$credit->amount} could not be reversed automatically ({$e->getMessage()}). Please adjust the partner wallet.", $user);
        }
    }

    protected function respond(Booking $booking, array $extra)
    {
        return response()->json(array_merge(['data' => new BookingResource($booking->load(['passengers', 'segments']))], $extra));
    }
}
