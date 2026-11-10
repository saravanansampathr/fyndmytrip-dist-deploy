<?php

namespace App\Domains\Suppliers\Services;

use App\Domains\Booking\Models\BusSeatLock;
use App\Support\BusType;

/**
 * Generates a deterministic seat layout for a given bus trip
 * (supplier_code + external_id + travel_date always shows the same map),
 * shaped by its seat type — mirrors FlightSeatMapService's approach
 * (deterministic base layout, a realistic scattering of seats already
 * taken by "other" passengers, then overlaid with real reservations) but
 * for three distinct bus layouts instead of one aircraft cabin:
 *
 *  - Seater: a single-deck 2+2 grid (A/B | aisle | C/D) — same shape as a
 *    flight cabin. The two adjacent pairs are (A,B) and (C,D); B and C
 *    are never adjacent to each other (the aisle sits between them).
 *  - Sleeper: two decks (lower/upper — a "full sleeper" coach really does
 *    stack two berths), each row a 1+2 layout: a solo single berth on one
 *    side of the aisle (no adjacent seat at all) and a double berth on
 *    the other (its two berths are adjacent to each other).
 *  - Seat-cum-sleeper: the same 1+2 row shape as Sleeper, but a single
 *    deck only — these convert between a seat and a partial berth, they
 *    don't stack two full decks the way a sleeper coach does.
 *
 * A "double" position is the only place two strangers ever end up right
 * next to each other, so it's the only place the female-adjacency rule
 * has anything to do — single berths and lone seater seats have no
 * adjacent_seat_number at all and are never restricted.
 *
 * Female-adjacency rule: once a seat is occupied by a female passenger,
 * its adjacent seat — if still empty — becomes reservable by another
 * female passenger only, so a lone woman never ends up seated next to a
 * stranger who books in afterwards. A seat occupied by a male passenger
 * places no such restriction on the seat next to him.
 *
 * Fare tiers: "in bus have a different prices like seats, Single birth
 * and double birth and single lower and double lower and last row like
 * this have fare difference" — every seat carries a fare_multiplier
 * (applied to the offer's base net fare) plus a human-readable
 * fare_category. Seater has no differential (flat 1.0 — it's just
 * "seats"). Berth layouts (Sleeper/Seat-cum-Sleeper) get five tiers,
 * cheapest to priciest: Last row (0.85, overrides single/double
 * entirely) < Double Berth/upper (1.00, the reference/base tier) <
 * Single Berth/upper (1.10) < Double Lower (1.05) < Single Lower
 * (1.20) — "lower" here means the lower deck of a full two-deck
 * Sleeper, or the single deck of a Seat-cum-Sleeper (it has no upper
 * deck to compare against, so it's priced as the lower/main tier).
 */
class BusSeatMapService
{
    /** Cheapest-tier multiplier a seat_type can ever show — used for "fares start at X". */
    public function minFareMultiplier(string $seatType): float
    {
        return $seatType === BusType::SEATER ? 1.0 : 0.85;
    }

    /**
     * @return array{
     *   supplier_code: string, external_id: string, travel_date: string,
     *   bus_type: string, seat_type: string, total_seats: int,
     *   available_seats: int,
     *   rows: array<int, array{deck: string, row: int, seats: array<int, array{
     *     seat_number: string, deck: string, row: int, position: string,
     *     adjacent_seat_number: ?string, state: string,
     *     fare_multiplier: float, fare_category: ?string
     *   }>}>
     * }
     */
    public function generate(string $supplierCode, string $externalId, string $travelDate, string $busType, int $totalSeats): array
    {
        $totalSeats = max(1, min(80, $totalSeats));
        $seatType = BusType::seatType($busType);

        $skeleton = match ($seatType) {
            BusType::SLEEPER => $this->berthLayout($totalSeats, decked: true),
            BusType::SEAT_CUM_SLEEPER => $this->berthLayout($totalSeats, decked: false),
            default => $this->seaterLayout($totalSeats),
        };

        $occupied = $this->deterministicallyOccupiedSeats($supplierCode, $externalId, $travelDate, $skeleton);

        $locks = BusSeatLock::query()
            ->where('supplier_code', $supplierCode)
            ->where('external_id', $externalId)
            ->whereDate('travel_date', $travelDate)
            ->get(['seat_number', 'gender']);

        foreach ($locks as $lock) {
            $occupied[$lock->seat_number] = $lock->gender;
        }

        $states = $this->applyOccupancyAndAdjacency($skeleton, $occupied);

        $byDeckAndRow = [];
        foreach ($skeleton as $seat) {
            $byDeckAndRow[$seat['deck']][$seat['row']][] = array_merge($seat, ['state' => $states[$seat['seat_number']]]);
        }

        $rows = [];
        foreach ($byDeckAndRow as $deck => $rowsByNumber) {
            ksort($rowsByNumber);
            foreach ($rowsByNumber as $rowNumber => $seats) {
                $rows[] = ['deck' => $deck, 'row' => $rowNumber, 'seats' => $seats];
            }
        }

        $availableCount = 0;
        foreach ($states as $state) {
            if ($state === 'available' || $state === 'female_only') {
                $availableCount++;
            }
        }

        return [
            'supplier_code' => $supplierCode,
            'external_id' => $externalId,
            'travel_date' => $travelDate,
            'bus_type' => $busType,
            'seat_type' => $seatType,
            'total_seats' => count($skeleton),
            'available_seats' => $availableCount,
            'rows' => $rows,
        ];
    }

