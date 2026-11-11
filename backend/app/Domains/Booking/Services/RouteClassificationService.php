<?php

namespace App\Domains\Booking\Services;

use App\Domains\Booking\Models\Airport;
use App\Domains\Booking\Models\Booking;

/**
 * Answers one question for the payment-gateway routing requirement: is
 * this booking domestic or international? A booking is domestic only when
 * every segment's origin AND destination resolve (via the airports table)
 * to the platform's home country (config('booking.home_country'), default
 * 'IN'). Anything else — a foreign country on either end, or a segment
 * between two non-home countries — is international.
 *
 * An airport code that isn't in the airports table is treated as
 * domestic. This is a deliberate, documented default: the mock supplier
 * accepts any free-text 3-letter code the search form sends, and this
 * system ships with no real GDS airport master, so treating unknown codes
 * as international would silently break every existing demo search that
 * predates this feature. Only codes actually seeded as foreign (see the
 * create_airports_table migration) can ever route a booking to the
 * payment gateway. Extending coverage is a matter of adding rows to
 * `airports`, not touching this class.
 */
class RouteClassificationService
{
    public function isInternational(Booking $booking): bool
    {
        $segments = $booking->relationLoaded('segments')
            ? $booking->segments
            : $booking->segments()->get();

        if ($segments->isEmpty()) {
            return false;
        }

        foreach ($segments as $segment) {
            if ($this->isInternationalCode($segment->origin, $segment->destination)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Same classification as isInternational(), but from raw airport codes
     * rather than a persisted Booking's segments — for the markup-scoping
     * roadmap piece ("Markup should be in like Domestic & International"),
     * which needs to know domestic/international at search time and at
     * hold() time, both before the booking (and its segments) exist yet.
     */
    public function isInternationalCode(?string $origin, ?string $destination): bool
    {
        $homeCountry = config('booking.home_country', 'IN');

        $codes = collect([$origin, $destination])
            ->filter()
            ->map(fn ($code) => strtoupper($code))
            ->unique()
            ->values();

        if ($codes->isEmpty()) {
            return false;
        }

        $countriesByCode = Airport::query()
            ->whereIn('code', $codes)
            ->pluck('country_code', 'code');

        $originCountry = $countriesByCode->get(strtoupper((string) $origin), $homeCountry);
        $destinationCountry = $countriesByCode->get(strtoupper((string) $destination), $homeCountry);

        return $originCountry !== $homeCountry || $destinationCountry !== $homeCountry;
    }
}
