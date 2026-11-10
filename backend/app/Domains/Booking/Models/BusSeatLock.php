<?php

namespace App\Domains\Booking\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Reserves one bus seat (supplier_code + external_id + travel_date +
 * seat_number) for one booking passenger, system-wide — the bus analog of
 * FlightSeatLock. Also carries the passenger's own gender directly, so
 * BusSeatMapService can apply the female-adjacent-seat rule with a single
 * query per seat-map render instead of joining back to booking_passengers.
 * The unique index on (supplier_code, external_id, travel_date,
 * seat_number) is what actually enforces "never the same physical seat
 * twice" — see BusSeatMapService::reserve().
 */
class BusSeatLock extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'supplier_code',
        'external_id',
        'travel_date',
        'seat_number',
        'gender',
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
