<?php

namespace App\Domains\Suppliers\Adapters;

use App\Domains\Suppliers\Contracts\SupplierAdapterInterface;
use App\Domains\Suppliers\DTOs\BookingConfirmResult;
use App\Domains\Suppliers\DTOs\BookingHoldResult;
use App\Domains\Suppliers\DTOs\FareRuleResult;
use App\Domains\Suppliers\DTOs\SearchRequest;
use App\Domains\Suppliers\DTOs\SearchResultItem;
use App\Domains\Suppliers\DTOs\SupplierBalance;
use App\Domains\Suppliers\Exceptions\SupplierException;
use App\Support\BusType;
use Illuminate\Support\Str;

/**
 * A deterministic fake bus supplier — Phase 3's "New Verticals & Real
 * Booking Flows", started with Bus since it already had the most
 * groundwork (an inventory model + a manual test-booking tool). Same
 * pattern as MockFlightSupplierAdapter: every other layer (orchestrator,
 * booking flow, wallet debit) talks to this through the exact same
 * SupplierAdapterInterface a real bus-supplier API would implement, so
 * wiring one in later needs no changes outside app/Domains/Suppliers/Adapters.
 *
 * Every operator name, bus type, price, timing etc. below is entirely
 * fictional and derived deterministically from the route + date + operator,
 * so the same search always returns the same trips and fares — useful for
 * demos and tests without a database of fake inventory.
 */
class MockBusSupplierAdapter implements SupplierAdapterInterface
{
    /**
     * Fictional bus operators. Each entry: [name, 2-letter code, bus type,
     * base fare multiplier (a no-frills seater vs. a premium sleeper),
     * chassis/vehicle brand — a real-world facet ("Volvo", "Scania") that
     * has no free-text field to derive it from, unlike AC/non-AC and seat
     * type which BusType parses straight out of the bus type string.
     */
    private const OPERATORS = [
        ['Sundram Travels', 'ST', 'AC Sleeper', 1.15, 'Volvo'],
        ['Highway Express', 'HE', 'Non-AC Seater', 0.70, 'Ashok Leyland'],
        ['Coastal Cruiser Lines', 'CC', 'AC Seater', 0.95, 'Eicher'],
        ['Volante Multi-Axle Travels', 'VM', 'AC Multi-Axle Sleeper', 1.40, 'Scania'],
        ['MockBus National', 'MB', 'AC Sleeper', 1.00, 'Mercedes-Benz'],
        ['Speedline Roadways', 'SL', 'Non-AC Sleeper', 0.85, 'Ashok Leyland'],
    ];

    private const BOARDING_POINTS = ['Central Bus Stand', 'Highway Junction Stop', 'City Bus Terminal', 'Transport Nagar Depot'];

    private const DROPPING_POINTS = ['Main Bus Depot', 'Ring Road Drop Point', 'Downtown Terminal', 'Outer Bypass Stop'];

    public function code(): string
    {
        return 'MOCKBUS';
    }

