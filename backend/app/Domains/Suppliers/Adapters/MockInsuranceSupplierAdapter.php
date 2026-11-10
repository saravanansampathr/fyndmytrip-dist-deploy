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
 * A deterministic fake travel-insurance supplier — Phase 3's fourth
 * real-booking-flow vertical, after Bus, Hotel and Cab. Same pattern as
 * MockCabSupplierAdapter/MockHotelSupplierAdapter: every other layer
 * (orchestrator, booking flow, wallet debit) talks to this through the
 * exact same SupplierAdapterInterface a real insurance-provider API would
 * implement, so wiring one in later needs no changes outside
 * app/Domains/Suppliers/Adapters.
 *
 * Every provider name, plan tier, coverage amount etc. below is entirely
 * fictional and derived deterministically from the destination + trip
 * dates + provider + plan, so the same search always returns the same
 * plans and premiums — useful for demos and tests without a database of
 * fake inventory.
 *
 * SearchRequest's generic origin/destination/departureDate/returnDate
 * travel here as: origin = trip destination (an insurance search has no
 * "origin" the way a transit search does), departureDate = trip start
 * date, returnDate = trip end date, passengerCount = travellers. Unlike
 * Bus/Cab (single-leg) or Hotel (a stay), a policy has no schedule of its
 * own — the "segment" below just carries the customer's own trip window
 * back so the checkout flow has consistent dates to show, same
 * departure_at/arrival_at convention Hotel already established for a date
 * range instead of a transit leg.
 */
class MockInsuranceSupplierAdapter implements SupplierAdapterInterface
{
    /**
     * Fictional insurance providers. Each entry: [name, 2-letter code, base
     * per-traveller-per-day premium, price multiplier].
     */
    private const PROVIDERS = [
        ['Sundram Assurance', 'SA', 45, 0.90],
        ['Highway Cover', 'HC', 35, 0.65],
        ['Coastal Shield Insurance', 'CS', 55, 1.20],
        ['Volante Travel Protect', 'VP', 60, 1.35],
        ['MockInsure Global', 'MI', 50, 1.00],
        ['Speedline SafeTrip', 'SL', 30, 0.55],
    ];

    /**
     * Plan tiers every provider offers, each with its own coverage amount
     * (in INR) and a multiplier over the provider's base premium — not
     * every provider offers every tier, same "not every X offers every Y"
     * skip logic MockHotelSupplierAdapter uses for room types.
     */
    private const PLANS = [
        ['Basic', 500000, 1.00],
        ['Standard', 1500000, 1.45],
        ['Premium', 3000000, 2.10],
    ];

    public function code(): string
    {
        return 'MOCKINSURANCE';
    }

    public function searchAvailability(SearchRequest $request, array $credentials): array
    {
        $results = [];
        $destination = $request->origin;
        $tripStart = $request->departureDate;
        $tripEnd = $request->returnDate ?? $tripStart;
        $tripDays = max(1, (int) ((strtotime($tripEnd) - strtotime($tripStart)) / 86400) + 1);
        $travellers = max(1, $request->passengerCount);
        $stackSeed = $destination.'|'.$tripStart.'|'.$tripEnd;

        foreach (self::PROVIDERS as [$name, $code, $baseDailyPremium, $priceMultiplier]) {
            $providerSeed = $stackSeed.'|'.$code;

            // Not every provider covers every destination.
            if ($this->seededInt($providerSeed.'|offers', 0, 99) < 8) {
                continue;
            }

            foreach (self::PLANS as [$planName, $coverageAmount, $planMultiplier]) {
                $planSeed = $providerSeed.'|'.$planName;

                // Not every provider offers every plan tier.
                if ($this->seededInt($planSeed.'|offer', 0, 99) < 15) {
                    continue;
                }

                $netAmount = number_format($baseDailyPremium * $priceMultiplier * $planMultiplier * $tripDays * $travellers, 2, '.', '');
                $policiesAvailable = $this->seededInt($planSeed.'|policies', 5, 50);
                $isRefundable = $this->seededInt($planSeed.'|refund', 0, 1) === 1;

                $externalId = strtoupper(Str::random(10));

                $segments = [[
                    'sequence' => 1,
                    'origin' => $destination,
                    'destination' => $destination,
                    'departure_at' => $tripStart.'T00:00:00',
                    'arrival_at' => $tripEnd.'T23:59:59',
                    'carrier_or_provider' => $name,
                    'fare_basis' => $planName,
                    'coverage_amount' => (string) $coverageAmount,
                    'policy_term_days' => $tripDays,
                ]];

                $results[] = new SearchResultItem(
                    supplierCode: $this->code(),
                    externalId: $externalId,
                    netAmount: $netAmount,
                    currency: 'INR',
                    carrierOrProvider: $name,
                    fareBasis: $planName,
                    segments: $segments,
                    raw: ['mock' => true, 'external_id' => $externalId],
                    isRefundable: $isRefundable,
                    seatsAvailable: $policiesAvailable,
                    coverageAmount: (string) $coverageAmount,
                    policyTermDays: $tripDays,
                );
            }
        }

        // Guarantee at least a couple of options even on an unlucky seed.
        if (count($results) < 2) {
            return $this->guaranteedFallback($request, $destination, $tripStart, $tripEnd, $tripDays, $travellers);
        }

        return $results;
    }

