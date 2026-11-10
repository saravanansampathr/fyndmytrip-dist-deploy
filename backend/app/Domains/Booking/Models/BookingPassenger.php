<?php

namespace App\Domains\Booking\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookingPassenger extends Model
{
    public const TYPE_ADULT = 'adult';

    public const TYPE_CHILD = 'child';

    public const TYPE_INFANT = 'infant';

    public $timestamps = false;

    protected $fillable = [
        'booking_id',
        'title',
        'name',
        'first_name',
        'last_name',
        'passenger_type',
        'dob',
        // Plain numeric age — Bus/Cab/Hotel's passenger-details step
        // captures this instead of a full date of birth; Flight is the
        // only vertical asking for `dob` (passport/MRZ purposes on
        // international travel), so the two fields deliberately coexist
        // without either one deriving the other.
        'age',
        'gender',
        'passport_number',
        'passport_issue_date',
        'passport_expiry_date',
        'passport_country',
        'document_type',
        'document_number',
        'seat_number',
    ];

    protected $casts = [
        'dob' => 'date',
        'age' => 'integer',
        'passport_number' => 'encrypted',
        'passport_issue_date' => 'date',
        'passport_expiry_date' => 'date',
        'document_number' => 'encrypted',
        'created_at' => 'datetime',
    ];

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function ancillaries()
    {
        return $this->hasMany(BookingAncillary::class);
    }
}
