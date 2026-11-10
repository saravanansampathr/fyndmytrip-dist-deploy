<?php

namespace App\Domains\Booking\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookingSegment extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'booking_id',
        'sequence',
        'origin',
        'destination',
        'departure_at',
        'arrival_at',
        'carrier_or_provider',
        'fare_basis',
        'raw_supplier_payload',
    ];

    protected $casts = [
        'departure_at' => 'datetime',
        'arrival_at' => 'datetime',
        'raw_supplier_payload' => 'array',
        'created_at' => 'datetime',
    ];

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }
}
