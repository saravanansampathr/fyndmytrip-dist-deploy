<?php

namespace App\Domains\Inventory\Models;

use App\Domains\Inventory\Models\Concerns\HasCommonInventoryFields;
use Illuminate\Database\Eloquent\Model;

/**
 * A tenant's own manually-entered activity inventory (city tours, water
 * sports, museum entries, and similar single-date/single-location
 * experiences bought as an allotment) — merges into real
 * ActivityBookingController::search() results alongside MOCKACTIVITY-
 * adapter offers (see toActivityOfferArray()) exactly like
 * CruiseInventoryItem/CabInventoryItem/InsuranceInventoryItem do for their
 * modules, in addition to the admin CRUD screen it gets here for the first
 * time (Activities previously had no model, no admin screen, nothing).
 */
class ActivityInventoryItem extends Model
{
    use HasCommonInventoryFields;

    protected $table = 'activity_inventory_items';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    /**
     * The synthetic supplier_code used to mark an offer as sourced from a
     * tenant's own inventory rather than the MOCKACTIVITY adapter —
     * ActivityBookingController::hold()/finalizeTicketing() branch on this
     * to skip the SupplierOrchestrator entirely, same convention as every
     * other module's *InventoryItem::SUPPLIER_CODE.
     */
    public const SUPPLIER_CODE = 'OWN_INVENTORY';

    protected $fillable = [
        'tenant_id',
        'title',
        'city',
        'activity_date',
        'provider_name',
        'category',
        'duration_minutes',
        'meeting_point',
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
        'activity_date' => 'date',
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
     * seamlessly into ActivityBookingController::search()'s offer array —
     * mirrors CruiseInventoryItem::toCruiseOfferArray() /
     * CabInventoryItem::toCabOfferArray(). Activities is the first module
     * that needs no new DTO fields at all: carrierOrProvider/fareBasis
     * (provider name/category), durationMinutes (already added for Cab)
     * and boardingPoint (already added for Bus, reused here as the meeting
     * point) cover everything an activity offer needs.
     */
    public function toActivityOfferArray(): array
    {
        return [
            'supplier_code' => self::SUPPLIER_CODE,
            'external_id' => 'inv-'.$this->id,
            'net_amount' => (string) $this->net_price,
            'currency' => $this->currency,
            'carrier_or_provider' => $this->provider_name ?: $this->title,
            'fare_basis' => $this->category ?? 'General',
            'segments' => [[
                'sequence' => 1,
                'origin' => $this->city,
                'destination' => null,
                'departure_at' => $this->activity_date?->toIso8601String(),
                'arrival_at' => null,
                'carrier_or_provider' => $this->provider_name ?: $this->title,
                'fare_basis' => $this->category ?? 'General',
                'duration_minutes' => $this->duration_minutes,
                'boarding_point' => $this->meeting_point,
            ]],
            'is_refundable' => $this->is_refundable,
            'seats_available' => $this->capacity,
            'duration_minutes' => $this->duration_minutes,
            'boarding_point' => $this->meeting_point,
        ];
    }
}
