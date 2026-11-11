<?php

namespace App\Domains\Cashback\Models;

use App\Domains\Booking\Models\Booking;
use Illuminate\Database\Eloquent\Model;

/**
 * Configurable cashback rule engine — "define in the CRM based on airline
 * or date or location or duration or product" per the feature request.
 * Deliberately mirrors App\Domains\Coupons\Models\Coupon: tenant_id NULL is
 * a platform-wide rule (not tenant-scoped automatically, same as Coupon
 * and PaymentGatewayConfig — see those models' docblocks), flat-or-
 * percentage value, status/validity-window lifecycle. CashbackService
 * picks the highest-priority active rule whose matchesBooking() passes.
 */
class CashbackRule extends Model
{
    public const CALC_FLAT = 'flat';

    public const CALC_PERCENTAGE = 'percentage';

    public const SCOPE_DOMESTIC = 'domestic';

    public const SCOPE_INTERNATIONAL = 'international';

    public const SCOPE_BOTH = 'both';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_PAUSED = 'paused';

    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'tenant_id',
        'name',
        'applicable_modules',
        'airline_code',
        'origin_country',
        'destination_country',
        'booking_scope',
        'valid_from',
        'valid_to',
        'min_duration_days',
        'max_duration_days',
        'calc_type',
        'value',
        'max_cashback_amount',
        'priority',
        'status',
    ];

    protected $casts = [
        'applicable_modules' => 'array',
        'valid_from' => 'date',
        'valid_to' => 'date',
        'value' => 'decimal:2',
        'max_cashback_amount' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $rule) {
            if (empty($rule->status)) {
                $rule->status = self::STATUS_DRAFT;
            }

            if (empty($rule->booking_scope)) {
                $rule->booking_scope = self::SCOPE_BOTH;
            }

            if (empty($rule->priority)) {
                $rule->priority = 1;
            }
        });
    }

    public function isCurrentlyActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * All location/date/duration/product matching in one place so
     * CashbackService stays a thin lookup. Every dimension left unset on
     * the rule matches anything; every set dimension must match.
     */
    public function matchesBooking(Booking $booking): bool
    {
        if (! $this->isCurrentlyActive()) {
            return false;
        }

        if (! empty($this->applicable_modules) && ! in_array($booking->module, $this->applicable_modules, true)) {
            return false;
        }

        if ($this->booking_scope === self::SCOPE_DOMESTIC && $booking->is_international) {
            return false;
        }

        if ($this->booking_scope === self::SCOPE_INTERNATIONAL && ! $booking->is_international) {
            return false;
        }

        $segments = $booking->relationLoaded('segments') ? $booking->segments : $booking->segments()->get();

        if ($this->airline_code) {
            $needle = strtolower($this->airline_code);
            $matchesAirline = $segments->contains(
                fn ($segment) => $segment->carrier_or_provider && str_contains(strtolower($segment->carrier_or_provider), $needle)
            );

            if (! $matchesAirline) {
                return false;
            }
        }

        if ($this->origin_country || $this->destination_country) {
            $codes = $segments
                ->flatMap(fn ($segment) => [$segment->origin, $segment->destination])
                ->filter()
                ->map(fn ($code) => strtoupper($code))
                ->unique();

            $countriesByCode = \App\Domains\Booking\Models\Airport::query()
                ->whereIn('code', $codes)
                ->pluck('country_code', 'code');

            $originMatches = ! $this->origin_country || $segments->contains(
                fn ($segment) => $countriesByCode->get(strtoupper((string) $segment->origin)) === $this->origin_country
            );
            $destinationMatches = ! $this->destination_country || $segments->contains(
                fn ($segment) => $countriesByCode->get(strtoupper((string) $segment->destination)) === $this->destination_country
            );

            if (! $originMatches || ! $destinationMatches) {
                return false;
            }
        }

        if ($this->valid_from && $booking->travel_date_start && $booking->travel_date_start->lt($this->valid_from)) {
            return false;
        }

        if ($this->valid_to && $booking->travel_date_start && $booking->travel_date_start->gt($this->valid_to)) {
            return false;
        }

        if (($this->min_duration_days || $this->max_duration_days) && $booking->travel_date_end) {
            $durationDays = $booking->travel_date_start->diffInDays($booking->travel_date_end);

            if ($this->min_duration_days && $durationDays < $this->min_duration_days) {
                return false;
            }

            if ($this->max_duration_days && $durationDays > $this->max_duration_days) {
                return false;
            }
        }

        return true;
    }
}
