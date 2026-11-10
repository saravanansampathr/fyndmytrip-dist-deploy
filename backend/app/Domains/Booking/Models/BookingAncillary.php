<?php

namespace App\Domains\Booking\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One purchased add-on line item against a booking — a meal, extra baggage,
 * an insurance plan, or a misc. service (priority check-in, lounge access,
 * etc). See config/ancillaries.php for the sellable catalog these are
 * created from.
 */
class BookingAncillary extends Model
{
    public $timestamps = false;

    public const CATEGORY_MEAL = 'meal';
    public const CATEGORY_BAGGAGE = 'baggage';
    public const CATEGORY_INSURANCE = 'insurance';
    public const CATEGORY_OTHER = 'other';

    protected $fillable = [
        'booking_id',
        'booking_passenger_id',
        'category',
        'code',
        'label',
        'amount',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'created_at' => 'datetime',
    ];

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function passenger(): BelongsTo
    {
        return $this->belongsTo(BookingPassenger::class, 'booking_passenger_id');
    }
}