    public function searchAvailability(SearchRequest $request, array $credentials): array
    {
        $results = [];
        $routeSeed = $request->origin.'|'.$request->destination.'|'.$request->departureDate;

        // Intercity bus routes on this platform run roughly 4-14 hours,
        // derived from the route itself so the same O&D always feels the
        // same length across repeat searches.
        $baseDurationMinutes = 240 + $this->seededInt($routeSeed.'|duration', 0, 600);

        foreach (self::OPERATORS as [$name, $code, $busType, $priceMultiplier, $busBrand]) {
            $operatorSeed = $routeSeed.'|'.$code;

            // Not every operator runs every route every day.
            if ($this->seededInt($operatorSeed.'|offers', 0, 99) < 15) {
                continue;
            }

            $tripsForOperator = $this->seededInt($operatorSeed.'|count', 1, 2);

            for ($i = 0; $i < $tripsForOperator; $i++) {
                $tripSeed = $operatorSeed.'|'.$i;

                $departureHour = $this->seededInt($tripSeed.'|hour', 0, 23);
                $departureMinute = $this->seededInt($tripSeed.'|min', 0, 3) * 15;
                $priceSeedValue = $this->seededInt($tripSeed.'|price', 0, 900);
                $nightDiscount = ($departureHour >= 20 || $departureHour < 6) ? 1.08 : 1.0; // overnight sleepers price slightly higher

                $netAmount = number_format((450 + $priceSeedValue) * $priceMultiplier * $nightDiscount, 2, '.', '');
                $seatsAvailable = $this->seededInt($tripSeed.'|seats', 4, 40);
                $isRefundable = $this->seededInt($tripSeed.'|refund', 0, 1) === 1;
                $boardingPoint = self::BOARDING_POINTS[$this->seededInt($tripSeed.'|board', 0, count(self::BOARDING_POINTS) - 1)];
                $droppingPoint = self::DROPPING_POINTS[$this->seededInt($tripSeed.'|drop', 0, count(self::DROPPING_POINTS) - 1)];

                $departureAt = $request->departureDate.'T'.sprintf('%02d:%02d:00', $departureHour, $departureMinute);
                $arrivalAt = date('Y-m-d\TH:i:s', strtotime($departureAt) + $baseDurationMinutes * 60);
                $boardingPoints = $this->buildBoardingPoints($departureAt);
                $droppingPoints = $this->buildDroppingPoints($arrivalAt);

                $externalId = strtoupper(Str::random(10));

                $segments = [[
                    'sequence' => 1,
                    'origin' => $request->origin,
                    'destination' => $request->destination,
                    'departure_at' => $departureAt,
                    'arrival_at' => $arrivalAt,
                    'carrier_or_provider' => $name,
                    'fare_basis' => $busType,
                    'bus_type' => $busType,
                    'boarding_point' => $boardingPoint,
                    'dropping_point' => $droppingPoint,
                    'duration_minutes' => $baseDurationMinutes,
                ]];

                $results[] = new SearchResultItem(
                    supplierCode: $this->code(),
                    externalId: $externalId,
                    netAmount: $netAmount,
                    currency: 'INR',
                    carrierOrProvider: $name,
                    fareBasis: $busType,
                    segments: $segments,
                    raw: ['mock' => true, 'external_id' => $externalId, 'boarding_point' => $boardingPoint, 'dropping_point' => $droppingPoint],
                    durationMinutes: $baseDurationMinutes,
                    stops: 0,
                    isRefundable: $isRefundable,
                    seatsAvailable: $seatsAvailable,
                    busType: $busType,
                    boardingPoint: $boardingPoint,
                    droppingPoint: $droppingPoint,
                    isAc: BusType::isAc($busType),
                    seatType: BusType::seatType($busType),
                    busBrand: $busBrand,
                    boardingPoints: $boardingPoints,
                    droppingPoints: $droppingPoints,
                );
            }
        }

        // Guarantee at least a couple of options even on an unlucky seed.
        if (count($results) < 2) {
            return $this->guaranteedFallback($request);
        }

        return $results;
    }

    public function priceCheck(string $externalId, array $credentials): FareRuleResult
    {
        $seed = crc32($externalId);
        $netAmount = number_format(450 + ($seed % 900), 2, '.', '');

        return new FareRuleResult(
            isStillAvailable: true,
            confirmedNetAmount: $netAmount,
            isRefundable: $seed % 3 !== 0,
            rules: ['cancellation_fee' => 'As per the operator\'s policy up to 6 hours before departure, thereafter non-refundable.'],
        );
    }

    public function holdBooking(string $externalId, array $passengers, array $credentials): BookingHoldResult
    {
        if ($passengers === []) {
            throw new SupplierException($this->code(), 'At least one passenger is required to hold a booking.');
        }

        $seed = crc32($externalId);
        $netAmount = number_format(450 + ($seed % 900), 2, '.', '');

        return new BookingHoldResult(
            supplierBookingRef: 'MOCKBUS-'.strtoupper(Str::random(8)),
            holdExpiresAt: new \DateTimeImmutable('+30 minutes'),
            netAmount: $netAmount,
        );
    }

    public function confirmBooking(string $supplierBookingRef, array $credentials): BookingConfirmResult
    {
        if (! str_starts_with($supplierBookingRef, 'MOCKBUS-')) {
            throw new SupplierException($this->code(), "Unknown supplier booking reference: {$supplierBookingRef}");
        }

        return new BookingConfirmResult(
            supplierBookingRef: $supplierBookingRef,
            isTicketed: true,
            ticketNumbers: ['TKT-'.strtoupper(Str::random(10))],
        );
    }

    public function retrieveBooking(string $supplierBookingRef, array $credentials): array
    {
        return [
            'supplier_booking_ref' => $supplierBookingRef,
            'status' => 'ticketed',
        ];
    }

