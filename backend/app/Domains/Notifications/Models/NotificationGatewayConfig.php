<?php

namespace App\Domains\Notifications\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Database Design Document §9.1. tenant_id NULL = platform-default channel
 * account (MSG91/Gupshup/etc. keys owned by Afynd Pvt Ltd) — not scoped
 * with BelongsToTenant for the same reason as PaymentGatewayConfig.
 */
class NotificationGatewayConfig extends Model
{
    public const CHANNEL_SMS = 'sms';
    public const CHANNEL_WHATSAPP = 'whatsapp';
    public const CHANNEL_EMAIL = 'email';

    public const CHANNELS = [self::CHANNEL_SMS, self::CHANNEL_WHATSAPP, self::CHANNEL_EMAIL];

    protected $fillable = [
        'tenant_id',
        'channel',
        'provider',
        'credentials_encrypted',
        'sender_id',
        'dlt_template_ids',
        'is_active',
    ];

    protected $casts = [
        'credentials_encrypted' => 'encrypted:array',
        'dlt_template_ids' => 'array',
        'is_active' => 'boolean',
    ];
}
