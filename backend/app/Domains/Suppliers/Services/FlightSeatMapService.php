<?php

namespace App\Domains\Suppliers\Services;

use App\Domains\Booking\Models\FlightSeatLock;
use Carbon\Carbon;

/**
 * Generates a deterministic seat layout for a given flight_number +
 * aircraft_type (same flight always shows the same map), overlays a
 * realistic-looking scattering of seats already taken by "other"
 * passengers (deterministic, not real data), and then overlays real
 * reservations made through this platform (flight_seat_locks) so two
 * different bookings can never be assigned the same seat.
 */
class FlightSeatMapService
{
    public const EXTRA_LEGROOM_FEE = '300.00';

    /**
     * @return array{
     *   flight_number: string,
     *   aircraft_type: string,
     *   columns: array<int, string>,
     *   rows: array<int, array{row: int, seats: array<int, array{seat_number: string, available: bool, fare: string, is_extra_legroom: bool}>}>
     * }
     */
    public function generate(string $flightNumber, string $travelDate, string $aircraftType): array
    {
        $isSmallAircraft = $aircraftType === 'ATR 72-600';
        $columns = $isSmallAircraft ? ['A', 'B', 'C', 'D'] : ['A', 'B', 'C', 'D', 'E', 'F'];
        $aisleAfter = $isSmallAircraft ? 'B' : 'C';
        $rowCount = $isSmallAircraft ? 18 : 20;
        $extraLegroomRows = $isSmallAircraft ? [1] : [1, 13];

        $bookedElsewhere = $this->deterministicallyOccupiedSeats($flightNumber, $travelDate, $columns, $rowCount);

        $lockedSeats = FlightSeatLock::query()
            ->where('flight_number', $flightNumber)
            ->where('travel_date', $travelDate)
            ->pluck('seat_number')
            ->all();

        $taken = array_unique(array_merge($bookedElsewhere, $lockedSeats));

        $rows = [];

        for ($row = 1; $row <= $rowCount; $row++) {
            $isExtraLegroom = in_array($row, $extraLegroomRows, true);
            $seats = [];

            foreach ($columns as $col) {
                $seatNumber = $row.$col;

                $seats[] = [
                    'seat_number' => $seatNumber,
                    'available' => ! in_array($seatNumber, $taken, true),
                    'fare' => $isExtraLegroom ? self::EXTRA_LEGROOM_FEE : '0.00',
                    'is_extra_legroom' => $isExtraLegroom,
                    'is_aisle' => $col === $aisleAfter,
                ];
            }

            $rows[] = ['row' => $row, 'seats' => $seats];
        }

        return [
            'flight_number' => $flightNumber,
            'travel_date' => $travelDate,
            'aircraft_type' => $aircraftType,
            'columns' => $columns,
            'aisle_after' => $aisleAfter,
            'rows' => $rows,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function deterministicallyOccupiedSeats(string $flightNumber, string $travelDate, array $columns, int $rowCount): array
    {
        $occupied = [];

        foreach (range(1, $rowCount) as $row) {
            foreach ($columns as $col) {
                $seatNumber = $row.$col;
                $seed = crc32($flightNumber.'|'.$travelDate.'|'.$seatNumber);

                // ~22% of seats look pre-occupied by "other" passengers.
                if ($seed % 100 < 22) {
                    $occupied[] = $seatNumber;
                }
            }
        }

        return $occupied;
    }

    /**
     * Atomically reserve a seat for one passenger; throws if it's already
     * taken (someone else grabbed it since the map was fetched, or it was
     * one of the deterministic "other passenger" seats).
     *
     * @throws \RuntimeException
     */
    public function reserve(string $flightNumber, string $travelDate, string $seatNumber, int $bookingId, int $passengerId): FlightSeatLock
    {
        try {
            return FlightSeatLock::query()->create([
                'flight_number' => $flightNumber,
                'travel_date' => $travelDate,
                'seat_number' => $seatNumber,
                'booking_id' => $bookingId,
                'booking_passenger_id' => $passengerId,
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            throw new \RuntimeException("Seat {$seatNumber} is no longer available. Please pick another seat.", 0, $e);
        }
    }

    public function releaseForBooking(int $bookingId): void
    {
        FlightSeatLock::query()->where('booking_id', $bookingId)->delete();
    }
}
