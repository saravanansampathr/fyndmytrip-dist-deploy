<?php

namespace App\Domains\Inventory\Models;

use App\Domains\Inventory\Models\Concerns\HasCommonInventoryFields;
use Illuminate\Database\Eloquent\Model;

/**
 * A tenant's own manually-entered travel-insurance plan (a policy it
 * distributes directly rather than via a supplier) — merges into real
 * InsuranceBookingController::search() results alongside MOCKINSURANCE-
 * adapter offers (see toInsuranceOfferArray()) exactly like
 * FlightInventoryItem/BusInventoryItem/HotelInventoryItem/CabInventoryItem
 * do for their modules, in addition to the admin CRUD screen it already
 * had. Not to be confused with VisaProduct's own documents/inclusions
 * checklist — this is a distinct Product Module (standalone travel
 * insurance plans), separate from visa.
 *
 * Unlike Hotel/Bus/Cab, a plan row carries no trip date of its own — see
 * this model's own migration docblock and
 * InsuranceBookingController::ownInventoryOffers() for why.
 */
class InsuranceInventoryItem extends Model
{
    use HasCommonInventoryFields;

    protected $table = 'insurance_inventory_items';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    /**
     * The synthetic supplier_code used to mark an offer as sourced from a
     * tenant's own inventory rather than the MOCKINSURANCE adapter —
     * InsuranceBookingController::hold()/finalizeTicketing() branch on this
     * to skip the SupplierOrchestrator entirely, same convention as
     * FlightInventoryItem::SUPPLIER_CODE / BusInventoryItem::SUPPLIER_CODE /
     * HotelInventoryItem::SUPPLIER_CODE / CabInventoryItem::SUPPLIER_CODE.
     */
    public const SUPPLIER_CODE = 'OWN_INVENTORY';

    protected $fillable = [
        'tenant_id',
        'title',
        'plan_name',
        'provider_name',
        'coverage_amount',
        'policy_term_days',
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
        'coverage_amount' => 'decimal:2',
        'policy_term_days' => 'integer',
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
     * seamlessly into InsuranceBookingController::search()'s offer array —
     * mirrors BusInventoryItem::toBusOfferArray() /
     * HotelInventoryItem::toHotelOfferArray() /
     * CabInventoryItem::toCabOfferArray(). $tripStartDate/$tripEndDate come
     * from the search request itself (this row has no dates of its own),
     * exactly the way the customer's own trip window is used everywhere
     * else in this offer shape.
     */
    public function toInsuranceOfferArray(string $tripStartDate, string $tripEndDate): array
    {
        return [
            'supplier_code' => self::SUPPLIER_CODE,
            'external_id' => 'inv-'.$this->id,
            'net_amount' => (string) $this->net_price,
            'currency' => $this->currency,
            'carrier_or_provider' => $this->provider_name ?: $this->title,
            'fare_basis' => $this->plan_name,
            'segments' => [[
                'sequence' => 1,
                'origin' => null,
                'destination' => null,
                'departure_at' => $tripStartDate.'T00:00:00',
                'arrival_at' => $tripEndDate.'T23:59:59',
                'carrier_or_provider' => $this->provider_name ?: $this->title,
                'fare_basis' => $this->plan_name,
                'coverage_amount' => $this->coverage_amount !== null ? (string) $this->coverage_amount : null,
                'policy_term_days' => $this->policy_term_days,
            ]],
            'is_refundable' => $this->is_refundable,
            'seats_available' => $this->capacity,
            'coverage_amount' => $this->coverage_amount !== null ? (string) $this->coverage_amount : null,
            'policy_term_days' => $this->policy_term_days,
        ];
    }
}
