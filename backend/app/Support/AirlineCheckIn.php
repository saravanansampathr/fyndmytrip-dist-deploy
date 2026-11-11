<?php

namespace App\Support;

/**
 * Platform Roadmap Phase 5 — "Airline check-in deep link on flight
 * e-tickets." Derives the 2-character IATA carrier code from a flight
 * number ("6E-2341", "6E 2341", "6E2341" all -> "6E") and looks it up in
 * config/airline_checkin_urls.php. Used from the flight e-ticket PDF
 * (resources/views/pdf/flight-eticket.blade.php) and mirrored on the
 * frontend (frontend/src/data/airlineCheckIn.ts) for the booking
 * confirmation screen — keep both tables in sync when adding a carrier.
 */
class AirlineCheckIn
{
    public static function deriveCode(?string $flightNumber): ?string
    {
        if (! $flightNumber) {
            return null;
        }

        $clean = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $flightNumber));

        return preg_match('/^([A-Z0-9]{2})/', $clean, $m) ? $m[1] : null;
    }

    /** @return array{name: string, checkin_url: string}|null */
    public static function lookup(?string $flightNumber): ?array
    {
        $code = self::deriveCode($flightNumber);

        if (! $code) {
            return null;
        }

        $entry = config('airline_checkin_urls.'.$code);

        return $entry ?: null;
    }

    /**
     * The URL to show as "Check in online" for this flight number, with the
     * PNR appended as a query parameter only for a carrier whose entry
     * explicitly declares `pnr_param` (confirmed to accept it that way) —
     * see the config file's own docblock for why this isn't done for
     * every carrier.
     */
    public static function checkInUrl(?string $flightNumber, ?string $pnr = null): ?string
    {
        $code = self::deriveCode($flightNumber);

        if (! $code) {
            return null;
        }

        $entry = config('airline_checkin_urls.'.$code);

        if (! $entry) {
            return null;
        }

        $url = $entry['checkin_url'];

        if ($pnr && ! empty($entry['pnr_param'])) {
            $separator = str_contains($url, '?') ? '&' : '?';
            $url .= $separator.$entry['pnr_param'].'='.urlencode($pnr);
        }

        return $url;
    }
}
