<?php

return [
    // Display labels for PaymentGatewayConfig::GATEWAYS, for the CRM's
    // Payment gateway configuration screen (PaymentGatewayConfigController).
    'labels' => [
        'razorpay' => 'Razorpay',
        'payu' => 'PayU',
        'cashfree' => 'Cashfree',
        'ccavenue' => 'CCAvenue',
        'stripe' => 'Stripe',
    ],

    // Per-gateway credential field templates — which text fields the
    // frontend renders, and which are secrets that get masked once saved
    // and never re-sent to the client in plaintext. Mirrors
    // config/suppliers.php's credential_fields exactly.
    'credential_fields' => [
        'razorpay' => [
            ['key' => 'key_id', 'label' => 'Key ID', 'secret' => false, 'placeholder' => 'rzp_live_...'],
            ['key' => 'key_secret', 'label' => 'Key secret', 'secret' => true],
        ],
        'payu' => [
            ['key' => 'merchant_key', 'label' => 'Merchant key', 'secret' => false],
            ['key' => 'salt', 'label' => 'Salt', 'secret' => true],
        ],
        'cashfree' => [
            ['key' => 'app_id', 'label' => 'App ID', 'secret' => false],
            ['key' => 'secret_key', 'label' => 'Secret key', 'secret' => true],
        ],
        'ccavenue' => [
            ['key' => 'merchant_id', 'label' => 'Merchant ID', 'secret' => false],
            ['key' => 'access_code', 'label' => 'Access code', 'secret' => false],
            ['key' => 'working_key', 'label' => 'Working key', 'secret' => true],
        ],
        'stripe' => [
            ['key' => 'publishable_key', 'label' => 'Publishable key', 'secret' => false, 'placeholder' => 'pk_live_...'],
            ['key' => 'secret_key', 'label' => 'Secret key', 'secret' => true, 'placeholder' => 'sk_live_...'],
        ],
    ],
];
