<?php

namespace App\Domains\Booking\Services;

use App\Domains\Booking\Models\HotelGroupBookingRequest;
use App\Domains\Wallet\Exceptions\InsufficientBalanceException;
use App\Domains\Wallet\Models\Wallet;
use App\Domains\Wallet\Models\WalletLedgerEntry;
use App\Domains\Wallet\Services\WalletService;
use App\Models\User;
use App\Support\TenantMailBranding;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Every state transition for the staff-quoted hotel group booking flow —
 * see HotelGroupBookingRequest's docblock for the full diagram. Money
 * movements (deposit, balance) go through WalletService against the
 * requester's own wallet, exactly like every other payment in this app;
 * everything else is a plain status change plus a best-effort email,
 * mirroring GroupBookingService's own pattern (Mail::raw, never queued —
 * no queue worker runs on this cPanel host).
 */
class HotelGroupBookingService
{
    public function __construct(protected WalletService $walletService) {}

    public function submit(User $user, array $data): HotelGroupBookingRequest
    {
        return HotelGroupBookingRequest::query()->create([
            'tenant_id' => $user->tenant_id,
            'requested_by_user_id' => $user->id,
            'city' => $data['city'],
            'check_in_date' => $data['check_in_date'],
            'check_out_date' => $data['check_out_date'],
            'rooms_requested' => $data['rooms_requested'],
            'guest_count' => $data['guest_count'],
            'room_type_preference' => $data['room_type_preference'] ?? null,
            'meal_plan_preference' => $data['meal_plan_preference'] ?? null,
            'contact_name' => $data['contact_name'],
            'contact_email' => $data['contact_email'],
            'contact_phone' => $data['contact_phone'],
            'remarks' => $data['remarks'] ?? null,
        ]);
    }

    /**
     * Staff prices the request: a per-room rate (or total, staff's call
     * which they enter) plus a deposit to lock in the rate hold, and the
     * two dates that drive the rest of the flow — after
     * rate_hold_expires_at without a paid deposit, markExpired() applies;
     * rooming_list_deadline is when the final guest list is due once the
     * deposit is in.
     */
    public function quote(HotelGroupBookingRequest $request, User $staff, array $data): HotelGroupBookingRequest
    {
        abort_unless($request->isPendingQuote(), 422, 'This request has already been quoted or closed.');

        $request->update([
            'quoted_rate_per_room' => $data['quoted_rate_per_room'],
            'quoted_total_fare' => $data['quoted_total_fare'],
            'deposit_amount' => $data['deposit_amount'],
            'quote_remarks' => $data['quote_remarks'] ?? null,
            'quoted_by_user_id' => $staff->id,
            'quoted_at' => now(),
            'rate_hold_expires_at' => $data['rate_hold_expires_at'],
            'rooming_list_deadline' => $data['rooming_list_deadline'],
            'status' => HotelGroupBookingRequest::STATUS_QUOTED,
        ]);

        $this->notify(
            $request,
            'Your hotel group booking quote is ready',
            "We've quoted your group booking request ({$request->city}, {$request->rooms_requested} rooms, {$request->guest_count} guests):\n\n"
                ."Rate per room: \xe2\x82\xb9{$request->quoted_rate_per_room}\n"
                ."Total fare: \xe2\x82\xb9{$request->quoted_total_fare}\n"
                ."Deposit required to hold this rate: \xe2\x82\xb9{$request->deposit_amount}\n"
                .'Rate hold expires: '.$request->rate_hold_expires_at->format('d M Y')."\n"
                .'Final rooming list due by: '.$request->rooming_list_deadline->format('d M Y')
                .($data['quote_remarks'] ?? null ? "\n\nNote from our team: {$data['quote_remarks']}" : '')
                ."\n\nPay the deposit before the rate hold expires to confirm this booking.",
        );

        return $request->fresh();
    }

    /**
     * @throws InsufficientBalanceException
     */
    public function acceptQuote(HotelGroupBookingRequest $request, User $user): HotelGroupBookingRequest
    {
        abort_unless($request->isQuoted(), 422, 'This request has no active quote to accept.');
        abort_if(
            $request->rate_hold_expires_at && now()->startOfDay()->gt($request->rate_hold_expires_at),
            422,
            'This rate hold has expired — ask our team to requote it.',
        );

        // Deliberately NOT wrapped in an outer DB::transaction() — same
        // reasoning as GroupBookingService::acceptQuote(): WalletService::
        // debit() already runs its own atomic, row-locked transaction and
        // fires its "wallet debited" email right after THAT commits, so
        // nesting a further transaction here would risk that email going
        // out before this method's own commit.
        $wallet = Wallet::query()->firstOrCreate([
            'tenant_id' => $user->tenant_id,
            'owner_type' => Wallet::OWNER_USER,
            'owner_id' => $user->id,
        ]);

        $entry = $this->walletService->debit(
            $wallet,
            (string) $request->deposit_amount,
            WalletLedgerEntry::REF_HOTEL_GROUP_DEPOSIT,
            $request->id,
            "Hotel group booking deposit — {$request->city}, {$request->rooms_requested} rooms",
            $user->id,
        );

        $request->update([
            'status' => HotelGroupBookingRequest::STATUS_DEPOSIT_PAID,
            'deposit_paid_at' => now(),
            'deposit_wallet_ledger_entry_id' => $entry->id,
        ]);

        return $request->fresh();
    }

