<?php

namespace App\Support;

/**
 * Bus search filters — "boarding, dropping points, Seater, Sleeper, Seat
 * Cum Sleeper, Ac, No Ac" — need AC/non-AC and seat type as two
 * independent, checkable facets, but the platform has only ever stored a
 * single free-text bus_type string ("AC Sleeper", "Non-AC Seater"), both
 * on MockBusSupplierAdapter's fictional operators and on a tenant's own
 * manually-entered bus_inventory_items row. Rather than a migration to
 * split that column (and a backfill for every existing row), this parses
 * the two facets out of whatever string is already there — the same
 * "derive it, don't store it twice" instinct as MyBookingsController's
 * travelSummaryFor(). New offers can still set is_ac/seat_type precisely
 * (see MockBusSupplierAdapter); this is what makes every OLD row filter
 * correctly too, with no backfill required.
 */
class BusType
{
    public const SEATER = 'seater';

    public const SLEEPER = 'sleeper';

    public const SEAT_CUM_SLEEPER = 'seat_cum_sleeper';

    public static function isAc(?string $busType): bool
    {
        $lower = strtolower($busType ?? '');

        if (str_contains($lower, 'non-ac') || str_contains($lower, 'non ac')) {
            return false;
        }

        // Unlabeled/ambiguous defaults to AC — the large majority of
        // intercity coaches sold on this platform are AC, and every
        // fictional operator that isn't AC says "Non-AC" explicitly.
        return true;
    }

    public static function seatType(?string $busType): string
    {
        $lower = strtolower($busType ?? '');

        if (str_contains($lower, 'seat cum sleeper')
            || str_contains($lower, 'seater cum sleeper')
            || str_contains($lower, 'seat-cum-sleeper')
        ) {
            return self::SEAT_CUM_SLEEPER;
        }

        if (str_contains($lower, 'sleeper')) {
            return self::SLEEPER;
        }

        return self::SEATER;
    }
}
