<?php

namespace App\Domains\Booking\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Reserves one seat (flight_number + travel_date + seat_number) for one
 * booking passenger, system-wide, so two different bookings can never be
 * assigned the same physical seat on the same flight/date. The unique
 * index on (flight_number, travel_date, seat_number) is what actually
 * enforces this — see FlightSeatMapService.
 */
class FlightSeatLock extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'flight_number',
        'travel_date',
        'seat_number',
        'booking_id',
        'booking_passenger_id',
    ];

    protected $casts = [
        'travel_date' => 'date',
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
