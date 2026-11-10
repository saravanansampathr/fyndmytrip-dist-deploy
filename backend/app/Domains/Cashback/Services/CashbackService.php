<?php

namespace App\Domains\Cashback\Services;

use App\Domains\Booking\Models\Booking;
use App\Domains\Cashback\Models\CashbackCredit;
use App\Domains\Cashback\Models\CashbackRule;
use App\Domains\Wallet\Models\Wallet;
use App\Domains\Wallet\Models\WalletLedgerEntry;
use App\Domains\Wallet\Services\WalletService;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

/**
 * Applies the best-matching active CashbackRule to a just-confirmed
 * booking and credits the amount straight to the customer's wallet
 * (WalletLedgerEntry::REF_CASHBACK) so it's immediately spendable —
 * "Cashback can be added in wallet and used" per the feature request.
 * Called once, from FlightBookingController's shared finalize step, for
 * both the domestic (instant wallet-pay) and international
 * (payment-gateway) confirmation paths.
 */
class CashbackService
{
    public function __construct(protected WalletService $walletService) {}

    /**
     * Idempotent: a booking that already has a cashback_credits row is
     * left alone, so calling this more than once for the same booking
     * (defensive — the booking state machine shouldn't allow it) never
     * double-credits the wallet.
     */
    public function applyCashback(Booking $booking, User $user): ?WalletLedgerEntry
    {
        if (CashbackCredit::query()->where('booking_id', $booking->id)->exists()) {
            return null;
        }

        $rule = $this->bestRuleFor($booking);

        if (! $rule) {
            return null;
        }

        $amount = $this->computeAmount($rule, $booking);

        if (BigDecimal::of($amount)->isLessThanOrEqualTo(0)) {
            return null;
        }

        return DB::transaction(function () use ($rule, $booking, $user, $amount) {
            $wallet = Wallet::query()->firstOrCreate([
                'tenant_id' => $booking->tenant_id,
                'owner_type' => Wallet::OWNER_USER,
                'owner_id' => $user->id,
            ]);

            $entry = $this->walletService->credit(
                $wallet,
                $amount,
                WalletLedgerEntry::REF_CASHBACK,
                $booking->id,
                "Cashback for booking {$booking->booking_reference} ({$rule->name})",
                null,
            );

            CashbackCredit::query()->create([
                'booking_id' => $booking->id,
                'cashback_rule_id' => $rule->id,
                'wallet_ledger_entry_id' => $entry->id,
                'amount' => $amount,
            ]);

            return $entry;
        });
    }

    public function bestRuleFor(Booking $booking): ?CashbackRule
    {
        $candidates = CashbackRule::query()
            ->where('status', CashbackRule::STATUS_ACTIVE)
            ->where(fn ($q) => $q->where('tenant_id', $booking->tenant_id)->orWhereNull('tenant_id'))
            ->orderByDesc('priority')
            ->orderByDesc('tenant_id') // a tenant-specific rule wins a priority tie over a platform-wide one
            ->get();

        foreach ($candidates as $rule) {
            if ($rule->matchesBooking($booking)) {
                return $rule;
            }
        }

        return null;
    }

    public function computeAmount(CashbackRule $rule, Booking $booking): string
    {
        $base = BigDecimal::of((string) $booking->total_amount);

        $amount = $rule->calc_type === CashbackRule::CALC_PERCENTAGE
            ? $base->multipliedBy((string) $rule->value)->dividedBy(100, 2, RoundingMode::HALF_UP)
            : BigDecimal::of((string) $rule->value);

        if ($rule->max_cashback_amount !== null) {
            $cap = BigDecimal::of((string) $rule->max_cashback_amount);

            if ($amount->isGreaterThan($cap)) {
                $amount = $cap;
            }
        }

        // Cashback is a reward, not a discount, but it should never exceed
        // what the customer actually paid on this booking.
        if ($amount->isGreaterThan($base)) {
            $amount = $base;
        }

        return (string) $amount->toScale(2, RoundingMode::HALF_UP);
    }
}
