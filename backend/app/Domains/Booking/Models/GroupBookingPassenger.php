<?php

namespace App\Domains\Booking\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row of the "name list" a requester submits for a group booking after
 * paying the deposit — see GroupBookingRequest's docblock and
 * GroupBookingService::submitNameList(). No tenant scope of its own: always
 * reached through its parent GroupBookingRequest, which already carries the
 * tenant boundary (same pattern as OfflinePaymentAttachment).
 */
class GroupBookingPassenger extends Model
{
    public const TYPE_ADULT = 'adult';
    public const TYPE_CHILD = 'child';
    public const TYPE_INFANT = 'infant';

    public const TYPES = [self::TYPE_ADULT, self::TYPE_CHILD, self::TYPE_INFANT];

    protected $fillable = [
        'group_booking_request_id',
        'title',
        'first_name',
        'last_name',
        'passenger_type',
        'dob',
        'gender',
        'passport_number',
        'passport_expiry_date',
    ];

    protected $casts = [
        'dob' => 'date',
        'passport_expiry_date' => 'date',
    ];

    public function groupBookingRequest(): BelongsTo
    {
        return $this->belongsTo(GroupBookingRequest::class);
    }
}
