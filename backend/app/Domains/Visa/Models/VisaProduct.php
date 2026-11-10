<?php

namespace App\Domains\Visa\Models;

use App\Domains\Holiday\Models\HolidayPackage;
use Illuminate\Database\Eloquent\Model;

/**
 * One (destination country, visa type) offering in the visa catalog.
 * Same tenant_id-nullable / no-BelongsToTenant convention as
 * HolidayPackage and CashbackRule: tenant_id NULL is the platform-wide
 * catalog, filtered manually by the controller.
 */
class VisaProduct extends Model
{
    public const SUPPLIER_AKBAR = 'akbar';

    public const SUPPLIER_TRIPJACK = 'tripjack';

    public const SUPPLIER_MANUAL = 'manual';

    public const ENTRY_SINGLE = 'single';

    public const ENTRY_MULTIPLE = 'multiple';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_PAUSED = 'paused';

    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'tenant_id',
        'supplier_code',
        'supplier_product_ref',
        'destination_country',
        'destination_country_name',
        'visa_type',
        'processing_time_label',
        'entry_type',
        'validity_days',
        'stay_days',
        'net_price',
        'markup_percent',
        'sell_price',
        'currency',
        'documents_required',
        'included_services',
        'excluded_services',
        'notes',
        'status',
        'synced_at',
    ];

    protected $casts = [
        'net_price' => 'decimal:2',
        'markup_percent' => 'decimal:2',
        'sell_price' => 'decimal:2',
        'documents_required' => 'array',
        'included_services' => 'array',
        'excluded_services' => 'array',
        'synced_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $product) {
            if (empty($product->status)) {
                $product->status = self::STATUS_DRAFT;
            }

            if (empty($product->markup_percent)) {
                $product->markup_percent = HolidayPackage::DEFAULT_MARKUP_PERCENT;
            }

            if (empty($product->sell_price) && ! empty($product->net_price)) {
                $product->sell_price = HolidayPackage::priceWithMarkup($product->net_price, $product->markup_percent);
            }
        });
    }

    public function isBookable(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
