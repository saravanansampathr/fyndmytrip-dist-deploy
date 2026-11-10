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
 * A deterministic fake cruise supplier — Phase 3's fifth and final
 * real-booking-flow vertical, after Bus, Hotel, Cab and Insurance. Same
 * pattern as MockBusSupplierAdapter/MockCabSupplierAdapter: every other
 * layer (orchestrator, booking flow, wallet debit) talks to this through
 * the exact same SupplierAdapterInterface a real cruise-line API would
 * implement, so wiring one in later needs no changes outside
 * app/Domains/Suppliers/Adapters.
 *
 * Every cruise line name, ship name, cabin type, price etc. below is
 * entirely fictional and derived deterministically from the ports + sail
 * date + cruise line, so the same search always returns the same sailings
 * and fares — useful for demos and tests without a database of fake
 * inventory.
 *
 * A cruise sailing on this platform is a single point-to-point voyage — no
 * multi-port itinerary breakdown or round-trip concept in this first cut
 * (a return leg is just a separate search the other way, same reasoning
 * Bus already established). SearchRequest's generic origin/destination/
 * departureDate/passengerCount travel here in their literal transit sense
 * (origin = departure port, destination = arrival port, departureDate =
 * sail date, passengerCount = travellers), same as Bus/Cab.
 */
class MockCruiseSupplierAdapter implements SupplierAdapterInterface
{
    /**
     * Fictional cruise lines. Each entry: [cruise line, 2-letter code, ship
     * name, base per-night fare multiplier].
     */
    private const CRUISE_LINES = [
        ['Sundram Seas Cruises', 'SS', 'Sundram Horizon', 0.90],
        ['Highway Ocean Line', 'HO', 'Highway Mariner', 0.65],
        ['Coastal Voyager Cruises', 'CV', 'Coastal Serenity', 1.20],
        ['Volante Cruise Co.', 'VC', 'Volante Odyssey', 1.45],
        ['MockCruise International', 'MC', 'MockCruise Majesty', 1.00],
        ['Speedline Cruise Lines', 'SL', 'Speedline Wanderer', 0.75],
    ];

    /**
     * Cabin types available on every sailing, each with its own multiplier
     * over the cruise line's base per-night fare — not every cruise line
     * offers every cabin type, same "not every X offers every Y" skip logic
     * MockHotelSupplierAdapter uses for room types.
     */
    private const CABIN_TYPES = [
        ['Interior', 1.00],
        ['Ocean View', 1.40],
        ['Balcony', 1.90],
        ['Suite', 2.75],
    ];

    public function code(): string
    {
        return 'MOCKCRUISE';
    }

    public function searchAvailability(SearchRequest $request, array $credentials): array
    {
        $results = [];
        $departurePort = $request->origin;
        $arrivalPort = $request->destination ?? $request->origin;
        $sailDate = $request->departureDate;
        $routeSeed = $departurePort.'|'.$arrivalPort.'|'.$sailDate;

        // A sailing on this platform runs roughly 3-14 nights, derived from
        // the route itself so the same ports always feel the same length
        // across repeat searches.
        $nights = 3 + $this->seededInt($routeSeed.'|nights', 0, 11);

        foreach (self::CRUISE_LINES as [$lineName, $code, $shipName, $baseMultiplier]) {
            $lineSeed = $routeSeed.'|'.$code;

            // Not every cruise line sails every route.
            if ($this->seededInt($lineSeed.'|offers', 0, 99) < 12) {
                continue;
            }

            $baseNightlyRate = 3500 + $this->seededInt($lineSeed.'|rate', 0, 8500);

            foreach (self::CABIN_TYPES as [$cabinType, $cabinMultiplier]) {
                $cabinSeed = $lineSeed.'|'.$cabinType;

                // Not every cruise line offers every cabin type.
                if ($this->seededInt($cabinSeed.'|offer', 0, 99) < 15) {
                    continue;
                }

                $netAmount = number_format($baseNightlyRate * $baseMultiplier * $cabinMultiplier * $nights, 2, '.', '');
                $cabinsAvailable = $this->seededInt($cabinSeed.'|cabins', 1, 20);
                $isRefundable = $this->seededInt($cabinSeed.'|refund', 0, 1) === 1;

                $departureAt = $sailDate.'T16:00:00';
                $arrivalAt = date('Y-m-d\TH:i:s', strtotime($departureAt) + $nights * 86400);
                $externalId = strtoupper(Str::random(10));

                $segments = [[
                    'sequence' => 1,
                    'origin' => $departurePort,
                    'destination' => $arrivalPort,
                    'departure_at' => $departureAt,
                    'arrival_at' => $arrivalAt,
                    'carrier_or_provider' => $lineName,
                    'fare_basis' => $cabinType,
                    'ship_name' => $shipName,
                    'nights' => $nights,
                ]];

                $results[] = new SearchResultItem(
                    supplierCode: $this->code(),
                    externalId: $externalId,
                    netAmount: $netAmount,
                    currency: 'INR',
                    carrierOrProvider: $lineName,
                    fareBasis: $cabinType,
                    segments: $segments,
                    raw: ['mock' => true, 'external_id' => $externalId],
                    isRefundable: $isRefundable,
                    seatsAvailable: $cabinsAvailable,
                    nights: $nights,
                    shipName: $shipName,
                );
            }
        }

        // Guarantee at least a couple of options even on an unlucky seed.
        if (count($results) < 2) {
            return $this->guaranteedFallback($request, $departurePort, $arrivalPort, $sailDate, $nights);
        }

        return $results;
    }

