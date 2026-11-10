<?php

namespace App\Domains\Inventory\Models;

use App\Domains\Inventory\Models\Concerns\HasCommonInventoryFields;
use Illuminate\Database\Eloquent\Model;

/**
 * A tenant's own manually-entered cruise inventory (cabins on a sailing it
 * has bought an allotment on) — merges into real
 * CruiseBookingController::search() results alongside MOCKCRUISE-adapter
 * offers (see toCruiseOfferArray()) exactly like
 * FlightInventoryItem/BusInventoryItem/HotelInventoryItem/CabInventoryItem/
 * InsuranceInventoryItem do for their modules, in addition to the admin
 * CRUD screen it already had.
 */
class CruiseInventoryItem extends Model
{
    use HasCommonInventoryFields;

    protected $table = 'cruise_inventory_items';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    /**
     * The synthetic supplier_code used to mark an offer as sourced from a
     * tenant's own inventory rather than the MOCKCRUISE adapter —
     * CruiseBookingController::hold()/finalizeTicketing() branch on this to
     * skip the SupplierOrchestrator entirely, same convention as every
     * other module's *InventoryItem::SUPPLIER_CODE.
     */
    public const SUPPLIER_CODE = 'OWN_INVENTORY';

    protected $fillable = [
        'tenant_id',
        'title',
        'cruise_line',
        'ship_name',
        'departure_port',
        'arrival_port',
        'departure_date',
        'nights',
        'cabin_type',
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
        'departure_date' => 'date',
        'nights' => 'integer',
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
     * seamlessly into CruiseBookingController::search()'s offer array —
     * mirrors BusInventoryItem::toBusOfferArray() /
     * HotelInventoryItem::toHotelOfferArray() /
     * CabInventoryItem::toCabOfferArray().
     */
    public function toCruiseOfferArray(): array
    {
        $nights = (int) ($this->nights ?? 7);
        $arrivalDate = $this->departure_date ? $this->departure_date->copy()->addDays($nights) : null;

        return [
            'supplier_code' => self::SUPPLIER_CODE,
            'external_id' => 'inv-'.$this->id,
            'net_amount' => (string) $this->net_price,
            'currency' => $this->currency,
            'carrier_or_provider' => $this->cruise_line ?: $this->title,
            'fare_basis' => $this->cabin_type ?? 'STANDARD',
            'segments' => [[
                'sequence' => 1,
                'origin' => $this->departure_port,
                'destination' => $this->arrival_port,
                'departure_at' => $this->departure_date?->toIso8601String(),
                'arrival_at' => $arrivalDate?->toIso8601String(),
                'carrier_or_provider' => $this->cruise_line ?: $this->title,
                'fare_basis' => $this->cabin_type ?? 'STANDARD',
                'ship_name' => $this->ship_name,
                'nights' => $nights,
            ]],
            'is_refundable' => $this->is_refundable,
            'seats_available' => $this->capacity,
            'ship_name' => $this->ship_name,
            'nights' => $nights,
        ];
    }
}