    /**
     * Requester cancels their own request — only ever before money moves
     * (pending_quote or quoted); once the deposit is paid, cancellation is
     * a staff matter (refund judgement calls are out of scope here — same
     * posture already taken for Flight Group Bookings and credit terms).
     */
    public function cancel(HotelGroupBookingRequest $request, string $reason): HotelGroupBookingRequest
    {
        abort_unless($request->isPendingQuote() || $request->isQuoted(), 422, 'This request can no longer be cancelled here — contact our team.');

        $request->update([
            'status' => HotelGroupBookingRequest::STATUS_CANCELLED,
            'rejection_reason' => $reason,
        ]);

        return $request->fresh();
    }

    public function reject(HotelGroupBookingRequest $request, User $staff, string $reason): HotelGroupBookingRequest
    {
        abort_unless($request->isPendingQuote(), 422, 'Only a request awaiting its first quote can be rejected outright.');

        $request->update([
            'status' => HotelGroupBookingRequest::STATUS_REJECTED,
            'rejection_reason' => $reason,
        ]);

        $this->notify(
            $request,
            'Your hotel group booking request could not be quoted',
            "We're unable to quote a group rate for your request ({$request->city}, {$request->rooms_requested} rooms, {$request->guest_count} guests).\n\nReason: {$reason}",
        );

        return $request->fresh();
    }

    /** Staff marks a quote that expired unpaid — manual, no scheduler/cron in this environment (same posture as credit terms and Flight Group Bookings). */
    public function markExpired(HotelGroupBookingRequest $request): HotelGroupBookingRequest
    {
        abort_unless($request->isQuoted(), 422, 'Only an active, unpaid quote can be marked expired.');

        $request->update(['status' => HotelGroupBookingRequest::STATUS_EXPIRED]);

        return $request->fresh();
    }

    /**
     * @param array<int, array<string, mixed>> $guests
     */
    public function submitRoomingList(HotelGroupBookingRequest $request, array $guests): HotelGroupBookingRequest
    {
        abort_unless($request->isDepositPaid(), 422, 'The deposit must be paid before submitting the rooming list.');

        return DB::transaction(function () use ($request, $guests) {
            // Replace-in-full rather than merge — a resubmission before the
            // deadline is the correction path, same convention as
            // GroupBookingService::submitNameList().
            $request->guests()->delete();

            foreach ($guests as $guest) {
                $request->guests()->create([
                    ...$guest,
                    'guest_type' => $guest['guest_type'] ?? 'adult',
                ]);
            }

            $request->update(['status' => HotelGroupBookingRequest::STATUS_ROOMING_LIST_SUBMITTED]);

            return $request->fresh('guests');
        });
    }

    /**
     * Staff finalizes once the hotel has actually confirmed the block
     * (necessarily a manual, off-platform step for a negotiated group rate —
     * there is no live supplier integration for this path, same "assume
     * success" placeholder posture as the rest of this demo pipeline):
     * collects any balance beyond the deposit and records the reference the
     * hotel gave back.
     *
     * @throws InsufficientBalanceException
     */
    public function finalize(HotelGroupBookingRequest $request, User $staff, string $finalBookingReference): HotelGroupBookingRequest
    {
        abort_unless($request->isRoomingListSubmitted(), 422, 'The final rooming list must be submitted before finalizing.');

        // Same reasoning as acceptQuote() above for not wrapping this in its
        // own outer transaction.
        $balance = BigDecimal::of($request->balanceDue());
        $entryId = null;

        if ($balance->isGreaterThan(BigDecimal::zero())) {
            $user = $request->requestedBy;
            $wallet = Wallet::query()->firstOrCreate([
                'tenant_id' => $request->tenant_id,
                'owner_type' => Wallet::OWNER_USER,
                'owner_id' => $user->id,
            ]);

            $entry = $this->walletService->debit(
                $wallet,
                (string) $balance->toScale(2, RoundingMode::HALF_UP),
                WalletLedgerEntry::REF_HOTEL_GROUP_BALANCE,
                $request->id,
                "Hotel group booking balance — {$request->city}, ref {$finalBookingReference}",
                $staff->id,
            );
            $entryId = $entry->id;
        }

        $request->update([
            'status' => HotelGroupBookingRequest::STATUS_FINALIZED,
            'finalized_by_user_id' => $staff->id,
            'finalized_at' => now(),
            'final_booking_reference' => $finalBookingReference,
            'balance_wallet_ledger_entry_id' => $entryId,
        ]);

        $this->notify(
            $request,
            'Your hotel group booking is confirmed',
            "Your group booking ({$request->city}, {$request->rooms_requested} rooms) is finalized.\n\n"
                ."Booking reference: {$finalBookingReference}"
                .($balance->isGreaterThan(BigDecimal::zero()) ? "\nRemaining balance charged: \xe2\x82\xb9{$balance}" : ''),
        );

        return $request->fresh();
    }

    protected function notify(HotelGroupBookingRequest $request, string $subject, string $body): void
    {
        $email = $request->requestedBy?->email ?? $request->contact_email;

        if (! $email) {
            return;
        }

        $branding = TenantMailBranding::resolveForTenantId($request->tenant_id);

        try {
            Mail::raw($body, function ($message) use ($email, $subject, $branding) {
                $message->to($email)->subject($subject);

                if ($branding['from_address']) {
                    $message->from($branding['from_address'], $branding['from_name']);
                }
                if ($branding['support_email']) {
                    $message->replyTo($branding['support_email']);
                }
            });
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
