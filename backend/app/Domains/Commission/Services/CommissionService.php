<?php

namespace App\Domains\Commission\Services;

use App\Domains\Booking\Models\Booking;
use App\Domains\Commission\Models\CommissionCredit;
use App\Domains\Commission\Models\CommissionRule;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Services\TenantService;
use App\Domains\Wallet\Models\Wallet;
use App\Domains\Wallet\Models\WalletLedgerEntry;
use App\Domains\Wallet\Services\WalletService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

/**
 * Platform Roadmap Phase 7 — "Commission tracking per channel... calculated
 * and recorded distinctly from markup." Applies the nearest matching active
 * CommissionRule found by walking the booking tenant's ancestor chain
 * (parent, grandparent, ... up to Master) and credits the amount straight
 * to that BOOKING'S OWN tenant's wallet (WalletLedgerEntry::
 * REF_COMMISSION_PAYOUT) — the reseller (agent/sub-agent/white-label
 * operator/API partner) that actually sold the booking is who gets
 * rewarded, not whoever granted the rule.
 *
 * Deliberately mirrors App\Domains\Cashback\Services\CashbackService's
 * shape (same idempotency guard, same DB::transaction credit pattern) —
 * called once, from the same shared "booking just ticketed" finalize step
 * in every booking controller, right alongside applyCashback().
 *
 * Unlike MarkupService, there is no ceiling walk: markup flows UP the
 * chain as a cost, so a child tenant must never be able to configure its
 * way past what a parent allows it to charge. Commission flows DOWN the
 * chain as a reward instead, so that "child can't out-configure parent"
 * concern doesn't apply here — whichever ancestor is nearest with an
 * active matching rule is simply the one whose rule is used.
 */
class CommissionService
{
    public function __construct(
        protected WalletService $walletService,
        protected TenantService $tenantService,
    ) {}

    /**
     * Idempotent: a booking that already has a commission_credits row is
     * left alone, so calling this more than once for the same booking
     * never double-credits the wallet.
     */
    public function applyCommission(Booking $booking): ?WalletLedgerEntry
    {
        if (CommissionCredit::query()->where('booking_id', $booking->id)->exists()) {
            return null;
        }

        $tenant = $booking->tenant;

        // Nobody sits above Master to reward it — a booking made directly
        // under the platform's own tenant earns no commission.
        if (! $tenant || $tenant->type === Tenant::TYPE_MASTER) {
            return null;
        }

        $rule = $this->bestRuleFor($booking, $tenant);

        if (! $rule) {
            return null;
        }

        $amount = $this->computeAmount($rule, $booking);

        if (BigDecimal::of($amount)->isLessThanOrEqualTo(0)) {
            return null;
        }

        return DB::transaction(function () use ($rule, $booking, $tenant, $amount) {
            $wallet = Wallet::query()->firstOrCreate([
                'tenant_id' => $tenant->id,
                'owner_type' => Wallet::OWNER_TENANT,
                'owner_id' => $tenant->id,
            ]);

            $entry = $this->walletService->credit(
                $wallet,
                $amount,
                WalletLedgerEntry::REF_COMMISSION_PAYOUT,
                $booking->id,
                "Commission for booking {$booking->booking_reference} ({$rule->name})",
                null,
            );

            CommissionCredit::query()->create([
                'booking_id' => $booking->id,
                'commission_rule_id' => $rule->id,
                'wallet_ledger_entry_id' => $entry->id,
                'amount' => $amount,
            ]);

            // Kept in sync so Reports (which already sums Booking::commission_amount)
            // and any other existing reader of this column keep working
            // without changes now that it's finally a real, non-zero value.
            $booking->update(['commission_amount' => $amount]);

            return $entry;
        });
    }

    /**
     * The nearest ancestor (parent, then grandparent, ... up to Master) that
     * has an active rule matching this booking's tenant type + module +
     * domestic/international scope. Self is excluded — a tenant doesn't
     * grant itself commission.
     */
    public function bestRuleFor(Booking $booking, Tenant $tenant): ?CommissionRule
    {
        $ancestorIds = $this->tenantService->getAncestorIds($tenant);
        $ancestorIds = array_values(array_diff($ancestorIds, [$tenant->id]));

        if (empty($ancestorIds)) {
            return null;
        }

        $isInternational = (bool) $booking->is_international;

        $rules = CommissionRule::withoutTenantScope()
            ->whereIn('tenant_id', $ancestorIds)
            ->where('applies_to_type', $tenant->type)
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('module')->orWhere('module', $booking->module))
            ->get()
            ->filter(function (CommissionRule $r) use ($isInternational) {
                if ($r->booking_scope === CommissionRule::SCOPE_DOMESTIC && $isInternational) {
                    return false;
                }

                return ! ($r->booking_scope === CommissionRule::SCOPE_INTERNATIONAL && ! $isInternational);
            })
            // A module-specific rule wins over a null-module (every module) rule from the same tenant.
            ->sortByDesc(fn (CommissionRule $r) => $r->module !== null ? 1 : 0);

        $byTenant = $rules->groupBy('tenant_id')->map(fn ($group) => $group->first());

        foreach ($ancestorIds as $id) {
            if ($byTenant->has($id)) {
                return $byTenant->get($id);
            }
        }

        return null;
    }

    public function computeAmount(CommissionRule $rule, Booking $booking): string
    {
        $amount = match ($rule->rule_type) {
            CommissionRule::TYPE_PERCENTAGE => BigDecimal::of((string) $booking->gross_amount)
                ->multipliedBy($rule->value())
                ->dividedBy(100, 4, RoundingMode::HALF_UP),
            CommissionRule::TYPE_FLAT_PER_PNR => BigDecimal::of($rule->value()),
            CommissionRule::TYPE_FLAT_PER_PAX => BigDecimal::of($rule->value())
                ->multipliedBy(max(1, (int) $booking->passenger_count)),
            default => BigDecimal::of('0.00'),
        };

        if ($rule->max_commission_amount !== null) {
            $cap = BigDecimal::of((string) $rule->max_commission_amount);

            if ($amount->isGreaterThan($cap)) {
                $amount = $cap;
            }
        }

        // Commission is a reward carved out of what the platform earns on
        // this booking, so it should never exceed the booking's own markup
        // — a channel can't be paid more than the margin the booking
        // actually generated.
        $marginCeiling = BigDecimal::of((string) $booking->markup_amount);

        if ($amount->isGreaterThan($marginCeiling)) {
            $amount = $marginCeiling;
        }

        return (string) $amount->toScale(2, RoundingMode::HALF_UP);
    }
}
