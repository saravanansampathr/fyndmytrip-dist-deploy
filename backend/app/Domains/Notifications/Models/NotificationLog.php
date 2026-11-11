<?php

namespace App\Domains\Notifications\Models;

use App\Domains\Booking\Models\Booking;
use App\Domains\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Database Design Document §9.2.
 */
class NotificationLog extends Model
{
    use BelongsToTenant;

    const UPDATED_AT = null;

    public const STATUS_QUEUED = 'queued';
    public const STATUS_SENT = 'sent';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'tenant_id',
        'booking_id',
        'channel',
        'recipient',
        'template_key',
        'status',
        'provider_message_id',
        'sent_at',
    ];

    protected $casts = [
        'recipient' => 'encrypted',
        'sent_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $log) {
            if (empty($log->status)) {
                $log->status = self::STATUS_QUEUED;
            }
        });
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }
}
