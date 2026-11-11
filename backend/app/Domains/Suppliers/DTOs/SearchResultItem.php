<?php

namespace App\Domains\Suppliers\DTOs;

/**
 * One sellable option returned by a supplier for a SearchRequest — e.g. one
 * flight fare, one hotel room-rate. The Supplier Orchestrator merges,
 * deduplicates and ranks a list of these across every enabled supplier.
 *
 * The flight-specific fields (flightNumber, stops, baggage...) are optional
 * so this DTO still works for non-flight modules (hotel, cab, ...); a
 * flight adapter populates them so the frontend can build real filters
 * (airline, stops, price, departure time) and a seat map / ancillary
 * picker without re-deriving anything from the raw segments array.
 *
 * Phase 3's Bus vertical follows the same convention: busType/
 * boardingPoint/droppingPoint are bus-specific optional fields a
 * MockBusSupplierAdapter (or a real one later) populates, left null by
 * every flight adapter — same pattern as the flight-only fields above,
 * just for the next module instead of growing a module-specific DTO.
 *
 * Hotel (Phase 3's second vertical) follows the same convention again:
 * starRating/mealPlan/nights are hotel-specific optional fields a
 * MockHotelSupplierAdapter populates, left null everywhere else.
 * carrierOrProvider doubles as the hotel/property name and fareBasis as
 * the room type — the same reuse convention busType already established
 * for fareBasis, rather than adding yet another pair of fields.
 *
 * Cab (Phase 3's third vertical) again: carType/includedKm/extraKmFare are
 * cab-specific optional fields a MockCabSupplierAdapter populates, left
 * null everywhere else. carrierOrProvider doubles as the cab operator/
 * provider name and fareBasis as the car type, same reuse convention as
 * bus/hotel. durationMinutes/stops are reused for the estimated trip
 * duration (stops always 0 — a cab trip has no concept of stops).
 *
 * Insurance (Phase 3's fourth vertical) again: coverageAmount/
 * policyTermDays are insurance-specific optional fields a
 * MockInsuranceSupplierAdapter populates, left null everywhere else.
 * carrierOrProvider doubles as the insurer/provider name and fareBasis as
 * the plan name/tier, same reuse convention as bus/hotel/cab. Unlike those
 * three, an insurance offer has no transit leg or stay — its "segment" is
 * the trip window itself (departure_at/arrival_at hold the customer's own
 * trip start/end dates, not a supplier-fixed schedule).
 *
 * Cruise (Phase 3's fifth vertical) again: shipName is a cruise-specific
 * optional field a MockCruiseSupplierAdapter populates, left null
 * everywhere else. carrierOrProvider doubles as the cruise line and
 * fareBasis as the cabin type, same reuse convention as the other three;
 * nights (already added for Hotel) is reused as-is for the sailing length
 * rather than adding a duplicate field.
 *
 * Activities (Phase 3's sixth and final item, and the first module built
 * with no inventory table or admin screen to start from) needs no new
 * field at all: carrierOrProvider doubles as the activity operator/
 * provider name and fareBasis as the activity category (same reuse
 * convention as every module above), durationMinutes (already added for
 * Cab) is reused as-is for the activity's duration, and boardingPoint
 * (already added for Bus) is reused as the activity's meeting point.
 *
 * isAc/seatType/busBrand — bus-only facets added for real search-result
 * filtering (boarding/dropping point were already filterable; AC/non-AC,
 * seat type and operator brand were not, since busType was always one
 * free-text string). isAc/seatType are derived from that same busType
 * string via App\Support\BusType so every existing row filters correctly
 * with no backfill; busBrand (the chassis manufacturer — Volvo, Scania,
 * Mercedes-Benz, Ashok Leyland, Eicher) has no free-text field to derive
 * it from, so it's its own real column on bus_inventory_items too (set
 * from the admin form, same as operator_name/bus_type) as well as a
 * field MockBusSupplierAdapter assigns per fictional operator.
 *
 * boardingPoints/droppingPoints — additive multi-stop lists layered on top
 * of the original singular boardingPoint/droppingPoint fields (kept as-is
 * for backward compat with ticket PDF/metadata/admin displays, and as the
 * default selection). Each entry is ['point' => string, 'time' => 'HH:MM']
 * — MockBusSupplierAdapter populates all of its BOARDING_POINTS/
 * DROPPING_POINTS pool (not just the one it happens to pick for the
 * singular fields) with synthetic offset times; BusInventoryItem (which
 * only has one admin-entered point of each) emits a single-item list so
 * the frontend always gets an array to render, real or not.
 */
