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
use Illuminate\Support\Str;

/**
 * A deterministic fake flight supplier used until real TBO/TripJack/Akbar
 * Travels sandbox credentials are wired in — see the Supplier Adapter
 * pattern described in Master Brief §6. Every other layer of the platform
 * (orchestrator, booking flow, wallet debit, ticketing) talks to this
 * adapter through the exact same SupplierAdapterInterface a real adapter
 * would implement, so swapping in TBOAdapter later requires no changes
 * outside app/Domains/Suppliers/Adapters.
 *
 * Every airline, flight number, price, duration, stop pattern etc. below
 * is entirely fictional (no resemblance to any real carrier intended) and
 * is derived deterministically from the route + date + carrier, so the
 * same search always returns the same fares and the filters/sort a
 * frontend builds against them behave consistently across repeat visits
 * — useful for demos and tests without needing a database of fake
 * inventory.
 */
class MockFlightSupplierAdapter implements SupplierAdapterInterface
{
    /**
     * Fictional carriers. Each entry: [name, 2-letter code, base fare
     * multiplier (a cheap no-frills carrier vs. a pricier full-service
     * one), preferred aircraft].
     */
    private const CARRIERS = [
        ['AeroNova Airways', 'AN', 1.05, ['Airbus A320', 'Airbus A321neo']],
        ['BlueNest Air', 'BN', 0.85, ['Airbus A320']],
        ['Coastal Wings', 'CW', 0.95, ['Boeing 737-800']],
        ['Zephyr Airlines', 'ZE', 1.15, ['Airbus A321neo', 'Boeing 737-800']],
        ['SkyRath Express', 'SR', 0.80, ['ATR 72-600']],
        ['Prime Falcon Airways', 'PF', 1.25, ['Boeing 737-800']],
        ['MockAir Domestic', 'MA', 1.00, ['Airbus A320']],
        ['MockAir Express', 'MX', 0.90, ['Airbus A320', 'ATR 72-600']],
    ];

    /** Small pool of fictional hub cities used as layover points. */
    private const HUB_CITIES = ['HUB1', 'HUB2', 'HUB3', 'HUB4'];

    public function code(): string
    {
        return 'MOCKAIR';
    }

