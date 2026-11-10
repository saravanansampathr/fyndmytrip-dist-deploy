<?php

namespace App\Domains\Booking\Services;

use App\Domains\Booking\Models\Booking;
use App\Domains\Booking\Models\BookingAmendment;
use App\Domains\Booking\Models\BookingEvent;
use App\Domains\Wallet\Models\Wallet;
use App\Domains\Wallet\Models\WalletLedgerEntry;
use App\Domains\Wallet\Services\WalletService;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Phase 2's last bullet: "Reschedule / date change / reissue as
 * first-class actions, not just cancel: same policy-driven fee
 * calculation, applied when changing dates or reissuing a ticket." Both
 * actions reuse CancellationPolicyService::quote()'s existing band
 * calculation for the fee — "how much would be forfeited cancelling right
 * now" is exactly what's charged for changing the booking instead of
 * cancelling it, computed off the booking's CURRENT travel date (before
 * the change is applied). Only TICKETED bookings can be amended — a
 * cart/held/confirmed booking isn't a real ticket yet, and a cancelled/
 * refunded one is done.
 */
class BookingAmendmentService
{
    public function __construct(
        protected CancellationPolicyService $cancellationPolicyService,
        protected WalletService $walletService,
    ) {}

    /**
     * @throws RuntimeException if the booking isn't TICKETED
     * @throws InvalidArgumentException if neither a new start nor end date is given
     */
    public function reschedule(
        Booking $booking,
        User $actingUser,
        ?string $newTravelDateStart,
        ?string $newTravelDateEnd,
        string $feeCollection,
        ?string $notes = null,
    ): BookingAmendment {
        $this->assertTicketed($booking);

        if (! $newTravelDateStart && ! $newTravelDateEnd) {
            throw new InvalidArgumentException('Provide at least a new travel start or end date.');
        }

        $quote = $this->quoteFee($booking);

        return DB::transaction(function () use ($booking, $actingUser, $newTravelDateStart, $newTravelDateEnd, $feeCollection, $notes, $quote) {
            $amendment = BookingAmendment::query()->create([
                'booking_id' => $booking->id,
                'cancellation_policy_id' => $quote['policy_id'],
                'type' => BookingAmendment::TYPE_RESCHEDULE,
                'previous_travel_date_start' => $booking->travel_date_start,
                'previous_travel_date_end' => $booking->travel_date_end,
                'new_travel_date_start' => $newTravelDateStart ?? $booking->travel_date_start,
                'new_travel_date_end' => $newTravelDateEnd ?? $booking->travel_date_end,
                'fee_amount' => $quote['fee_amount'],
                'currency' => $quote['currency'],
                'fee_collection' => $feeCollection,
                'initiated_by_user_id' => $actingUser->id,
                'notes' => $notes,
            ]);

            $this->collectFee($amendment, $booking, $actingUser, "Reschedule fee for booking {$booking->booking_reference}");

            $booking->update([
                'travel_date_start' => $newTravelDateStart ?? $booking->travel_date_start,
                'travel_date_end' => $newTravelDateEnd ?? $booking->travel_date_end,
            ]);

            BookingEvent::log(
                $booking->id,
                BookingEvent::TYPE_RESCHEDULED,
                "Travel date changed to {$amendment->new_travel_date_start?->toDateString()}"
                    .($amendment->new_travel_date_end ? " – {$amendment->new_travel_date_end->toDateString()}" : '')
                    ." (fee: {$quote['currency']} {$quote['fee_amount']}, {$feeCollection}).",
                $actingUser,
            );

            return $amendment->fresh();
        });
    }

