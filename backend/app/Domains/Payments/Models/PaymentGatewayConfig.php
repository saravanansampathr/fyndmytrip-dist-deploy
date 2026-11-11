<?php

namespace App\Domains\Payments\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * "Bring Your Own Gateway": tenant_id NULL = platform-wide default gateway
 * (Master's own Razorpay/PayU/Cashfree/CCAvenue/Stripe keys); every White-
 * Label/Agent tenant may configure its own. Database Design Document §6.1.
 * Deliberately NOT scoped with BelongsToTenant since a NULL tenant_id row
 * (the platform default) must remain visible to every tenant's resolution
 * logic rather than being filtered out by the standard tenant scope.
 */
class PaymentGatewayConfig extends Model
{
    public const GATEWAYS = ['razorpay', 'payu', 'cashfree', 'ccavenue', 'stripe'];

    protected $fillable = [
        'tenant_id',
        'gateway',
        'credentials_encrypted',
        'is_active',
        'priority',
    ];

    protected $casts = [
        'credentials_encrypted' => 'encrypted:array',
        'is_active' => 'boolean',
        'priority' => 'integer',
    ];
}