    public function searchAvailability(SearchRequest $request, array $credentials): array
    {
        $results = [];
        $routeSeed = $request->origin.'|'.$request->destination.'|'.$request->departureDate;

        // Roughly how long this fictional route takes non-stop, derived
        // from the route itself so the same O&D always feels the same
        // length (e.g. DEL-BOM always ~2h, not sometimes 2h sometimes 6h).
        $baseDurationMinutes = 75 + $this->seededInt($routeSeed.'|duration', 0, 165); // 75–240 min

        foreach (self::CARRIERS as $carrierIndex => [$carrierName, $code, $priceMultiplier, $aircraftPool]) {
            $carrierSeed = $routeSeed.'|'.$code;

            // Not every carrier flies every route every day.
            if ($this->seededInt($carrierSeed.'|offers', 0, 99) < 12) {
                continue;
            }

            $flightsForCarrier = $this->seededInt($carrierSeed.'|count', 1, 2);

            for ($i = 0; $i < $flightsForCarrier; $i++) {
                $flightSeed = $carrierSeed.'|'.$i;
                $flightNumber = $code.'-'.(100 + $this->seededInt($flightSeed.'|num', 0, 899));

                $departureHour = $this->seededInt($flightSeed.'|hour', 0, 23);
                $departureMinute = $this->seededInt($flightSeed.'|min', 0, 3) * 15;

                $isNonStop = $this->seededInt($flightSeed.'|stop', 0, 99) >= 38; // ~62% non-stop
                $fareBasis = $this->seededInt($flightSeed.'|fare', 0, 1) === 0 ? 'SAVER' : 'FLEX';
                $aircraft = $aircraftPool[$this->seededInt($flightSeed.'|aircraft', 0, count($aircraftPool) - 1)];

                $durationMinutes = $baseDurationMinutes;
                $layoverCity = null;
                $layoverMinutes = 0;

                if (! $isNonStop) {
                    $layoverCity = self::HUB_CITIES[$this->seededInt($flightSeed.'|hub', 0, count(self::HUB_CITIES) - 1)];
                    $layoverMinutes = 45 + $this->seededInt($flightSeed.'|layover', 0, 75); // 45–120 min
                    $durationMinutes = $baseDurationMinutes + $layoverMinutes + $this->seededInt($flightSeed.'|leg2', 20, 70);
                }

                $priceSeedValue = $this->seededInt($flightSeed.'|price', 0, 6500);
                $timeOfDayDiscount = ($departureHour < 6 || $departureHour >= 22) ? 0.88 : 1.0;
                $stopDiscount = $isNonStop ? 1.0 : 0.90;
                $fareClassMultiplier = $fareBasis === 'FLEX' ? 1.35 : 1.0;

                $netAmount = number_format(
                    (2200 + $priceSeedValue) * $priceMultiplier * $timeOfDayDiscount * $stopDiscount * $fareClassMultiplier,
                    2, '.', ''
                );

                $departureAt = $request->departureDate.'T'.sprintf('%02d:%02d:00', $departureHour, $departureMinute);
                $arrivalTimestamp = strtotime($departureAt) + $durationMinutes * 60;
                $arrivalAt = date('Y-m-d\TH:i:s', $arrivalTimestamp);

                $isRefundable = $fareBasis === 'FLEX' || $this->seededInt($flightSeed.'|refund', 0, 1) === 1;
                $seatsAvailable = $this->seededInt($flightSeed.'|seats', 1, 9);
                $baggageKg = $fareBasis === 'FLEX' ? 25 : 15;
                $mealIncluded = $fareBasis === 'FLEX';

                $externalId = strtoupper(Str::random(10));

                $segments = [[
                    'sequence' => 1,
                    'origin' => $request->origin,
                    'destination' => $request->destination,
                    'departure_at' => $departureAt,
                    'arrival_at' => $arrivalAt,
                    'carrier_or_provider' => $carrierName,
                    'fare_basis' => $fareBasis,
                    'flight_number' => $flightNumber,
                    'aircraft_type' => $aircraft,
                    'stops' => $isNonStop ? 0 : 1,
                    'layover_city' => $layoverCity,
                    'layover_minutes' => $layoverMinutes,
                    'duration_minutes' => $durationMinutes,
                ]];

                $results[] = new SearchResultItem(
                    supplierCode: $this->code(),
                    externalId: $externalId,
                    netAmount: $netAmount,
                    currency: 'INR',
                    carrierOrProvider: $carrierName,
                    fareBasis: $fareBasis,
                    segments: $segments,
                    raw: ['mock' => true, 'external_id' => $externalId],
                    flightNumber: $flightNumber,
                    durationMinutes: $durationMinutes,
                    stops: $isNonStop ? 0 : 1,
                    layoverCity: $layoverCity,
                    baggageAllowanceKg: $baggageKg,
                    isRefundable: $isRefundable,
                    seatsAvailable: $seatsAvailable,
                    aircraftType: $aircraft,
                    mealIncluded: $mealIncluded,
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
        $netAmount = number_format(2500 + ($seed % 6500), 2, '.', '');

        return new FareRuleResult(
            isStillAvailable: true,
            confirmedNetAmount: $netAmount,
            isRefundable: $seed % 3 !== 0,
            rules: ['cancellation_fee' => '₹500 up to 24h before departure, thereafter non-refundable.'],
            taxBreakup: self::mockTaxes((float) $netAmount),
        );
    }

    public function holdBooking(string $externalId, array $passengers, array $credentials): BookingHoldResult
    {
        if ($passengers === []) {
            throw new SupplierException($this->code(), 'At least one passenger is required to hold a booking.');
        }

        $seed = crc32($externalId);
        $netAmount = number_format(2500 + ($seed % 6500), 2, '.', '');

        return new BookingHoldResult(
            supplierBookingRef: 'MOCK-'.strtoupper(Str::random(8)),
            holdExpiresAt: new \DateTimeImmutable('+30 minutes'),
            netAmount: $netAmount,
        );
    }

    public function confirmBooking(string $supplierBookingRef, array $credentials): BookingConfirmResult
    {
        if (! str_starts_with($supplierBookingRef, 'MOCK-')) {
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
        return new SupplierBalance(amount: '500000.00', currency: 'INR');
    }

    /**
     * Deterministic pseudo-random integer in [$min, $max] derived from an
     * arbitrary string key — lets every attribute of a fake flight (time,
     * price, stops, seats...) vary independently while still being 100%
     * reproducible for the same route + date + carrier + flight index.
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
        $carrier = self::CARRIERS[0];
        $results = [];

        foreach ([0, 1] as $index) {
            $flightNumber = $carrier[1].'-'.(100 + $index);
            $departureAt = $request->departureDate.'T'.sprintf('%02d:00:00', 8 + $index * 6);
            $durationMinutes = 120;
            $arrivalAt = date('Y-m-d\TH:i:s', strtotime($departureAt) + $durationMinutes * 60);
            $externalId = strtoupper(Str::random(10));
            $fareBasis = $index === 0 ? 'SAVER' : 'FLEX';

            $results[] = new SearchResultItem(
                supplierCode: $this->code(),
                externalId: $externalId,
                netAmount: number_format(3200 + $index * 800, 2, '.', ''),
                currency: 'INR',
                carrierOrProvider: $carrier[0],
                fareBasis: $fareBasis,
                segments: [[
                    'sequence' => 1,
                    'origin' => $request->origin,
                    'destination' => $request->destination,
                    'departure_at' => $departureAt,
                    'arrival_at' => $arrivalAt,
                    'carrier_or_provider' => $carrier[0],
                    'fare_basis' => $fareBasis,
                    'flight_number' => $flightNumber,
                    'aircraft_type' => $carrier[3][0],
                    'stops' => 0,
                    'layover_city' => null,
                    'layover_minutes' => 0,
                    'duration_minutes' => $durationMinutes,
                ]],
                raw: ['mock' => true, 'external_id' => $externalId],
                flightNumber: $flightNumber,
                durationMinutes: $durationMinutes,
                stops: 0,
                layoverCity: null,
                baggageAllowanceKg: $fareBasis === 'FLEX' ? 25 : 15,
                isRefundable: $fareBasis === 'FLEX',
                seatsAvailable: 9,
                aircraftType: $carrier[3][0],
                mealIncluded: $fareBasis === 'FLEX',
            );
        }

        return $results;
    }

    /**
     * A believable airline tax split of a mock fare: base ~82%, airline GST
     * (K3) 5% of base, fuel surcharge (YQ), and the rest airport/other taxes.
     *
     * @return array<string, string>
     */
    public static function mockTaxes(float $net): array
    {
        $base = round($net * 0.82, 2);
        $k3 = round($base * 0.05, 2);
        $yq = round($net * 0.06, 2);
        $other = round($net - $base - $k3 - $yq, 2);

        return [
            'K3' => number_format($k3, 2, '.', ''),
            'YQ' => number_format($yq, 2, '.', ''),
            'OT' => number_format($other, 2, '.', ''),
        ];
    }
}
