<?php

namespace App\Domains\Booking\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * "Staff define these in a CRM screen rather than in code" — a policy is a
 * set of time-before-travel bands (see bands' shape in the migration),
 * each with a refund percentage and/or flat fee, matched against a booking
 * the same way MarkupRule now is (tenant/module/domestic-international/
 * supplier/route). CancellationPolicyService is what resolves the best-
 * matching policy for a booking and actually computes a quote from it.
 *
 * Deliberately mirrors CashbackRule/Coupon's tenant convention: tenant_id
 * NULL is a platform-wide policy, not auto-scoped (no BelongsToTenant), so
 * the owning controller decides who may see/manage which rows.
 */
class CancellationPolicy extends Model
{
    public const SCOPE_DOMESTIC = 'domestic';

    public const SCOPE_INTERNATIONAL = 'international';

    public const SCOPE_BOTH = 'both';

    public const BOOKING_SCOPES = [self::SCOPE_DOMESTIC, self::SCOPE_INTERNATIONAL, self::SCOPE_BOTH];

    /** Used when no configured policy matches a booking at all — a
     * conservative, clearly-labelled fallback rather than "no rule = full
     * refund" or a hard failure. Staff can always override this by simply
     * creating a global default policy (tenant_id/module/supplier_code all
     * null) once they've decided their real bands. */
    public const FALLBACK_BANDS = [
        ['hours_before_min' => 168, 'refund_percentage' => '90.00', 'flat_fee' => '0.00', 'label' => '7+ days before travel'],
        ['hours_before_min' => 72, 'refund_percentage' => '50.00', 'flat_fee' => '0.00', 'label' => '3–7 days before travel'],
        ['hours_before_min' => 24, 'refund_percentage' => '25.00', 'flat_fee' => '0.00', 'label' => '24–72 hours before travel'],
        ['hours_before_min' => 0, 'refund_percentage' => '0.00', 'flat_fee' => '0.00', 'label' => 'Less than 24 hours / no-show'],
    ];

    protected $fillable = [
        'tenant_id',
        'name',
        'module',
        'booking_scope',
        'supplier_code',
        'origin',
        'destination',
        'bands',
        'priority',
        'is_active',
    ];

    protected $casts = [
        'bands' => 'array',
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $policy) {
            if (empty($policy->booking_scope)) {
                $policy->booking_scope = self::SCOPE_BOTH;
            }

            if (empty($policy->priority)) {
                $policy->priority = 1;
            }
        });
    }

    /**
     * How narrowly this policy is scoped — the same idea as
     * MarkupService::specificity(), used to pick between several matching
     * policies (module+scope+supplier+route all narrow it further).
     */
    public function specificity(): int
    {
        return ($this->tenant_id !== null ? 4 : 0)
            + ($this->module !== null ? 2 : 0)
            + ($this->supplier_code !== null ? 2 : 0)
            + ($this->booking_scope !== self::SCOPE_BOTH ? 1 : 0)
            + ($this->destination !== null ? 1 : 0);
    }

    public function matches(Booking $booking): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if ($this->tenant_id !== null && $this->tenant_id !== $booking->tenant_id) {
            return false;
        }

        if ($this->module !== null && $this->module !== $booking->module) {
            return false;
        }

        if ($this->booking_scope === self::SCOPE_DOMESTIC && $booking->is_international) {
            return false;
        }

        if ($this->booking_scope === self::SCOPE_INTERNATIONAL && ! $booking->is_international) {
            return false;
        }

        if ($this->supplier_code !== null && strtoupper((string) $this->supplier_code) !== strtoupper((string) $booking->supplier_code)) {
            return false;
        }

        if ($this->origin !== null || $this->destination !== null) {
            $segments = $booking->relationLoaded('segments') ? $booking->segments : $booking->segments()->get();

            $matchesRoute = $segments->contains(function ($segment) {
                $originOk = $this->origin === null || strtoupper((string) $segment->origin) === strtoupper($this->origin);
                $destinationOk = $this->destination === null || strtoupper((string) $segment->destination) === strtoupper($this->destination);

                return $originOk && $destinationOk;
            });

            if (! $matchesRoute) {
                return false;
            }
        }

        return true;
    }

    /**
     * The one band that applies at $hoursUntilTravel — bands sorted by
     * hours_before_min descending, first one the booking still clears. A
     * negative $hoursUntilTravel (travel date already passed) falls through
     * to the lowest band, same as a same-day no-show would.
     */
    public static function bandFor(array $bands, int $hoursUntilTravel): array
    {
        $sorted = collect($bands)->sortByDesc('hours_before_min')->values();

        foreach ($sorted as $band) {
            if ($hoursUntilTravel >= (int) $band['hours_before_min']) {
                return $band;
            }
        }

        // Every band's hours_before_min was somehow greater than this — fall
        // back to the strictest (lowest) band rather than refunding nothing
        // matched at all.
        return $sorted->last() ?? self::FALLBACK_BANDS[array_key_last(self::FALLBACK_BANDS)];
    }
}
