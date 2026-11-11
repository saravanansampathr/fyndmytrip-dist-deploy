<?php

namespace App\Domains\Inventory\Models;

use App\Domains\Inventory\Models\Concerns\HasCommonInventoryFields;
use Illuminate\Database\Eloquent\Model;

/**
 * A tenant's own manually-entered flight inventory (bulk-bought seats on a
 * real flight, a charter, etc.) that merges into flight search results
 * alongside real supplier offers — see toFlightOfferArray() and
 * FlightBookingController's own-inventory helpers, which are the only
 * consumer of this model outside its admin CRUD screen.
 *
 * Formerly a row in the generic `inventory_items` table (module='flight')
 * with these fields buried in a freeform `details` JSON column; this
 * dedicated table/model gives flight inventory its own real typed schema
 * ("Build the super product" — per-product-type fields instead of one
 * generic form for every module).
 */
class FlightInventoryItem extends Model
{
    use HasCommonInventoryFields;

    protected $table = 'flight_inventory_items';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    /**
     * The synthetic supplier_code used to mark an offer/leg as sourced from
     * a tenant's own inventory rather than a real supplier adapter —
     * FlightBookingController::hold()/finalizeTicketing() branch on this to
     * skip the SupplierOrchestrator entirely for these legs. Unchanged from
     * the old InventoryItem::SUPPLIER_CODE so existing held/confirmed
     * bookings that already reference "OWN_INVENTORY-{id}-..." keep
     * resolving correctly after this migration.
     */
    public const SUPPLIER_CODE = 'OWN_INVENTORY';

    public const CABIN_CLASSES = ['economy', 'premium_economy', 'business', 'first'];

    protected $fillable = [
        'tenant_id',
        'title',
        'origin',
        'destination',
        'travel_date',
        'departure_time',
        'airline',
        'flight_number',
        'cabin_class',
        'aircraft_type',
        'duration_minutes',
        'baggage_allowance_kg',
        'is_refundable',
        'meal_included',
        'capacity',
        'net_price',
        'currency',
        'valid_from',
        'valid_to',
        'share_on_platform_site',
        'share_with_network',
        'status',
    ];

    protected $casts = [
        'travel_date' => 'date',
        'duration_minutes' => 'integer',
        'baggage_allowance_kg' => 'integer',
        'is_refundable' => 'boolean',
        'meal_included' => 'boolean',
        'capacity' => 'integer',
        'net_price' => 'decimal:2',
        'valid_from' => 'date',
        'valid_to' => 'date',
        'share_on_platform_site' => 'boolean',
        'share_with_network' => 'boolean',
    ];

    /**
     * Shapes this row exactly like SearchResultItem::toArray() so it merges
     * seamlessly into FlightBookingController::search()'s offer arrays and
     * "just works" with the existing FlightFilters/FlightResultCard
     * frontend — those components have no idea this offer isn't from a
     * real supplier adapter.
     */
    public function toFlightOfferArray(): array
    {
        // The frontend's FlightResultCard/formatTime() expects full ISO
        // departure/arrival timestamps (every real supplier adapter sets
        // both — see MockFlightSupplierAdapter) — a bare date or a missing
        // arrival_at renders as "Invalid Date". departure_time (HH:MM,
        // admin-entered, defaults to 00:00) combines with travel_date to
        // build a real departure instant, and arrival is derived by adding
        // duration_minutes.
        $departureAt = $this->travel_date
            ? \Illuminate\Support\Carbon::parse($this->travel_date->toDateString().' '.($this->departure_time ?? '00:00'))
            : now();
        $arrivalAt = $departureAt->copy()->addMinutes((int) $this->duration_minutes);

        return [
            'supplier_code' => self::SUPPLIER_CODE,
            'external_id' => 'inv-'.$this->id,
            'net_amount' => (string) $this->net_price,
            'currency' => $this->currency,
            'carrier_or_provider' => $this->airline ?? $this->title,
            'fare_basis' => 'OWNINV',
            'segments' => [[
                'origin' => $this->origin,
                'destination' => $this->destination,
                'departure_at' => $departureAt->toIso8601String(),
                'arrival_at' => $arrivalAt->toIso8601String(),
                'carrier_or_provider' => $this->airline ?? $this->title,
            ]],
            'flight_number' => $this->flight_number,
            'duration_minutes' => (int) $this->duration_minutes,
            'stops' => 0,
            'layover_city' => null,
            'baggage_allowance_kg' => $this->baggage_allowance_kg,
            'is_refundable' => $this->is_refundable,
            'seats_available' => $this->capacity,
            'aircraft_type' => $this->aircraft_type,
            'meal_included' => $this->meal_included,
            'cabin_class' => $this->cabin_class ?? 'economy',
        ];
    }
}
