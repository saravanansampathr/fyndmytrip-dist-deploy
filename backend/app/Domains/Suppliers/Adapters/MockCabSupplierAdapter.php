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
 * A deterministic fake cab supplier — Phase 3's third real-booking-flow
 * vertical, after Bus and Hotel. Same pattern as MockBusSupplierAdapter/
 * MockHotelSupplierAdapter: every other layer (orchestrator, booking flow,
 * wallet debit) talks to this through the exact same
 * SupplierAdapterInterface a real cab-supplier API would implement, so
 * wiring one in later needs no changes outside app/Domains/Suppliers/Adapters.
 *
 * Every provider name, car type, price etc. below is entirely fictional and
 * derived deterministically from the route + date + provider, so the same
 * search always returns the same options and fares — useful for demos and
 * tests without a database of fake inventory.
 *
 * A cab trip on this platform is always one-way, point-to-point, single
 * pickup/drop — no round-trip concept (a return trip is just a separate
 * search the other way, same reasoning Bus already established) and no
 * pickup-address entry in this first cut, just origin/destination city
 * names, same simplification Bus made for boarding/dropping points.
 */
class MockCabSupplierAdapter implements SupplierAdapterInterface
{
    /**
     * Fictional cab providers. Each entry: [name, 2-letter code, car type,
     * included km, per-extra-km fare, base fare multiplier].
     */
    private const PROVIDERS = [
        ['Sundram Cabs', 'SC', 'Hatchback', 80, '12.00', 0.85],
        ['Highway Rides', 'HR', 'Sedan', 100, '14.00', 1.00],
        ['Coastal Cab Co.', 'CC', 'SUV', 120, '18.00', 1.35],
        ['Volante Fleet Services', 'VF', 'Premium Sedan', 100, '20.00', 1.55],
        ['MockCab National', 'MC', 'Sedan', 100, '14.00', 1.00],
        ['Speedline Rentals', 'SL', 'Hatchback', 80, '11.00', 0.75],
    ];

    public function code(): string
    {
        return 'MOCKCAB';
    }

    public function searchAvailability(SearchRequest $request, array $credentials): array
    {
        $results = [];
        $routeSeed = $request->origin.'|'.$request->destination.'|'.$request->departureDate;

        // Point-to-point cab trips on this platform run roughly 1-6 hours,
        // derived from the route itself so the same O&D always feels the
        // same length across repeat searches.
        $durationMinutes = 60 + $this->seededInt($routeSeed.'|duration', 0, 300);
        $distanceKm = 30 + $this->seededInt($routeSeed.'|distance', 0, 270);

        foreach (self::PROVIDERS as [$name, $code, $carType, $includedKm, $extraKmFare, $priceMultiplier]) {
            $providerSeed = $routeSeed.'|'.$code;

            // Not every provider runs every route.
            if ($this->seededInt($providerSeed.'|offers', 0, 99) < 12) {
                continue;
            }

            $pickupHour = $this->seededInt($providerSeed.'|hour', 5, 22);
            $pickupMinute = $this->seededInt($providerSeed.'|min', 0, 3) * 15;
            $priceSeedValue = $this->seededInt($providerSeed.'|price', 0, 1200);

            $netAmount = number_format((600 + $priceSeedValue + $distanceKm * 8) * $priceMultiplier, 2, '.', '');
            $carsAvailable = $this->seededInt($providerSeed.'|cars', 1, 8);
            $isRefundable = $this->seededInt($providerSeed.'|refund', 0, 1) === 1;

            $departureAt = $request->departureDate.'T'.sprintf('%02d:%02d:00', $pickupHour, $pickupMinute);
            $arrivalAt = date('Y-m-d\TH:i:s', strtotime($departureAt) + $durationMinutes * 60);

            $externalId = strtoupper(Str::random(10));

            $segments = [[
                'sequence' => 1,
                'origin' => $request->origin,
                'destination' => $request->destination,
                'departure_at' => $departureAt,
                'arrival_at' => $arrivalAt,
                'carrier_or_provider' => $name,
                'fare_basis' => $carType,
                'car_type' => $carType,
                'included_km' => $includedKm,
                'extra_km_fare' => $extraKmFare,
                'duration_minutes' => $durationMinutes,
            ]];

            $results[] = new SearchResultItem(
                supplierCode: $this->code(),
                externalId: $externalId,
                netAmount: $netAmount,
                currency: 'INR',
                carrierOrProvider: $name,
                fareBasis: $carType,
                segments: $segments,
                raw: ['mock' => true, 'external_id' => $externalId, 'distance_km' => $distanceKm],
                durationMinutes: $durationMinutes,
                stops: 0,
                isRefundable: $isRefundable,
                seatsAvailable: $carsAvailable,
                carType: $carType,
                includedKm: $includedKm,
                extraKmFare: $extraKmFare,
            );
        }

        // Guarantee at least a couple of options even on an unlucky seed.
        if (count($results) < 2) {
            return $this->guaranteedFallback($request, $durationMinutes);
        }

        return $results;
    }