    /**
     * Atomically reserve a seat for one passenger; throws if it's already
     * taken (someone else grabbed it since the map was fetched, or it was
     * one of the deterministic "other passenger" seats) — the availability
     * and female-adjacency check itself happens in the controller against
     * a freshly generate()'d map before this is called; this only guards
     * the actual race via the DB's unique constraint.
     *
     * @throws \RuntimeException
     */
    public function reserve(string $supplierCode, string $externalId, string $travelDate, string $seatNumber, string $gender, int $bookingId, ?int $bookingPassengerId): BusSeatLock
    {
        try {
            return BusSeatLock::query()->create([
                'supplier_code' => $supplierCode,
                'external_id' => $externalId,
                'travel_date' => $travelDate,
                'seat_number' => $seatNumber,
                'gender' => $gender,
                'booking_id' => $bookingId,
                'booking_passenger_id' => $bookingPassengerId,
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            throw new \RuntimeException("Seat {$seatNumber} is no longer available. Please pick another seat.", 0, $e);
        }
    }

    public function releaseForBooking(int $bookingId): void
    {
        BusSeatLock::query()->where('booking_id', $bookingId)->delete();
    }

    /**
     * Single-deck 2+2 grid: A,B | aisle | C,D. Adjacent pairs are (A,B)
     * and (C,D) only — B and C sit across the aisle from each other.
     *
     * @return array<int, array{seat_number: string, deck: string, row: int, position: string, adjacent_seat_number: ?string}>
     */
    private function seaterLayout(int $totalSeats): array
    {
        $columns = ['A', 'B', 'C', 'D'];
        $pairOf = ['A' => 'B', 'B' => 'A', 'C' => 'D', 'D' => 'C'];
        $seats = [];
        $seatIndex = 0;

        for ($row = 1; $seatIndex < $totalSeats; $row++) {
            foreach ($columns as $col) {
                if ($seatIndex >= $totalSeats) {
                    break;
                }

                $seats[] = [
                    'seat_number' => $row.$col,
                    'deck' => 'main',
                    'row' => $row,
                    'position' => in_array($col, ['A', 'D'], true) ? 'window' : 'aisle',
                    'adjacent_seat_number' => $row.$pairOf[$col],
                    // Seater has no fare differential — "seats" is a single flat tier.
                    'fare_multiplier' => 1.0,
                    'fare_category' => null,
                ];
                $seatIndex++;
            }
        }

        return $this->pruneDanglingAdjacency($seats);
    }

    /**
     * 1+2 row shape (a solo single berth + a double berth, separated by
     * the aisle), optionally stacked as lower/upper decks for a full
     * Sleeper coach, or kept to one deck for Seat-cum-Sleeper.
     *
     * @return array<int, array{seat_number: string, deck: string, row: int, position: string, adjacent_seat_number: ?string}>
     */
    private function berthLayout(int $totalSeats, bool $decked): array
    {
        $decks = $decked ? ['lower', 'upper'] : ['main'];
        $berthsPerRow = 3; // A (single) + B/C (double, adjacent to each other)
        $perDeck = (int) ceil($totalSeats / count($decks));
        $rowsPerDeck = (int) ceil($perDeck / $berthsPerRow);

        $seats = [];
        $seatIndex = 0;
        $maxRowByDeck = [];

        foreach ($decks as $deck) {
            // Single-deck (seat-cum-sleeper) seat numbers need no deck
            // prefix; a full sleeper coach prefixes with L/U so "1A" on
            // the lower deck and "1A" on the upper deck never collide.
            $prefix = $decked ? strtoupper(substr($deck, 0, 1)) : '';

            for ($row = 1; $row <= $rowsPerDeck && $seatIndex < $totalSeats; $row++) {
                foreach (['A', 'B', 'C'] as $col) {
                    if ($seatIndex >= $totalSeats) {
                        break;
                    }

                    $adjacent = match ($col) {
                        'B' => $prefix.$row.'C',
                        'C' => $prefix.$row.'B',
                        default => null,
                    };

                    $seats[] = [
                        'seat_number' => $prefix.$row.$col,
                        'deck' => $deck,
                        'row' => $row,
                        'position' => $col === 'A' ? 'single' : ($col === 'B' ? 'double_window' : 'double_aisle'),
                        'adjacent_seat_number' => $adjacent,
                    ];
                    $seatIndex++;
                }

                // Tracks the highest row number actually generated for this
                // deck (a deck's last row can be short a berth or two when
                // totalSeats isn't a clean multiple of the row width), so
                // the fare-tier pass below can tell which seats are really
                // in the last row rather than assuming it's $rowsPerDeck.
                $maxRowByDeck[$deck] = $row;
            }
        }

        foreach ($seats as &$seat) {
            $isLastRow = $seat['row'] === ($maxRowByDeck[$seat['deck']] ?? $seat['row']);
            [$seat['fare_multiplier'], $seat['fare_category']] = $this->berthFareTier($seat['deck'], $seat['position'], $isLastRow, $decked);
        }
        unset($seat);

        return $this->pruneDanglingAdjacency($seats);
    }

    /**
     * @return array{0: float, 1: string}
     */
    private function berthFareTier(string $deck, string $position, bool $isLastRow, bool $decked): array
    {
        // Cheapest tier, regardless of single/double — "last row like this
        // have fare difference" was named as its own category.
        if ($isLastRow) {
            return [0.85, 'Last row'];
        }

        // The lower deck of a full two-deck Sleeper (or the only deck a
        // Seat-cum-Sleeper has) is the easier-to-reach, higher-demand tier.
        $isLowerTier = ! $decked || $deck === 'lower';

        if ($position === 'single') {
            return $isLowerTier ? [1.20, 'Single Lower'] : [1.10, 'Single Berth'];
        }

        // Double berths (the two adjacent seats across 'B'/'C').
        return $isLowerTier ? [1.05, 'Double Lower'] : [1.00, 'Double Berth'];
    }

    /**
     * The last row of a deck can be short a berth (totalSeats isn't
     * always a clean multiple of the row width) — null out an
     * adjacent_seat_number that points at a seat which never got
     * generated, so that half of a pair is never treated as "adjacent to
     * a seat that doesn't exist".
     */
    private function pruneDanglingAdjacency(array $seats): array
    {
        $existing = array_flip(array_column($seats, 'seat_number'));

        foreach ($seats as &$seat) {
            if ($seat['adjacent_seat_number'] !== null && ! isset($existing[$seat['adjacent_seat_number']])) {
                $seat['adjacent_seat_number'] = null;
            }
        }

        return $seats;
    }

    /**
     * @param array<int, array{seat_number: string}> $skeleton
     * @return array<string, string> seat_number => gender ('male'|'female')
     */
    private function deterministicallyOccupiedSeats(string $supplierCode, string $externalId, string $travelDate, array $skeleton): array
    {
        $occupied = [];

        foreach ($skeleton as $seat) {
            $seed = crc32($supplierCode.'|'.$externalId.'|'.$travelDate.'|'.$seat['seat_number']);

            // ~22% of seats look pre-occupied by "other" passengers, same
            // realism convention as FlightSeatMapService — a deterministic
            // gender split off the same seed keeps it stable across
            // requests while staying roughly balanced male/female.
            if ($seed % 100 < 22) {
                $occupied[$seat['seat_number']] = $seed % 2 === 0 ? 'male' : 'female';
            }
        }

        return $occupied;
    }

    /**
     * @param array<int, array{seat_number: string, adjacent_seat_number: ?string}> $skeleton
     * @param array<string, string> $occupied seat_number => gender
     * @return array<string, string> seat_number => 'available'|'male'|'female'|'female_only'
     */
    private function applyOccupancyAndAdjacency(array $skeleton, array $occupied): array
    {
        $states = [];
        foreach ($skeleton as $seat) {
            $states[$seat['seat_number']] = $occupied[$seat['seat_number']] ?? 'available';
        }

        foreach ($skeleton as $seat) {
            $gender = $occupied[$seat['seat_number']] ?? null;
            $adjacent = $seat['adjacent_seat_number'];

            if ($gender === 'female' && $adjacent !== null && ($states[$adjacent] ?? null) === 'available') {
                $states[$adjacent] = 'female_only';
            }
        }

        return $states;
    }
}