    public function priceCheck(string $externalId, array $credentials): FareRuleResult
    {
        $seed = crc32($externalId);
        $netAmount = number_format(500 + ($seed % 4500), 2, '.', '');

        return new FareRuleResult(
            isStillAvailable: true,
            confirmedNetAmount: $netAmount,
            isRefundable: $seed % 3 !== 0,
            rules: ['free_look_period' => 'Full refund if cancelled within 15 days of purchase and before the trip start date, thereafter non-refundable.'],
        );
    }

    public function holdBooking(string $externalId, array $passengers, array $credentials): BookingHoldResult
    {
        if ($passengers === []) {
            throw new SupplierException($this->code(), 'At least one traveller is required to hold a policy.');
        }

        $seed = crc32($externalId);
        $netAmount = number_format(500 + ($seed % 4500), 2, '.', '');

        return new BookingHoldResult(
            supplierBookingRef: 'MOCKINSURANCE-'.strtoupper(Str::random(8)),
            holdExpiresAt: new \DateTimeImmutable('+30 minutes'),
            netAmount: $netAmount,
        );
    }

    public function confirmBooking(string $supplierBookingRef, array $credentials): BookingConfirmResult
    {
        if (! str_starts_with($supplierBookingRef, 'MOCKINSURANCE-')) {
            throw new SupplierException($this->code(), "Unknown supplier booking reference: {$supplierBookingRef}");
        }

        return new BookingConfirmResult(
            supplierBookingRef: $supplierBookingRef,
            isTicketed: true,
            ticketNumbers: ['POL-'.strtoupper(Str::random(10))],
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
        return new SupplierBalance(amount: '250000.00', currency: 'INR');
    }

    /**
     * Deterministic pseudo-random integer in [$min, $max] derived from an
     * arbitrary string key — same technique as MockHotelSupplierAdapter.
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
    private function guaranteedFallback(SearchRequest $request, string $destination, string $tripStart, string $tripEnd, int $tripDays, int $travellers): array
    {
        $results = [];

        foreach ([0, 1] as $index) {
            [$name, , $baseDailyPremium] = self::PROVIDERS[$index];
            [$planName, $coverageAmount] = self::PLANS[$index];
            $externalId = strtoupper(Str::random(10));
            $netAmount = number_format($baseDailyPremium * $tripDays * $travellers, 2, '.', '');

            $results[] = new SearchResultItem(
                supplierCode: $this->code(),
                externalId: $externalId,
                netAmount: $netAmount,
                currency: 'INR',
                carrierOrProvider: $name,
                fareBasis: $planName,
                segments: [[
                    'sequence' => 1,
                    'origin' => $destination,
                    'destination' => $destination,
                    'departure_at' => $tripStart.'T00:00:00',
                    'arrival_at' => $tripEnd.'T23:59:59',
                    'carrier_or_provider' => $name,
                    'fare_basis' => $planName,
                    'coverage_amount' => (string) $coverageAmount,
                    'policy_term_days' => $tripDays,
                ]],
                raw: ['mock' => true, 'external_id' => $externalId],
                isRefundable: true,
                seatsAvailable: 20,
                coverageAmount: (string) $coverageAmount,
                policyTermDays: $tripDays,
            );
        }

        return $results;
    }
}
