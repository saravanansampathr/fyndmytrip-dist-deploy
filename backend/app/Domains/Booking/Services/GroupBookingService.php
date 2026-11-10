<?php

namespace App\Domains\Booking\Services;

use App\Domains\Booking\Models\GroupBookingRequest;
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
 * Every state transition for the staff-quoted group booking flow — see
 * GroupBookingRequest's docblock for the full diagram. Money movements
 * (deposit, balance) go through WalletService against the requester's own
 * wallet, exactly like every other payment in this app; everything else is
 * a plain status change plus a best-effort email, mirroring
 * OfflinePaymentService's own pattern (Mail::raw, never queued — no queue
 * worker runs on this cPanel host).
 */
class GroupBookingService
{
    public function __construct(protected WalletService $walletService) {}

    public function submit(User $user, array $data): GroupBookingRequest
    {
        return GroupBookingRequest::query()->create([
            'tenant_id' => $user->tenant_id,
            'requested_by_user_id' => $user->id,
            'trip_type' => $data['trip_type'],
            'origin' => strtoupper($data['origin']),
            'destination' => strtoupper($data['destination']),
            'depart_date' => $data['depart_date'],
            'return_date' => $data['return_date'] ?? null,
            'passenger_count' => $data['passenger_count'],
            'cabin_class' => $data['cabin_class'] ?? 'economy',
            'flexible_dates' => $data['flexible_dates'] ?? false,
            'contact_name' => $data['contact_name'],
            'contact_email' => $data['contact_email'],
            'contact_phone' => $data['contact_phone'],
            'remarks' => $data['remarks'] ?? null,
        ]);
    }

    /**
     * Staff prices the request: total fare (or per-pax * count, staff's
     * call which they enter), a deposit to lock in the fare hold, and the
     * two dates that drive the rest of the flow — after fare_hold_expires_at
     * without a paid deposit, markExpired() applies; name_list_deadline is
     * when the final passenger list is due once the deposit is in.
     */
    public function quote(GroupBookingRequest $request, User $staff, array $data): GroupBookingRequest
    {
        abort_unless($request->isPendingQuote(), 422, 'This request has already been quoted or closed.');

        $request->update([
            'quoted_fare_per_pax' => $data['quoted_fare_per_pax'],
            'quoted_total_fare' => $data['quoted_total_fare'],
            'deposit_amount' => $data['deposit_amount'],
            'quote_remarks' => $data['quote_remarks'] ?? null,
            'quoted_by_user_id' => $staff->id,
            'quoted_at' => now(),
            'fare_hold_expires_at' => $data['fare_hold_expires_at'],
            'name_list_deadline' => $data['name_list_deadline'],
            'status' => GroupBookingRequest::STATUS_QUOTED,
        ]);

        $this->notify(
            $request,
            'Your group booking quote is ready',
            "We've quoted your group booking request ({$request->origin} \xe2\x86\x92 {$request->destination}, {$request->passenger_count} passengers):\n\n"
                ."Fare per passenger: \xe2\x82\xb9{$request->quoted_fare_per_pax}\n"
                ."Total fare: \xe2\x82\xb9{$request->quoted_total_fare}\n"
                ."Deposit required to hold this fare: \xe2\x82\xb9{$request->deposit_amount}\n"
                .'Fare hold expires: '.$request->fare_hold_expires_at->format('d M Y')."\n"
                .'Final passenger name list due by: '.$request->name_list_deadline->format('d M Y')
                .($data['quote_remarks'] ?? null ? "\n\nNote from our team: {$data['quote_remarks']}" : '')
                ."\n\nPay the deposit before the fare hold expires to confirm this booking.",
        );

        return $request->fresh();
    }

    /**
     * @throws InsufficientBalanceException
     */
    public function acceptQuote(GroupBookingRequest $request, User $user): GroupBookingRequest
    {
        abort_unless($request->isQuoted(), 422, 'This request has no active quote to accept.');
        abort_if(
            $request->fare_hold_expires_at && now()->startOfDay()->gt($request->fare_hold_expires_at),
            422,
            'This fare hold has expired — ask our team to requote it.',
        );

        // Deliberately NOT wrapped in an outer DB::transaction(): WalletService::debit()
        // already runs its own atomic, row-locked transaction and fires its
        // "wallet debited" email right after THAT commits — nesting it
        // inside a further transaction here (as OfflinePaymentService::approve()
        // does) would let that email go out before this method's own commit,
        // so a later failure in this method could roll back the debit after
        // the email already claimed it happened. The status update below is
        // a single simple write with nothing left to fail alongside it.
        $wallet = Wallet::query()->firstOrCreate([
            'tenant_id' => $user->tenant_id,
            'owner_type' => Wallet::OWNER_USER,
            'owner_id' => $user->id,
        ]);

        $entry = $this->walletService->debit(
            $wallet,
            (string) $request->deposit_amount,
            WalletLedgerEntry::REF_GROUP_DEPOSIT,
            $request->id,
            "Group booking deposit — {$request->origin} to {$request->destination}, {$request->passenger_count} pax",
            $user->id,
        );

        $request->update([
            'status' => GroupBookingRequest::STATUS_DEPOSIT_PAID,
            'deposit_paid_at' => now(),
            'deposit_wallet_ledger_entry_id' => $entry->id,
        ]);

        return $request->fresh();
    }

