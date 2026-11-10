<?php

namespace App\Domains\Payments\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * "Which gateway handles Credit card / Debit card / UPI / Netbanking /
 * Wallets / International cards" — see the create_payment_method_routes_table
 * migration's docblock. Not tenant-scoped automatically (tenant_id NULL is
 * a platform-wide default), same convention as PaymentGatewayConfig.
 */
class PaymentMethodRoute extends Model
{
    public const METHOD_CREDIT_CARD = 'credit_card';

    public const METHOD_DEBIT_CARD = 'debit_card';

    public const METHOD_UPI = 'upi';

    public const METHOD_NETBANKING = 'netbanking';

    public const METHOD_WALLET = 'wallet';

    public const METHOD_INTERNATIONAL_CARD = 'international_card';

    public const METHODS = [
        self::METHOD_CREDIT_CARD,
        self::METHOD_DEBIT_CARD,
        self::METHOD_UPI,
        self::METHOD_NETBANKING,
        self::METHOD_WALLET,
        self::METHOD_INTERNATIONAL_CARD,
    ];

    public const GATEWAYS = ['razorpay', 'payu', 'cashfree', 'ccavenue', 'stripe'];

    protected $fillable = [
        'tenant_id',
        'payment_method',
        'gateway',
        'is_active',
        'priority',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'priority' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $route) {
            if (empty($route->priority)) {
                $route->priority = 1;
            }
        });
    }
}
