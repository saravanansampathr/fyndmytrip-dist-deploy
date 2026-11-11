<?php

namespace App\Domains\Booking\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row of the "rooming list" a requester submits for a hotel group
 * booking after paying the deposit — see HotelGroupBookingRequest's
 * docblock and HotelGroupBookingService::submitRoomingList(). No tenant
 * scope of its own: always reached through its parent
 * HotelGroupBookingRequest, which already carries the tenant boundary
 * (same pattern as GroupBookingPassenger).
 */
class HotelGroupBookingGuest extends Model
{
    public const TYPE_ADULT = 'adult';
    public const TYPE_CHILD = 'child';

    public const TYPES = [self::TYPE_ADULT, self::TYPE_CHILD];

    protected $fillable = [
        'hotel_group_booking_request_id',
        'room_number',
        'title',
        'first_name',
        'last_name',
        'guest_type',
        'dob',
        'gender',
        'id_proof_type',
        'id_proof_number',
    ];

    protected $casts = [
        'dob' => 'date',
    ];

    public function hotelGroupBookingRequest(): BelongsTo
    {
        return $this->belongsTo(HotelGroupBookingRequest::class);
    }
}