    /**
     * Requester cancels their own request — only ever before money moves
     * (pending_quote or quoted); once the deposit is paid, cancellation is
     * a staff matter (refund judgement calls are out of scope here — same
     * "manual, staff-judgement" posture already taken for credit terms).
     */
    public function cancel(GroupBookingRequest $request, string $reason): GroupBookingRequest
    {
        abort_unless($request->isPendingQuote() || $request->isQuoted(), 422, 'This request can no longer be cancelled here — contact our team.');

        $request->update([
            'status' => GroupBookingRequest::STATUS_CANCELLED,
            'rejection_reason' => $reason,
        ]);

        return $request->fresh();
    }

    public function reject(GroupBookingRequest $request, User $staff, string $reason): GroupBookingRequest
    {
        abort_unless($request->isPendingQuote(), 422, 'Only a request awaiting its first quote can be rejected outright.');

        $request->update([
            'status' => GroupBookingRequest::STATUS_REJECTED,
            'rejection_reason' => $reason,
        ]);

        $this->notify(
            $request,
            'Your group booking request could not be quoted',
            "We're unable to quote a group fare for your request ({$request->origin} \xe2\x86\x92 {$request->destination}, {$request->passenger_count} passengers).\n\nReason: {$reason}",
        );

        return $request->fresh();
    }

    /** Staff marks a quote that expired unpaid — manual, no scheduler/cron in this environment (same posture as credit terms). */
    public function markExpired(GroupBookingRequest $request): GroupBookingRequest
    {
        abort_unless($request->isQuoted(), 422, 'Only an active, unpaid quote can be marked expired.');

        $request->update(['status' => GroupBookingRequest::STATUS_EXPIRED]);

        return $request->fresh();
    }

    /**
     * @param array<int, array<string, mixed>> $passengers
     */
    public function submitNameList(GroupBookingRequest $request, array $passengers): GroupBookingRequest
    {
        abort_unless($request->isDepositPaid(), 422, 'The deposit must be paid before submitting the passenger name list.');

        return DB::transaction(function () use ($request, $passengers) {
            // Replace-in-full rather than merge — a resubmission before the
            // deadline is the correction path (matches how a real group desk
            // takes the LATEST full list, not a diff against the last one).
            $request->passengers()->delete();

            foreach ($passengers as $passenger) {
                $request->passengers()->create([
                    ...$passenger,
                    'passenger_type' => $passenger['passenger_type'] ?? 'adult',
                ]);
            }

            $request->update(['status' => GroupBookingRequest::STATUS_NAME_LIST_SUBMITTED]);

            return $request->fresh('passengers');
        });
    }

    /**
     * Staff finalizes once the airline has actually issued tickets
     * (necessarily a manual, off-platform step for a negotiated group fare —
     * there is no live supplier integration for this path, same "assume
     * success" placeholder posture as the rest of this demo pipeline):
     * collects any balance beyond the deposit and records the reference the
     * airline/GDS gave back.
     *
     * @throws InsufficientBalanceException
     */
    public function finalize(GroupBookingRequest $request, User $staff, string $finalBookingReference): GroupBookingRequest
    {
        abort_unless($request->isNameListSubmitted(), 422, 'The final passenger name list must be submitted before finalizing.');

        // Same reasoning as acceptQuote() above for not wrapping this in its
        // own outer transaction — WalletService::debit() is already atomic
        // and its notification timing assumes it isn't nested further.
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
                WalletLedgerEntry::REF_GROUP_BALANCE,
                $request->id,
                "Group booking balance — {$request->origin} to {$request->destination}, ref {$finalBookingReference}",
                $staff->id,
            );
            $entryId = $entry->id;
        }

        $request->update([
            'status' => GroupBookingRequest::STATUS_FINALIZED,
            'finalized_by_user_id' => $staff->id,
            'finalized_at' => now(),
            'final_booking_reference' => $finalBookingReference,
            'balance_wallet_ledger_entry_id' => $entryId,
        ]);

        $this->notify(
            $request,
            'Your group booking is confirmed',
            "Your group booking ({$request->origin} \xe2\x86\x92 {$request->destination}, {$request->passenger_count} passengers) is finalized.\n\n"
                ."Booking reference: {$finalBookingReference}"
                .($balance->isGreaterThan(BigDecimal::zero()) ? "\nRemaining balance charged: \xe2\x82\xb9{$balance}" : ''),
        );

        return $request->fresh();
    }

    protected function notify(GroupBookingRequest $request, string $subject, string $body): void
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
