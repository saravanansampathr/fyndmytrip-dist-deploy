<?php

namespace App\Domains\Booking\Services;

use App\Domains\Booking\Models\Booking;
use App\Domains\Booking\Models\CancellationPolicy;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;

/**
 * Resolves the best-matching CancellationPolicy for a booking and computes
 * an actual refund/fee quote from it — the roadmap's "all need to calculate
 * properly and show them its in process" for Phase 2's first slice. Mirrors
 * CashbackService::bestRuleFor()'s query shape (tenant-or-platform-wide
 * candidates, most-specific/highest-priority match wins) but orders by
 * CancellationPolicy::specificity() rather than priority alone, since a
 * policy set realistically has several rows tied on priority (the seeded
 * default vs. a supplier-specific override) and specificity is what should
 * break that tie, same as MarkupRule/MarkupService's approach in Phase 6.
 *
 * When nothing matches at all, quote() falls back to
 * CancellationPolicy::FALLBACK_BANDS rather than refunding 100% or erroring
 * — a conservative default that's always in effect even before staff have
 * configured a single policy.
 */
class CancellationPolicyService
{
    public function bestPolicyFor(Booking $booking): ?CancellationPolicy
    {
        $candidates = CancellationPolicy::query()
            ->where('is_active', true)
            ->where(fn ($q) => $q->where('tenant_id', $booking->tenant_id)->orWhereNull('tenant_id'))
            ->orderByDesc('priority')
            ->get();

        $matching = $candidates->filter(fn (CancellationPolicy $policy) => $policy->matches($booking));

        if ($matching->isEmpty()) {
            return null;
        }

        return $matching->sortByDesc(fn (CancellationPolicy $policy) => $policy->specificity())->first();
    }

    /**
     * All active platform-wide-or-this-tenant policies, fetched once and
     * re-used across every offer in a search response — bestMatchFromCandidates()
     * below is a pure in-memory filter over this same list, so showing a
     * cancellation policy preview on N search offers costs one query, not N.
     */
    public function activeCandidates(?int $tenantId)
    {
        return CancellationPolicy::query()
            ->where('is_active', true)
            ->where(fn ($q) => $q->where('tenant_id', $tenantId)->orWhereNull('tenant_id'))
            ->orderByDesc('priority')
            ->get();
    }

    /**
     * bestPolicyFor()'s matching logic, but against a not-yet-booked
     * offer's own fields instead of a persisted Booking — a search result
     * or seat-map view has no Booking row to match against yet, and
     * doesn't need one just to show the customer what the cancellation
     * terms would be. Deliberately duplicates (rather than reuses)
     * CancellationPolicy::matches()'s handful of comparisons instead of
     * building a fake Booking: matches() also checks a `segments`
     * relation, which a bare offer doesn't have.
     */
    public function bestMatchFromCandidates(
        $candidates,
        string $module,
        bool $isInternational,
        ?string $supplierCode,
        ?string $origin = null,
        ?string $destination = null,
    ): ?CancellationPolicy {
        $matching = $candidates->filter(function (CancellationPolicy $policy) use ($module, $isInternational, $supplierCode, $origin, $destination) {
            if ($policy->module !== null && $policy->module !== $module) {
                return false;
            }

            if ($policy->booking_scope === CancellationPolicy::SCOPE_DOMESTIC && $isInternational) {
                return false;
            }

            if ($policy->booking_scope === CancellationPolicy::SCOPE_INTERNATIONAL && ! $isInternational) {
                return false;
            }

            if ($policy->supplier_code !== null && strtoupper($policy->supplier_code) !== strtoupper((string) $supplierCode)) {
                return false;
            }

            if ($policy->origin !== null && strtoupper($policy->origin) !== strtoupper((string) $origin)) {
                return false;
            }

            if ($policy->destination !== null && strtoupper($policy->destination) !== strtoupper((string) $destination)) {
                return false;
            }

            return true;
        });

        if ($matching->isEmpty()) {
            return null;
        }

        return $matching->sortByDesc(fn (CancellationPolicy $policy) => $policy->specificity())->first();
    }