    /**
     * @throws RuntimeException if the booking isn't TICKETED
     * @throws InvalidArgumentException if neither a new supplier reference nor ticket numbers are given
     */
    public function reissue(
        Booking $booking,
        User $actingUser,
        ?string $newSupplierBookingRef,
        ?array $newTicketNumbers,
        string $feeCollection,
        ?string $notes = null,
    ): BookingAmendment {
        $this->assertTicketed($booking);

        if (! $newSupplierBookingRef && empty($newTicketNumbers)) {
            throw new InvalidArgumentException('Provide a new supplier booking reference or ticket number(s).');
        }

        $quote = $this->quoteFee($booking);
        $previousTicketNumbers = $booking->metadata['ticket_numbers'] ?? [];

        return DB::transaction(function () use ($booking, $actingUser, $newSupplierBookingRef, $newTicketNumbers, $feeCollection, $notes, $quote, $previousTicketNumbers) {
            $amendment = BookingAmendment::query()->create([
                'booking_id' => $booking->id,
                'cancellation_policy_id' => $quote['policy_id'],
                'type' => BookingAmendment::TYPE_REISSUE,
                'previous_supplier_booking_ref' => $booking->supplier_booking_ref,
                'new_supplier_booking_ref' => $newSupplierBookingRef,
                'previous_ticket_numbers' => $previousTicketNumbers ?: null,
                'new_ticket_numbers' => $newTicketNumbers ?: null,
                'fee_amount' => $quote['fee_amount'],
                'currency' => $quote['currency'],
                'fee_collection' => $feeCollection,
                'initiated_by_user_id' => $actingUser->id,
                'notes' => $notes,
            ]);

            $this->collectFee($amendment, $booking, $actingUser, "Reissue fee for booking {$booking->booking_reference}");

            $booking->update([
                'supplier_booking_ref' => $newSupplierBookingRef ?? $booking->supplier_booking_ref,
                'metadata' => array_merge($booking->metadata ?? [], [
                    'ticket_numbers' => $newTicketNumbers ?: $previousTicketNumbers,
                ]),
            ]);

            BookingEvent::log(
                $booking->id,
                BookingEvent::TYPE_REISSUED,
                'Ticket reissued'
                    .($newSupplierBookingRef ? " — new supplier ref {$newSupplierBookingRef}" : '')
                    .(! empty($newTicketNumbers) ? ' — ticket(s) '.implode(', ', $newTicketNumbers) : '')
                    ." (fee: {$quote['currency']} {$quote['fee_amount']}, {$feeCollection}).",
                $actingUser,
            );

            return $amendment->fresh();
        });
    }

    protected function assertTicketed(Booking $booking): void
    {
        if ($booking->status !== Booking::STATUS_TICKETED) {
            throw new RuntimeException("Booking #{$booking->id} must be ticketed to reschedule or reissue (currently '{$booking->status}').");
        }
    }

    /**
     * @return array{policy_id: int|null, fee_amount: string, currency: string}
     */
    protected function quoteFee(Booking $booking): array
    {
        $booking->loadMissing('segments');
        $quote = $this->cancellationPolicyService->quote($booking);

        return [
            'policy_id' => $quote['policy_id'],
            'fee_amount' => $quote['fee_amount'],
            'currency' => $quote['currency'],
        ];
    }

    /**
     * @throws \App\Domains\Wallet\Exceptions\InsufficientBalanceException
     * @throws \App\Domains\Wallet\Exceptions\WalletNotActiveException
     */
    protected function collectFee(BookingAmendment $amendment, Booking $booking, User $actingUser, string $remarks): void
    {
        if ($amendment->fee_collection !== BookingAmendment::FEE_WALLET) {
            return;
        }

        if ((float) $amendment->fee_amount <= 0) {
            return;
        }

        $wallet = Wallet::query()->firstOrCreate([
            'tenant_id' => $booking->tenant_id,
            'owner_type' => Wallet::OWNER_USER,
            'owner_id' => $booking->created_by_user_id,
        ]);

        $entry = $this->walletService->debit(
            $wallet,
            (string) $amendment->fee_amount,
            WalletLedgerEntry::REF_ADJUSTMENT,
            $booking->id,
            $remarks,
            $actingUser->id,
        );

        $amendment->update(['wallet_ledger_entry_id' => $entry->id]);
    }
}
