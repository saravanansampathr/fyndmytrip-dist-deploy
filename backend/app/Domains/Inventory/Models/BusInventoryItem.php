<?php

namespace App\Domains\Inventory\Models;

use App\Domains\Inventory\Models\Concerns\HasCommonInventoryFields;
use App\Support\BusType;
use Illuminate\Database\Eloquent\Model;

/**
 * A tenant's own manually-entered bus inventory (seats on a fleet it owns
 * or has bought in bulk) — merges into real BusBookingController::search()
 * results alongside MOCKBUS-adapter offers (see toBusOfferArray()) exactly
 * like FlightInventoryItem does for flights, in addition to its original
 * admin CRUD / quick-book test tool.
 */
class BusInventoryItem extends Model
{
    use HasCommonInventoryFields;

    protected $table = 'bus_inventory_items';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    /**
     * The synthetic supplier_code used to mark an offer as sourced from a
     * tenant's own inventory rather than the MOCKBUS adapter —
     * BusBookingController::hold()/finalizeTicketing() branch on this to
     * skip the SupplierOrchestrator entirely, same convention as
     * FlightInventoryItem::SUPPLIER_CODE.
     */
    public const SUPPLIER_CODE = 'OWN_INVENTORY';

    protected $fillable = [
        'tenant_id',
        'title',
        'origin',
        'destination',
        'travel_date',
        'departure_time',
        'operator_name',
        'bus_type',
        'boarding_point',
        'dropping_point',
        'bus_brand',
        'duration_minutes',
        'is_refundable',
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
        'is_refundable' => 'boolean',
        'capacity' => 'integer',
        'net_price' => 'decimal:2',
        'valid_from' => 'date',
        'valid_to' => 'date',
        'share_on_platform_site' => 'boolean',
        'share_with_network' => 'boolean',
    ];

    /**
     * Shapes this row exactly like SearchResultItem::toArray() so it merges
     * seamlessly into BusBookingController::search()'s offer array — mirrors
     * FlightInventoryItem::toFlightOfferArray().
     */
    public function toBusOfferArray(): array
    {
        $departureAt = $this->travel_date
            ? \Illuminate\Support\Carbon::parse($this->travel_date->toDateString().' '.($this->departure_time ?? '00:00'))
            : now();
        $durationMinutes = (int) ($this->duration_minutes ?? 300);
        $arrivalAt = $departureAt->copy()->addMinutes($durationMinutes);

        return [
            'supplier_code' => self::SUPPLIER_CODE,
            'external_id' => 'inv-'.$this->id,
            'net_amount' => (string) $this->net_price,
            'currency' => $this->currency,
            'carrier_or_provider' => $this->operator_name ?? $this->title,
            'fare_basis' => $this->bus_type ?? 'STANDARD',
            'segments' => [[
                'sequence' => 1,
                'origin' => $this->origin,
                'destination' => $this->destination,
                'departure_at' => $departureAt->toIso8601String(),
                'arrival_at' => $arrivalAt->toIso8601String(),
                'carrier_or_provider' => $this->operator_name ?? $this->title,
                'fare_basis' => $this->bus_type ?? 'STANDARD',
                'bus_type' => $this->bus_type,
                'boarding_point' => $this->boarding_point,
                'dropping_point' => $this->dropping_point,
                'duration_minutes' => $durationMinutes,
            ]],
            'duration_minutes' => $durationMinutes,
            'stops' => 0,
            'is_refundable' => $this->is_refundable,
            'seats_available' => $this->capacity,
            'bus_type' => $this->bus_type,
            'boarding_point' => $this->boarding_point,
            'dropping_point' => $this->dropping_point,
            // AC/non-AC and seat type are parsed straight out of the same
            // free-text bus_type an admin already enters — see BusType's
            // own docblock for why this derives rather than stores them.
            // Vehicle brand (Volvo/Scania/...) has no free-text field to
            // derive it from, so it's its own real column instead, set
            // from the admin form same as operator_name/bus_type.
            'is_ac' => BusType::isAc($this->bus_type),
            'seat_type' => BusType::seatType($this->bus_type),
            'bus_brand' => $this->bus_brand,
            // Admin only ever enters one boarding/dropping point per row, so
            // these are single-item lists — same additive shape
            // MockBusSupplierAdapter emits, just with nothing to pick from.
            'boarding_points' => $this->boarding_point ? [['point' => $this->boarding_point, 'time' => $departureAt->format('H:i')]] : [],
            'dropping_points' => $this->dropping_point ? [['point' => $this->dropping_point, 'time' => $arrivalAt->format('H:i')]] : [],
        ];
    }
}
