<?php

namespace App\Domains\Holiday\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HolidayPackagePriceTier extends Model
{
    public const OCC_ADULT_SHARING = 'adult_sharing';

    public const OCC_ADULT_SINGLE = 'adult_single';

    public const OCC_CHILD_WITH_BED = 'child_with_bed';

    public const OCC_CHILD_WITHOUT_BED = 'child_without_bed';

    public const OCC_INFANT = 'infant';

    protected $fillable = [
        'holiday_package_id',
        'occupancy_type',
        'age_label',
        'net_price',
        'markup_percent',
        'sell_price',
        'currency',
    ];

    protected $casts = [
        'net_price' => 'decimal:2',
        'markup_percent' => 'decimal:2',
        'sell_price' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $tier) {
            if (empty($tier->markup_percent)) {
                $tier->markup_percent = HolidayPackage::DEFAULT_MARKUP_PERCENT;
            }

            if (empty($tier->sell_price) && ! empty($tier->net_price)) {
                $tier->sell_price = HolidayPackage::priceWithMarkup($tier->net_price, $tier->markup_percent);
            }
        });
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(HolidayPackage::class, 'holiday_package_id');
    }
}