    public function cancelBooking(string $supplierBookingRef, array $credentials): bool
    {
        return true;
    }

    public function getRefundStatus(string $supplierBookingRef, array $credentials): string
    {
        return 'processed';
    }

    public function getSupplierAccountBalance(array $credentials): SupplierBalance
    {
        return new SupplierBalance(amount: '250000.00', currency: 'INR');
    }

    /**
     * All four BOARDING_POINTS pool entries, each with a synthetic pickup
     * time before departure — a real intercity bus picks up at several
     * stops on its way out of town before the route really starts, so the
     * times ascend towards (and the last one lands exactly on) departure.
     *
     * @return array<int, array{point: string, time: string}>
     */
    private function buildBoardingPoints(string $departureAt): array
    {
        $offsetsMinutesBefore = [60, 40, 20, 0];
        $departureTs = strtotime($departureAt);

        return array_map(
            fn (string $point, int $index) => [
                'point' => $point,
                'time' => date('H:i', $departureTs - ($offsetsMinutesBefore[$index] ?? 0) * 60),
            ],
            self::BOARDING_POINTS,
            array_keys(self::BOARDING_POINTS),
        );
    }

    /**
     * All four DROPPING_POINTS pool entries, each with a synthetic drop-off
     * time after arrival — mirrors buildBoardingPoints() but spreading
     * forward from arrival instead of back from departure.
     *
     * @return array<int, array{point: string, time: string}>
     */
    private function buildDroppingPoints(string $arrivalAt): array
    {
        $offsetsMinutesAfter = [0, 15, 30, 45];
        $arrivalTs = strtotime($arrivalAt);

        return array_map(
            fn (string $point, int $index) => [
                'point' => $point,
                'time' => date('H:i', $arrivalTs + ($offsetsMinutesAfter[$index] ?? 0) * 60),
            ],
            self::DROPPING_POINTS,
            array_keys(self::DROPPING_POINTS),
        );
    }

    /**
     * Deterministic pseudo-random integer in [$min, $max] derived from an
     * arbitrary string key — same technique as MockFlightSupplierAdapter.
     */
    private function seededInt(string $key, int $min, int $max): int
    {
        $range = $max - $min + 1;

        if ($range <= 0) {
            return $min;
        }

        return $min + (crc32($key) % $range);
    }

    /**
     * @return array<int, SearchResultItem>
     */
    private function guaranteedFallback(SearchRequest $request): array
    {
        $operator = self::OPERATORS[0];
        $results = [];

        foreach ([0, 1] as $index) {
            $departureAt = $request->departureDate.'T'.sprintf('%02d:00:00', 6 + $index * 12);
            $durationMinutes = 360;
            $arrivalAt = date('Y-m-d\TH:i:s', strtotime($departureAt) + $durationMinutes * 60);
            $externalId = strtoupper(Str::random(10));
            $boardingPoints = $this->buildBoardingPoints($departureAt);
            $droppingPoints = $this->buildDroppingPoints($arrivalAt);

            $results[] = new SearchResultItem(
                supplierCode: $this->code(),
                externalId: $externalId,
                netAmount: number_format(650 + $index * 150, 2, '.', ''),
                currency: 'INR',
                carrierOrProvider: $operator[0],
                fareBasis: $operator[2],
                segments: [[
                    'sequence' => 1,
                    'origin' => $request->origin,
                    'destination' => $request->destination,
                    'departure_at' => $departureAt,
                    'arrival_at' => $arrivalAt,
                    'carrier_or_provider' => $operator[0],
                    'fare_basis' => $operator[2],
                    'bus_type' => $operator[2],
                    'boarding_point' => self::BOARDING_POINTS[0],
                    'dropping_point' => self::DROPPING_POINTS[0],
                    'duration_minutes' => $durationMinutes,
                ]],
                raw: ['mock' => true, 'external_id' => $externalId],
                durationMinutes: $durationMinutes,
                stops: 0,
                isRefundable: true,
                seatsAvailable: 30,
                busType: $operator[2],
                boardingPoint: self::BOARDING_POINTS[0],
                droppingPoint: self::DROPPING_POINTS[0],
                isAc: BusType::isAc($operator[2]),
                seatType: BusType::seatType($operator[2]),
                busBrand: $operator[4],
                boardingPoints: $boardingPoints,
                droppingPoints: $droppingPoints,
            );
        }

        return $results;
    }
}
