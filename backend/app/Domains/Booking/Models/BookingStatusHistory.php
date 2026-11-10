<?php

namespace App\Domains\Booking\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Every transition of bookings.status is written here before the parent
 * row updates — the only permitted path to change a booking's status, per
 * Database Design Document §4.4 and §12. See BookingStatusService.
 */
class BookingStatusHistory extends Model
{
    const UPDATED_AT = null;

    // The migration creates the singular `booking_status_history` table;
    // without this override Eloquent auto-pluralizes to
    // `booking_status_histories`, which does not exist and breaks every
    // status transition (e.g. FlightBookingController::hold()).
    protected $table = 'booking_status_history';

    protected $fillable = [
        'booking_id',
        'from_status',
        'to_status',
        'changed_by',
        'reason',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