    /**
     * The customer-facing shape for a pre-booking cancellation preview —
     * just the bands (no computed refund amount, since there's no total
     * yet) plus which policy (or the fallback) they came from.
     *
     * @return array{policy_name: string, used_fallback: bool, bands: array<int, array{label: string, hours_before_min: int, refund_percentage: string, flat_fee: string}>}
     */
    public function previewBands(?CancellationPolicy $policy): array
    {
        $bands = $policy?->bands ?? CancellationPolicy::FALLBACK_BANDS;

        return [
            'policy_name' => $policy?->name ?? 'Standard cancellation policy',
            'used_fallback' => $policy === null,
            'bands' => array_map(fn (array $band) => [
                'label' => $band['label'] ?? '',
                'hours_before_min' => (int) ($band['hours_before_min'] ?? 0),
                'refund_percentage' => (string) ($band['refund_percentage'] ?? '0.00'),
                'flat_fee' => (string) ($band['flat_fee'] ?? '0.00'),
            ], $bands),
        ];
    }

    /**
     * @return array{
     *   policy_id: int|null,
     *   policy_name: string,
     *   used_fallback: bool,
     *   hours_until_travel: int,
     *   band_label: string,
     *   refund_percentage: string,
     *   flat_fee: string,
     *   refund_amount: string,
     *   fee_amount: string,
     *   currency: string,
     * }
     */
    public function quote(Booking $booking): array
    {
        $policy = $this->bestPolicyFor($booking);
        $bands = $policy?->bands ?? CancellationPolicy::FALLBACK_BANDS;

        $hoursUntilTravel = $this->hoursUntilTravel($booking);
        $band = CancellationPolicy::bandFor($bands, $hoursUntilTravel);

        $total = BigDecimal::of((string) $booking->total_amount);
        $refundPercentage = BigDecimal::of((string) $band['refund_percentage']);
        $flatFee = BigDecimal::of((string) ($band['flat_fee'] ?? '0'));

        $refundAmount = $total
            ->multipliedBy($refundPercentage)
            ->dividedBy(100, 2, RoundingMode::HALF_UP)
            ->minus($flatFee);

        if ($refundAmount->isNegative()) {
            $refundAmount = BigDecimal::of('0.00');
        }

        if ($refundAmount->isGreaterThan($total)) {
            $refundAmount = $total;
        }

        $feeAmount = $total->minus($refundAmount);

        return [
            'policy_id' => $policy?->id,
            'policy_name' => $policy?->name ?? 'Platform default (no policy configured)',
            'used_fallback' => $policy === null,
            'hours_until_travel' => $hoursUntilTravel,
            'band_label' => $band['label'] ?? '',
            'refund_percentage' => (string) $refundPercentage->toScale(2, RoundingMode::HALF_UP),
            'flat_fee' => (string) $flatFee->toScale(2, RoundingMode::HALF_UP),
            'refund_amount' => (string) $refundAmount->toScale(2, RoundingMode::HALF_UP),
            'fee_amount' => (string) $feeAmount->toScale(2, RoundingMode::HALF_UP),
            'currency' => $booking->currency ?? 'INR',
        ];
    }

    /**
     * Negative when travel has already started/passed — bandFor() falls
     * through to the strictest (lowest) band in that case, same as a
     * same-day no-show would. A booking with no travel_date_start at all
     * (shouldn't normally happen, but Booking's column is nullable) is
     * treated the same way: 0 hours out, no refund band.
     */
    protected function hoursUntilTravel(Booking $booking): int
    {
        if ($booking->travel_date_start === null) {
            return 0;
        }

        $travelAt = Carbon::parse($booking->travel_date_start);

        return (int) floor(Carbon::now()->diffInHours($travelAt, false));
    }
}