    public function priceCheck(string $externalId, array $credentials): FareRuleResult
    {
        $seed = crc32($externalId);
        $netAmount = number_format(600 + ($seed % 1200), 2, '.', '');

        return new FareRuleResult(
            isStillAvailable: true,
            confirmedNetAmount: $netAmount,
            isRefundable: $seed % 3 !== 0,
            rules: ['cancellation_fee' => 'Free cancellation up to 1 hour before pickup, thereafter one-way fare is charged.'],
        );
    }

    public function holdBooking(string $externalId, array $passengers, array $credentials): BookingHoldResult
    {
        if ($passengers === []) {
            throw new SupplierException($this->code(), 'At least one traveller is required to hold a booking.');
        }

        $seed = crc32($externalId);
        $netAmount = number_format(600 + ($seed % 1200), 2, '.', '');

        return new BookingHoldResult(
            supplierBookingRef: 'MOCKCAB-'.strtoupper(Str::random(8)),
            holdExpiresAt: new \DateTimeImmutable('+30 minutes'),
            netAmount: $netAmount,
        );
    }

    public function confirmBooking(string $supplierBookingRef, array $credentials): BookingConfirmResult
    {
        if (! str_starts_with($supplierBookingRef, 'MOCKCAB-')) {
            throw new SupplierException($this->code(), "Unknown supplier booking reference: {$supplierBookingRef}");
        }

        return new BookingConfirmResult(
            supplierBookingRef: $supplierBookingRef,
            isTicketed: true,
            ticketNumbers: ['TRIP-'.strtoupper(Str::random(10))],
        );
    }

    public function retrieveBooking(string $supplierBookingRef, array $credentials): array
    {
        return [
            'supplier_booking_ref' => $supplierBookingRef,
            'status' => 'confirmed',
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
        return new SupplierBalance(amount: '150000.00', currency: 'INR');
    }

    /**
     * Deterministic pseudo-random integer in [$min, $max] derived from an
     * arbitrary string key — same technique as MockBusSupplierAdapter.
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
    private function guaranteedFallback(SearchRequest $request, int $durationMinutes): array
    {
        $results = [];

        foreach ([0, 1] as $index) {
            [$name, , $carType, $includedKm, $extraKmFare] = self::PROVIDERS[$index];
            $departureAt = $request->departureDate.'T'.sprintf('%02d:00:00', 8 + $index * 6);
            $arrivalAt = date('Y-m-d\TH:i:s', strtotime($departureAt) + $durationMinutes * 60);
            $externalId = strtoupper(Str::random(10));

            $results[] = new SearchResultItem(
                supplierCode: $this->code(),
                externalId: $externalId,
                netAmount: number_format(900 + $index * 400, 2, '.', ''),
                currency: 'INR',
                carrierOrProvider: $name,
                fareBasis: $carType,
                segments: [[
                    'sequence' => 1,
                    'origin' => $request->origin,
                    'destination' => $request->destination,
                    'departure_at' => $departureAt,
                    'arrival_at' => $arrivalAt,
                    'carrier_or_provider' => $name,
                    'fare_basis' => $carType,
                    'car_type' => $carType,
                    'included_km' => $includedKm,
                    'extra_km_fare' => $extraKmFare,
                    'duration_minutes' => $durationMinutes,
                ]],
                raw: ['mock' => true, 'external_id' => $externalId],
                durationMinutes: $durationMinutes,
                stops: 0,
                isRefundable: true,
                seatsAvailable: 5,
                carType: $carType,
                includedKm: $includedKm,
                extraKmFare: $extraKmFare,
            );
        }

        return $results;
    }
}
