<?php

namespace App\Domains\Reporting\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Database Design Document §10.2. Polymorphic across bookings and
 * wallet_ledger_entries via subject_type/subject_id rather than Eloquent's
 * default morph columns, to match the documented schema exactly.
 */
class FraudFlag extends Model
{
    public const SUBJECT_BOOKING = 'booking';
    public const SUBJECT_WALLET_LEDGER_ENTRY = 'wallet_ledger_entry';

    public const STATUS_OPEN = 'open';
    public const STATUS_REVIEWED = 'reviewed';
    public const STATUS_CLEARED = 'cleared';
    public const STATUS_CONFIRMED_FRAUD = 'confirmed_fraud';

    protected $fillable = [
        'subject_type',
        'subject_id',
        'risk_score',
        'reason',
        'status',
        'reviewed_by',
    ];

    protected $casts = [
        'risk_score' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $flag) {
            if (empty($flag->status)) {
                $flag->status = self::STATUS_OPEN;
            }
        });
    }
}
