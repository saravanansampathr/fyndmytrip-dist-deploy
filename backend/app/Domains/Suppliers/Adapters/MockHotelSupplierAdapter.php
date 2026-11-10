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
 * A deterministic fake hotel supplier — Phase 3's second real-booking-flow
 * vertical, after Bus. Same pattern as MockBusSupplierAdapter/
 * MockFlightSupplierAdapter: every other layer (orchestrator, booking flow,
 * wallet debit) talks to this through the exact same
 * SupplierAdapterInterface a real hotel-supplier API (Akbar/TripJack/a GDS)
 * would implement, so wiring one in later needs no changes outside
 * app/Domains/Suppliers/Adapters.
 *
 * Every property name, room type, price etc. below is entirely fictional
 * and derived deterministically from the city + dates + property, so the
 * same search always returns the same properties and rates — useful for
 * demos and tests without a database of fake inventory.
 *
 * SearchRequest's generic origin/destination/departureDate/returnDate travel
 * here as: origin = city (a hotel search has no "destination" the way a
 * transit search does), departureDate = check-in date, returnDate =
 * check-out date, passengerCount = guests.
 */
class MockHotelSupplierAdapter implements SupplierAdapterInterface
{
    /**
     * Fictional hotel properties. Each entry: [name, 2-letter code, star
     * rating, base nightly-rate multiplier].
     */
    private const PROPERTIES = [
        ['Sundram Grand', 'SG', 5, 1.60],
        ['Highway Inn', 'HI', 2, 0.55],
        ['Coastal Retreat Resort', 'CR', 4, 1.25],
        ['Volante Business Suites', 'VB', 4, 1.10],
        ['MockHotel Palace', 'MH', 5, 1.80],
        ['Speedline Budget Stay', 'SB', 3, 0.75],
    ];

    /**
     * Room types available at every property, each with its own meal-plan
     * and a multiplier over the property's base nightly rate. Not every
     * property offers every room type — see the "not every property runs
     * every room type" skip logic in searchAvailability(), same convention
     * as MockBusSupplierAdapter's "not every operator runs every route".
     */
    private const ROOM_TYPES = [
        ['Standard Room', 'Room Only', 1.00],
        ['Deluxe Room', 'Breakfast Included', 1.35],
        ['Executive Suite', 'Breakfast & Dinner', 1.90],
    ];

    public function code(): string
    {
        return 'MOCKHOTEL';
    }

    public function searchAvailability(SearchRequest $request, array $credentials): array
    {
        $results = [];
        $city = $request->origin;
        $checkIn = $request->departureDate;
        $checkOut = $request->returnDate ?? $checkIn;
        $nights = max(1, (int) ((strtotime($checkOut) - strtotime($checkIn)) / 86400));
        $stackSeed = $city.'|'.$checkIn.'|'.$checkOut;

        foreach (self::PROPERTIES as [$name, $code, $starRating, $baseMultiplier]) {
            $propertySeed = $stackSeed.'|'.$code;

            // Not every property has availability for every stay.
            if ($this->seededInt($propertySeed.'|offers', 0, 99) < 10) {
                continue;
            }

            $baseNightlyRate = 1200 + $this->seededInt($propertySeed.'|rate', 0, 4800);

            foreach (self::ROOM_TYPES as [$roomType, $mealPlan, $roomMultiplier]) {
                $roomSeed = $propertySeed.'|'.$roomType;

                // Not every property offers every room type.
                if ($this->seededInt($roomSeed.'|offer', 0, 99) < 20) {
                    continue;
                }

                $nightlyRate = $baseNightlyRate * $baseMultiplier * $roomMultiplier;
                $netAmount = number_format($nightlyRate * $nights, 2, '.', '');
                $roomsAvailable = $this->seededInt($roomSeed.'|rooms', 1, 15);
                $isRefundable = $this->seededInt($roomSeed.'|refund', 0, 1) === 1;

                $externalId = strtoupper(Str::random(10));

                $segments = [[
                    'sequence' => 1,
                    'origin' => $city,
                    'destination' => $name,
                    'departure_at' => $checkIn.'T14:00:00',
                    'arrival_at' => $checkOut.'T11:00:00',
                    'carrier_or_provider' => $name,
                    'fare_basis' => $roomType,
                    'star_rating' => $starRating,
                    'meal_plan' => $mealPlan,
                    'nights' => $nights,
                ]];

                $results[] = new SearchResultItem(
                    supplierCode: $this->code(),
                    externalId: $externalId,
                    netAmount: $netAmount,
                    currency: 'INR',
                    carrierOrProvider: $name,
                    fareBasis: $roomType,
                    segments: $segments,
                    raw: ['mock' => true, 'external_id' => $externalId],
                    isRefundable: $isRefundable,
                    seatsAvailable: $roomsAvailable,
                    starRating: $starRating,
                    mealPlan: $mealPlan,
                    nights: $nights,
                );
            }
        }

        // Guarantee at least a couple of options even on an unlucky seed.
        if (count($results) < 2) {
            return $this->guaranteedFallback($request, $city, $checkIn, $checkOut, $nights);
        }

        return $results;
    }