    public function priceCheck(string $externalId, array $credentials): FareRuleResult
    {
        $seed = crc32($externalId);
        $netAmount = number_format(10000 + ($seed % 90000), 2, '.', '');

        return new FareRuleResult(
            isStillAvailable: true,
            confirmedNetAmount: $netAmount,
            isRefundable: $seed % 3 !== 0,
            rules: ['cancellation_fee' => 'Free cancellation up to 15 days before departure on refundable fares, thereafter a per-night charge applies.'],
        );
    }

    public function holdBooking(string $externalId, array $passengers, array $credentials): BookingHoldResult
    {
        if ($passengers === []) {
            throw new SupplierException($this->code(), 'At least one traveller is required to hold a booking.');
        }

        $seed = crc32($externalId);
        $netAmount = number_format(10000 + ($seed % 90000), 2, '.', '');

        return new BookingHoldResult(
            supplierBookingRef: 'MOCKCRUISE-'.strtoupper(Str::random(8)),
            holdExpiresAt: new \DateTimeImmutable('+30 minutes'),
            netAmount: $netAmount,
        );
    }

    public function confirmBooking(string $supplierBookingRef, array $credentials): BookingConfirmResult
    {
        if (! str_starts_with($supplierBookingRef, 'MOCKCRUISE-')) {
            throw new SupplierException($this->code(), "Unknown supplier booking reference: {$supplierBookingRef}");
        }

        return new BookingConfirmResult(
            supplierBookingRef: $supplierBookingRef,
            isTicketed: true,
            ticketNumbers: ['VOY-'.strtoupper(Str::random(10))],
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
        return new SupplierBalance(amount: '800000.00', currency: 'INR');
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
    private function guaranteedFallback(SearchRequest $request, string $departurePort, string $arrivalPort, string $sailDate, int $nights): array
    {
        $results = [];

        foreach ([0, 1] as $index) {
            [$lineName, , $shipName] = self::CRUISE_LINES[$index];
            [$cabinType] = self::CABIN_TYPES[$index];
            $departureAt = $sailDate.'T16:00:00';
            $arrivalAt = date('Y-m-d\TH:i:s', strtotime($departureAt) + $nights * 86400);
            $externalId = strtoupper(Str::random(10));

            $results[] = new SearchResultItem(
                supplierCode: $this->code(),
                externalId: $externalId,
                netAmount: number_format((28000 + $index * 12000) * $nights / 7, 2, '.', ''),
                currency: 'INR',
                carrierOrProvider: $lineName,
                fareBasis: $cabinType,
                segments: [[
                    'sequence' => 1,
                    'origin' => $departurePort,
                    'destination' => $arrivalPort,
                    'departure_at' => $departureAt,
                    'arrival_at' => $arrivalAt,
                    'carrier_or_provider' => $lineName,
                    'fare_basis' => $cabinType,
                    'ship_name' => $shipName,
                    'nights' => $nights,
                ]],
                raw: ['mock' => true, 'external_id' => $externalId],
                isRefundable: true,
                seatsAvailable: 10,
                nights: $nights,
                shipName: $shipName,
            );
        }

        return $results;
    }
}
