<?php

namespace App\Domains\Booking\Services;

use App\Domains\Booking\Models\Booking;
use App\Domains\Booking\Models\BookingEvent;
use App\Domains\Booking\Models\RefundTransaction;
use App\Domains\Wallet\Models\Wallet;
use App\Domains\Wallet\Models\WalletLedgerEntry;
use App\Domains\Wallet\Services\WalletService;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * "Refund issuance, three ways: back through the original payment gateway,
 * a direct bank transfer, or credit to the customer's wallet — full or
 * partial, and staff can initiate any of these on a customer's behalf."
 * Drives Booking through the STATUS_REFUND_PROCESSING "in process" status
 * this phase adds — BookingStatusService is still the only thing that
 * actually writes bookings.status, this service just decides which
 * transition to make and when:
 *
 *   initiate() — TICKETED|CANCELLED -> REFUND_PROCESSING, records a
 *     RefundTransaction. A wallet refund is instant (nothing external to
 *     wait on), so it's completed in the very same call; gateway/
 *     bank_transfer refunds stay in REFUND_PROCESSING until staff confirm
 *     the money actually landed.
 *   complete() — REFUND_PROCESSING -> REFUNDED (terminal), once staff
 *     confirm the gateway/bank transfer actually went through.
 *   fail() — REFUND_PROCESSING -> whatever the booking was before
 *     (previous_booking_status), when the refund attempt didn't go through
 *     and needs to be retried or handled another way.
 */
class RefundService
{
    public function __construct(
        protected BookingStatusService $statusService,
        protected WalletService $walletService,
        protected CancellationPolicyService $cancellationPolicyService,
    ) {}

    /**
     * @param  array{bank_account_name?: string, bank_account_number?: string, bank_ifsc?: string, notes?: string}  $details
     *
     * @throws RuntimeException if the booking isn't in a state that can start a refund (TICKETED/CANCELLED)
     */
    public function initiate(Booking $booking, User $actingUser, string $method, string $amount, array $details = []): RefundTransaction
    {
        if (! in_array($method, RefundTransaction::METHODS, true)) {
            throw new InvalidArgumentException("Unknown refund method: {$method}");
        }

        if (BigDecimal::of($amount)->isGreaterThan(BigDecimal::of((string) $booking->total_amount))) {
            throw new InvalidArgumentException('Refund amount cannot exceed the booking total.');
        }

        $previousStatus = $booking->status;
        $policy = $this->cancellationPolicyService->bestPolicyFor($booking);

        return DB::transaction(function () use ($booking, $actingUser, $method, $amount, $details, $previousStatus, $policy) {
            $this->statusService->transition(
                $booking,
                Booking::STATUS_REFUND_PROCESSING,
                $actingUser,
                $details['notes'] ?? "Refund initiated ({$method}).",
            );

            $refund = RefundTransaction::query()->create([
                'booking_id' => $booking->id,
                'cancellation_policy_id' => $policy?->id,
                'method' => $method,
                'requested_amount' => $amount,
                'currency' => $booking->currency,
                'previous_booking_status' => $previousStatus,
                'bank_account_name' => $method === RefundTransaction::METHOD_BANK_TRANSFER ? ($details['bank_account_name'] ?? null) : null,
                'bank_account_number' => $method === RefundTransaction::METHOD_BANK_TRANSFER ? ($details['bank_account_number'] ?? null) : null,
                'bank_ifsc' => $method === RefundTransaction::METHOD_BANK_TRANSFER ? ($details['bank_ifsc'] ?? null) : null,
                'initiated_by_user_id' => $actingUser->id,
                'notes' => $details['notes'] ?? null,
            ]);

            BookingEvent::log(
                $booking->id,
                BookingEvent::TYPE_REFUND_INITIATED,
                "Refund initiated via {$method} for {$refund->currency} {$amount}.",
                $actingUser,
            );

            if ($method === RefundTransaction::METHOD_WALLET) {
                $this->completeWalletRefund($refund, $booking, $actingUser);
            }

            return $refund->fresh();
        });
    }

    /**
     * @throws RuntimeException if the refund has already been resolved (completed or failed)
     */
    public function complete(RefundTransaction $refund, User $actingUser, ?string $gatewayReference = null, ?string $notes = null): RefundTransaction
    {
        if ($refund->status !== RefundTransaction::STATUS_PROCESSING) {
            throw new RuntimeException("Refund #{$refund->id} has already been resolved ({$refund->status}).");
        }

        $booking = $refund->booking;

        return DB::transaction(function () use ($refund, $booking, $actingUser, $gatewayReference, $notes) {
            $this->markCompleted($refund, $booking, $actingUser, $gatewayReference, $notes);

            return $refund->fresh();
        });
    }

    /**
     * @throws RuntimeException if the refund has already been resolved (completed or failed)
     */
    public function fail(RefundTransaction $refund, User $actingUser, string $reason): RefundTransaction
    {
        if ($refund->status !== RefundTransaction::STATUS_PROCESSING) {
            throw new RuntimeException("Refund #{$refund->id} has already been resolved ({$refund->status}).");
        }

        $booking = $refund->booking;

        return DB::transaction(function () use ($refund, $booking, $actingUser, $reason) {
            $refund->update([
                'status' => RefundTransaction::STATUS_FAILED,
                'failure_reason' => $reason,
                'completed_by_user_id' => $actingUser->id,
                'completed_at' => now(),
            ]);

            $this->statusService->transition($booking, $refund->previous_booking_status, $actingUser, "Refund failed: {$reason}");

            BookingEvent::log(
                $booking->id,
                BookingEvent::TYPE_REFUND_FAILED,
                "Refund via {$refund->method} failed: {$reason}",
                $actingUser,
            );

            return $refund->fresh();
        });
    }

    /**
     * Wallet refunds have nothing external to wait on, so initiate() credits
     * the booking owner's wallet and resolves the refund in the same
     * request rather than leaving it sitting in "processing" for no reason.
     */
    protected function completeWalletRefund(RefundTransaction $refund, Booking $booking, User $actingUser): void
    {
        $wallet = Wallet::query()->firstOrCreate([
            'tenant_id' => $booking->tenant_id,
            'owner_type' => Wallet::OWNER_USER,
            'owner_id' => $booking->created_by_user_id,
        ]);

        $entry = $this->walletService->credit(
            $wallet,
            (string) $refund->requested_amount,
            WalletLedgerEntry::REF_REFUND,
            $booking->id,
            "Refund for booking {$booking->booking_reference}",
            $actingUser->id,
        );

        $refund->update(['wallet_ledger_entry_id' => $entry->id]);

        $this->markCompleted($refund, $booking, $actingUser, null);
    }

    protected function markCompleted(RefundTransaction $refund, Booking $booking, User $actingUser, ?string $gatewayReference, ?string $notes = null): void
    {
        $refund->update([
            'status' => RefundTransaction::STATUS_COMPLETED,
            'gateway_reference' => $gatewayReference ?? $refund->gateway_reference,
            'notes' => $notes ?? $refund->notes,
            'completed_by_user_id' => $actingUser->id,
            'completed_at' => now(),
        ]);

        $this->statusService->transition($booking, Booking::STATUS_REFUNDED, $actingUser, "Refund completed ({$refund->method}).");

        BookingEvent::log(
            $booking->id,
            BookingEvent::TYPE_REFUND_COMPLETED,
            "Refund of {$refund->currency} {$refund->requested_amount} completed via {$refund->method}.",
            $actingUser,
        );
    }
}
