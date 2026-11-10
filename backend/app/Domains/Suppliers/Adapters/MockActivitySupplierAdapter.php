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
 * A deterministic fake activities supplier — Phase 3's sixth and final
 * item, and the first module built with no existing inventory table or
 * admin screen to start from (see the roadmap's "Doesn't exist at all"
 * note). Same pattern as MockCabSupplierAdapter/MockCruiseSupplierAdapter:
 * every other layer (orchestrator, booking flow, wallet debit) talks to
 * this through the exact same SupplierAdapterInterface a real
 * activities/experiences API (e.g. a city-tours aggregator) would
 * implement, so wiring one in later needs no changes outside
 * app/Domains/Suppliers/Adapters.
 *
 * Every operator name, category, price etc. below is entirely fictional
 * and derived deterministically from the city + date + operator, so the
 * same search always returns the same options and fares — useful for
 * demos and tests without a database of fake inventory.
 *
 * An activity on this platform is a single date at a single city — no
 * multi-day itinerary or time-slot picker in this first cut (one offer is
 * one fixed start time for that date), same simplification every other
 * Phase 3 vertical made for itself. SearchRequest's generic origin/
 * departureDate/passengerCount travel here as city/activity date/
 * travellers; destination is unused (an activity has no "arrival"
 * location distinct from where it's held).
 */
class MockActivitySupplierAdapter implements SupplierAdapterInterface
{
    /**
     * Fictional activity operators. Each entry: [name, 2-letter code,
     * category, base price multiplier].
     */
    private const OPERATORS = [
        ['Sundram City Experiences', 'SC', 'Sightseeing', 0.85],
        ['Highway Adventure Co.', 'HA', 'Adventure', 1.25],
        ['Coastal Water Sports', 'CW', 'Water sports', 1.10],
        ['Volante Heritage Walks', 'VH', 'Cultural', 0.95],
        ['MockTours International', 'MT', 'Sightseeing', 1.00],
        ['Speedline Wildlife Safaris', 'SW', 'Wildlife', 1.60],
    ];

    public function code(): string
    {
        return 'MOCKACTIVITY';
    }

    public function searchAvailability(SearchRequest $request, array $credentials): array
    {
        $results = [];
        $city = $request->origin;
        $activityDate = $request->departureDate;
        $citySeed = $city.'|'.$activityDate;

        foreach (self::OPERATORS as [$name, $code, $category, $priceMultiplier]) {
            $operatorSeed = $citySeed.'|'.$code;

            // Not every operator runs in every city.
            if ($this->seededInt($operatorSeed.'|offers', 0, 99) < 15) {
                continue;
            }

            // Activities on this platform run roughly 2-8 hours, derived
            // from the city + operator so the same search always feels the
            // same length across repeat searches.
            $durationMinutes = 120 + $this->seededInt($operatorSeed.'|duration', 0, 360);
            $startHour = $this->seededInt($operatorSeed.'|hour', 6, 15);

            $priceSeedValue = $this->seededInt($operatorSeed.'|price', 0, 2500);
            $netAmount = number_format((800 + $priceSeedValue) * $priceMultiplier, 2, '.', '');
            $slotsAvailable = $this->seededInt($operatorSeed.'|slots', 4, 30);
            $isRefundable = $this->seededInt($operatorSeed.'|refund', 0, 1) === 1;
            $meetingPoint = $city.' — '.$name.' meeting point';

            $departureAt = $activityDate.'T'.sprintf('%02d:00:00', $startHour);
            $arrivalAt = date('Y-m-d\TH:i:s', strtotime($departureAt) + $durationMinutes * 60);
            $externalId = strtoupper(Str::random(10));

            $segments = [[
                'sequence' => 1,
                'origin' => $city,
                'destination' => null,
                'departure_at' => $departureAt,
                'arrival_at' => $arrivalAt,
                'carrier_or_provider' => $name,
                'fare_basis' => $category,
                'duration_minutes' => $durationMinutes,
                'boarding_point' => $meetingPoint,
            ]];

            $results[] = new SearchResultItem(
                supplierCode: $this->code(),
                externalId: $externalId,
                netAmount: $netAmount,
                currency: 'INR',
                carrierOrProvider: $name,
                fareBasis: $category,
                segments: $segments,
                raw: ['mock' => true, 'external_id' => $externalId],
                durationMinutes: $durationMinutes,
                boardingPoint: $meetingPoint,
                isRefundable: $isRefundable,
                seatsAvailable: $slotsAvailable,
            );
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
        $netAmount = number_format(800 + ($seed % 2500), 2, '.', '');

        return new FareRuleResult(
            isStillAvailable: true,
            confirmedNetAmount: $netAmount,
            isRefundable: $seed % 3 !== 0,
            rules: ['cancellation_fee' => 'Free cancellation up to 24 hours before the activity, thereafter the full amount is forfeited.'],
        );
    }

    public function holdBooking(string $externalId, array $passengers, array $credentials): BookingHoldResult
    {
        if ($passengers === []) {
            throw new SupplierException($this->code(), 'At least one traveller is required to hold a booking.');
        }

        $seed = crc32($externalId);
        $netAmount = number_format(800 + ($seed % 2500), 2, '.', '');

        return new BookingHoldResult(
            supplierBookingRef: 'MOCKACTIVITY-'.strtoupper(Str::random(8)),
            holdExpiresAt: new \DateTimeImmutable('+30 minutes'),
            netAmount: $netAmount,
        );
    }

    public function confirmBooking(string $supplierBookingRef, array $credentials): BookingConfirmResult
    {
        if (! str_starts_with($supplierBookingRef, 'MOCKACTIVITY-')) {
            throw new SupplierException($this->code(), "Unknown supplier booking reference: {$supplierBookingRef}");
        }

        return new BookingConfirmResult(
            supplierBookingRef: $supplierBookingRef,
            isTicketed: true,
            ticketNumbers: ['ACT-'.strtoupper(Str::random(10))],
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
    private function guaranteedFallback(SearchRequest $request): array
    {
        $results = [];
        $city = $request->origin;
        $activityDate = $request->departureDate;

        foreach ([0, 1] as $index) {
            [$name, , $category] = self::OPERATORS[$index];
            $durationMinutes = 180;
            $departureAt = $activityDate.'T'.sprintf('%02d:00:00', 8 + $index * 5);
            $arrivalAt = date('Y-m-d\TH:i:s', strtotime($departureAt) + $durationMinutes * 60);
            $externalId = strtoupper(Str::random(10));
            $meetingPoint = $city.' — '.$name.' meeting point';

            $results[] = new SearchResultItem(
                supplierCode: $this->code(),
                externalId: $externalId,
                netAmount: number_format(1200 + $index * 500, 2, '.', ''),
                currency: 'INR',
                carrierOrProvider: $name,
                fareBasis: $category,
                segments: [[
                    'sequence' => 1,
                    'origin' => $city,
                    'destination' => null,
                    'departure_at' => $departureAt,
                    'arrival_at' => $arrivalAt,
                    'carrier_or_provider' => $name,
                    'fare_basis' => $category,
                    'duration_minutes' => $durationMinutes,
                    'boarding_point' => $meetingPoint,
                ]],
                raw: ['mock' => true, 'external_id' => $externalId],
                durationMinutes: $durationMinutes,
                boardingPoint: $meetingPoint,
                isRefundable: true,
                seatsAvailable: 10,
            );
        }

        return $results;
    }
}
