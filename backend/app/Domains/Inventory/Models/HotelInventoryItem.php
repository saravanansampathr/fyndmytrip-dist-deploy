<?php

namespace App\Domains\Inventory\Models;

use App\Domains\Inventory\Models\Concerns\HasCommonInventoryFields;
use Illuminate\Database\Eloquent\Model;

/**
 * A tenant's own manually-entered hotel inventory (an allotment it holds
 * directly with a property) — merges into real
 * HotelBookingController::search() results alongside MOCKHOTEL-adapter
 * offers (see toHotelOfferArray()) exactly like FlightInventoryItem and
 * BusInventoryItem do for their modules, in addition to its original
 * admin CRUD / quick-book test tool.
 */
class HotelInventoryItem extends Model
{
    use HasCommonInventoryFields;

    protected $table = 'hotel_inventory_items';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    /**
     * The synthetic supplier_code used to mark an offer as sourced from a
     * tenant's own inventory rather than the MOCKHOTEL adapter —
     * HotelBookingController::hold()/finalizeTicketing() branch on this to
     * skip the SupplierOrchestrator entirely, same convention as
     * FlightInventoryItem::SUPPLIER_CODE / BusInventoryItem::SUPPLIER_CODE.
     */
    public const SUPPLIER_CODE = 'OWN_INVENTORY';

    protected $fillable = [
        'tenant_id',
        'title',
        'city',
        'hotel_name',
        'check_in_date',
        'check_out_date',
        'room_type',
        'star_rating',
        'meal_plan',
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
        'check_in_date' => 'date',
        'check_out_date' => 'date',
        'star_rating' => 'integer',
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
     * seamlessly into HotelBookingController::search()'s offer array —
     * mirrors FlightInventoryItem::toFlightOfferArray() /
     * BusInventoryItem::toBusOfferArray(). The "segment" here is the stay
     * itself (check-in to check-out), not a transit leg — origin/
     * destination are reused as city/property the same way Bus reused them
     * for boarding city/destination city.
     */
    public function toHotelOfferArray(): array
    {
        $nights = ($this->check_in_date && $this->check_out_date)
            ? (int) $this->check_in_date->diffInDays($this->check_out_date)
            : null;

        return [
            'supplier_code' => self::SUPPLIER_CODE,
            'external_id' => 'inv-'.$this->id,
            'net_amount' => (string) $this->net_price,
            'currency' => $this->currency,
            'carrier_or_provider' => $this->hotel_name ?: $this->title,
            'fare_basis' => $this->room_type ?? 'STANDARD',
            'segments' => [[
                'sequence' => 1,
                'origin' => $this->city,
                'destination' => $this->hotel_name ?: $this->title,
                'departure_at' => $this->check_in_date?->toIso8601String(),
                'arrival_at' => $this->check_out_date?->toIso8601String(),
                'carrier_or_provider' => $this->hotel_name ?: $this->title,
                'fare_basis' => $this->room_type ?? 'STANDARD',
                'star_rating' => $this->star_rating,
                'meal_plan' => $this->meal_plan,
                'nights' => $nights,
            ]],
            'is_refundable' => $this->is_refundable,
            'seats_available' => $this->capacity,
            'star_rating' => $this->star_rating,
            'meal_plan' => $this->meal_plan,
            'nights' => $nights,
        ];
    }
}
