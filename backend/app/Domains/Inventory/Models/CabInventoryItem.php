<?php

namespace App\Domains\Inventory\Models;

use App\Domains\Inventory\Models\Concerns\HasCommonInventoryFields;
use Illuminate\Database\Eloquent\Model;

/**
 * A tenant's own manually-entered cab inventory (an owned/contracted cab
 * fleet) — merges into real CabBookingController::search() results
 * alongside MOCKCAB-adapter offers (see toCabOfferArray()) exactly like
 * FlightInventoryItem/BusInventoryItem/HotelInventoryItem do for their
 * modules, in addition to the admin CRUD screen it already had.
 */
class CabInventoryItem extends Model
{
    use HasCommonInventoryFields;

    protected $table = 'cab_inventory_items';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    /**
     * The synthetic supplier_code used to mark an offer as sourced from a
     * tenant's own inventory rather than the MOCKCAB adapter —
     * CabBookingController::hold()/finalizeTicketing() branch on this to
     * skip the SupplierOrchestrator entirely, same convention as
     * FlightInventoryItem::SUPPLIER_CODE / BusInventoryItem::SUPPLIER_CODE /
     * HotelInventoryItem::SUPPLIER_CODE.
     */
    public const SUPPLIER_CODE = 'OWN_INVENTORY';

    protected $fillable = [
        'tenant_id',
        'title',
        'origin',
        'destination',
        'travel_date',
        'pickup_time',
        'provider_name',
        'car_type',
        'included_km',
        'extra_km_fare',
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
        'included_km' => 'integer',
        'extra_km_fare' => 'decimal:2',
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
     * seamlessly into CabBookingController::search()'s offer array —
     * mirrors BusInventoryItem::toBusOfferArray() /
     * HotelInventoryItem::toHotelOfferArray().
     */
    public function toCabOfferArray(): array
    {
        $departureAt = $this->travel_date
            ? \Illuminate\Support\Carbon::parse($this->travel_date->toDateString().' '.($this->pickup_time ?? '00:00'))
            : now();
        $durationMinutes = (int) ($this->duration_minutes ?? 180);
        $arrivalAt = $departureAt->copy()->addMinutes($durationMinutes);

        return [
            'supplier_code' => self::SUPPLIER_CODE,
            'external_id' => 'inv-'.$this->id,
            'net_amount' => (string) $this->net_price,
            'currency' => $this->currency,
            'carrier_or_provider' => $this->provider_name ?? $this->title,
            'fare_basis' => $this->car_type ?? 'STANDARD',
            'segments' => [[
                'sequence' => 1,
                'origin' => $this->origin,
                'destination' => $this->destination,
                'departure_at' => $departureAt->toIso8601String(),
                'arrival_at' => $arrivalAt->toIso8601String(),
                'carrier_or_provider' => $this->provider_name ?? $this->title,
                'fare_basis' => $this->car_type ?? 'STANDARD',
                'car_type' => $this->car_type,
                'included_km' => $this->included_km,
                'extra_km_fare' => $this->extra_km_fare !== null ? (string) $this->extra_km_fare : null,
                'duration_minutes' => $durationMinutes,
            ]],
            'duration_minutes' => $durationMinutes,
            'stops' => 0,
            'is_refundable' => $this->is_refundable,
            'seats_available' => $this->capacity,
            'car_type' => $this->car_type,
            'included_km' => $this->included_km,
            'extra_km_fare' => $this->extra_km_fare !== null ? (string) $this->extra_km_fare : null,
        ];
    }
}