    public function priceCheck(string $externalId, array $credentials): FareRuleResult
    {
        $seed = crc32($externalId);
        $netAmount = number_format(1200 + ($seed % 4800), 2, '.', '');

        return new FareRuleResult(
            isStillAvailable: true,
            confirmedNetAmount: $netAmount,
            isRefundable: $seed % 3 !== 0,
            rules: ['cancellation_fee' => 'Free cancellation up to 24 hours before check-in on refundable rates, thereafter one night\'s charge.'],
        );
    }

    public function holdBooking(string $externalId, array $passengers, array $credentials): BookingHoldResult
    {
        if ($passengers === []) {
            throw new SupplierException($this->code(), 'At least one guest is required to hold a booking.');
        }

        $seed = crc32($externalId);
        $netAmount = number_format(1200 + ($seed % 4800), 2, '.', '');

        return new BookingHoldResult(
            supplierBookingRef: 'MOCKHOTEL-'.strtoupper(Str::random(8)),
            holdExpiresAt: new \DateTimeImmutable('+30 minutes'),
            netAmount: $netAmount,
        );
    }

    public function confirmBooking(string $supplierBookingRef, array $credentials): BookingConfirmResult
    {
        if (! str_starts_with($supplierBookingRef, 'MOCKHOTEL-')) {
            throw new SupplierException($this->code(), "Unknown supplier booking reference: {$supplierBookingRef}");
        }

        return new BookingConfirmResult(
            supplierBookingRef: $supplierBookingRef,
            isTicketed: true,
            ticketNumbers: ['CONF-'.strtoupper(Str::random(10))],
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
        return new SupplierBalance(amount: '500000.00', currency: 'INR');
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
    private function guaranteedFallback(SearchRequest $request, string $city, string $checkIn, string $checkOut, int $nights): array
    {
        $property = self::PROPERTIES[0];
        [$name, , $starRating] = $property;
        $results = [];

        foreach ([0, 1] as $index) {
            [$roomType, $mealPlan] = self::ROOM_TYPES[$index];
            $externalId = strtoupper(Str::random(10));
            $netAmount = number_format((1800 + $index * 900) * $nights, 2, '.', '');

            $results[] = new SearchResultItem(
                supplierCode: $this->code(),
                externalId: $externalId,
                netAmount: $netAmount,
                currency: 'INR',
                carrierOrProvider: $name,
                fareBasis: $roomType,
                segments: [[
                    'sequence' => 1,
                    'origin' => $city,
                    'destination' => $name,
                    'departure_at' => $checkIn.'T14:00:00',
                    'arrival_at' => $checkOut.'T11:00:00',
                    'carrier_or_provider' => $name,
                    'fare_basis' => $roomType,
                    'star_rating' => $starRating,
                    'meal_plan' => $mealPlan,
                    'nights' => $nights,
                ]],
                raw: ['mock' => true, 'external_id' => $externalId],
                isRefundable: true,
                seatsAvailable: 10,
                starRating: $starRating,
                mealPlan: $mealPlan,
                nights: $nights,
            );
        }

        return $results;
    }
}
