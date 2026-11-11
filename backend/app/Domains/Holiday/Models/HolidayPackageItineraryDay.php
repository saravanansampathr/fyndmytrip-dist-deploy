<?php

namespace App\Domains\Holiday\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HolidayPackageItineraryDay extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'holiday_package_id',
        'day_number',
        'title',
        'description',
    ];

    public function package(): BelongsTo
    {
        return $this->belongsTo(HolidayPackage::class, 'holiday_package_id');
    }
}