final class SearchResultItem
{
    public function __construct(
        public readonly string $supplierCode,
        public readonly string $externalId,
        public readonly string $netAmount,
        public readonly string $currency,
        public readonly string $carrierOrProvider,
        public readonly string $fareBasis,
        public readonly array $segments = [],
        public readonly array $raw = [],
        public readonly ?string $flightNumber = null,
        public readonly ?int $durationMinutes = null,
        public readonly ?int $stops = null,
        public readonly ?string $layoverCity = null,
        public readonly ?int $baggageAllowanceKg = null,
        public readonly ?bool $isRefundable = null,
        public readonly ?int $seatsAvailable = null,
        public readonly ?string $aircraftType = null,
        public readonly ?bool $mealIncluded = null,
        public readonly ?string $cabinClass = null,
        public readonly ?string $busType = null,
        public readonly ?string $boardingPoint = null,
        public readonly ?string $droppingPoint = null,
        public readonly ?int $starRating = null,
        public readonly ?string $mealPlan = null,
        public readonly ?int $nights = null,
        public readonly ?string $carType = null,
        public readonly ?int $includedKm = null,
        public readonly ?string $extraKmFare = null,
        public readonly ?string $coverageAmount = null,
        public readonly ?int $policyTermDays = null,
        public readonly ?string $shipName = null,
        public readonly ?bool $isAc = null,
        public readonly ?string $seatType = null,
        public readonly ?string $busBrand = null,
        public readonly ?array $boardingPoints = null,
        public readonly ?array $droppingPoints = null,
    ) {}

    public function toArray(): array
    {
        return [
            'supplier_code' => $this->supplierCode,
            'external_id' => $this->externalId,
            'net_amount' => $this->netAmount,
            'currency' => $this->currency,
            'carrier_or_provider' => $this->carrierOrProvider,
            'fare_basis' => $this->fareBasis,
            'segments' => $this->segments,
            'flight_number' => $this->flightNumber,
            'duration_minutes' => $this->durationMinutes,
            'stops' => $this->stops,
            'layover_city' => $this->layoverCity,
            'baggage_allowance_kg' => $this->baggageAllowanceKg,
            'is_refundable' => $this->isRefundable,
            'seats_available' => $this->seatsAvailable,
            'aircraft_type' => $this->aircraftType,
            'meal_included' => $this->mealIncluded,
            'cabin_class' => $this->cabinClass,
            'bus_type' => $this->busType,
            'boarding_point' => $this->boardingPoint,
            'dropping_point' => $this->droppingPoint,
            'star_rating' => $this->starRating,
            'meal_plan' => $this->mealPlan,
            'nights' => $this->nights,
            'car_type' => $this->carType,
            'included_km' => $this->includedKm,
            'extra_km_fare' => $this->extraKmFare,
            'coverage_amount' => $this->coverageAmount,
            'policy_term_days' => $this->policyTermDays,
            'ship_name' => $this->shipName,
            'is_ac' => $this->isAc,
            'seat_type' => $this->seatType,
            'bus_brand' => $this->busBrand,
            'boarding_points' => $this->boardingPoints,
            'dropping_points' => $this->droppingPoints,
        ];
    }
}
