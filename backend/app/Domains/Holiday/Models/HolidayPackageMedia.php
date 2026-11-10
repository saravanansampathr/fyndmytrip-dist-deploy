<?php

namespace App\Domains\Holiday\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One photo or video attached to a holiday package's gallery. Kept as a
 * child table (like itinerary days / price tiers) rather than a JSON column
 * so entries can carry a caption and an explicit sort order, and so a
 * video and an image can be told apart without sniffing the URL.
 */
class HolidayPackageMedia extends Model
{
    public const TYPE_IMAGE = 'image';

    public const TYPE_VIDEO = 'video';

    protected $table = 'holiday_package_media';

    protected $fillable = [
        'holiday_package_id',
        'media_type',
        'url',
        'caption',
        'sort_order',
    ];

    protected $casts = [
        'sort_order' => 'integer',
    ];

    public function holidayPackage(): BelongsTo
    {
        return $this->belongsTo(HolidayPackage::class);
    }
}
