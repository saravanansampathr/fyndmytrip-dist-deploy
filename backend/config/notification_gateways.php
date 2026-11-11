<?php

return [
    // Which providers the CRM's Notification gateway screen offers per
    // channel (NotificationGatewayConfig::CHANNELS) — a free-form 'provider'
    // string is still what's actually stored, so a tenant with a custom/
    // unlisted provider can still type it in.
    'providers' => [
        'sms' => ['msg91', 'gupshup', 'twilio'],
        'whatsapp' => ['gupshup', 'twilio', 'interakt'],
        'email' => ['ses', 'sendgrid', 'smtp'],
    ],

    // Per-provider credential field templates, same masking convention as
    // config/payment_gateways.php and config/suppliers.php.
    'credential_fields' => [
        'msg91' => [
            ['key' => 'auth_key', 'label' => 'Auth key', 'secret' => true],
        ],
        'gupshup' => [
            ['key' => 'api_key', 'label' => 'API key', 'secret' => true],
            ['key' => 'source_number', 'label' => 'WhatsApp source number', 'secret' => false],
        ],
        'twilio' => [
            ['key' => 'account_sid', 'label' => 'Account SID', 'secret' => false],
            ['key' => 'auth_token', 'label' => 'Auth token', 'secret' => true],
            ['key' => 'from_number', 'label' => 'From number / WhatsApp sender', 'secret' => false],
        ],
        'interakt' => [
            ['key' => 'api_key', 'label' => 'API key', 'secret' => true],
        ],
        'ses' => [
            ['key' => 'access_key_id', 'label' => 'AWS access key ID', 'secret' => false],
            ['key' => 'secret_access_key', 'label' => 'AWS secret access key', 'secret' => true],
            ['key' => 'region', 'label' => 'AWS region', 'secret' => false, 'placeholder' => 'ap-south-1'],
            ['key' => 'from_email', 'label' => 'From email address', 'secret' => false],
        ],
        'sendgrid' => [
            ['key' => 'api_key', 'label' => 'API key', 'secret' => true],
            ['key' => 'from_email', 'label' => 'From email address', 'secret' => false],
        ],
        'smtp' => [
            ['key' => 'host', 'label' => 'SMTP host', 'secret' => false],
            ['key' => 'port', 'label' => 'Port', 'secret' => false, 'placeholder' => '587'],
            ['key' => 'username', 'label' => 'Username', 'secret' => false],
            ['key' => 'password', 'label' => 'Password', 'secret' => true],
            ['key' => 'from_email', 'label' => 'From email address', 'secret' => false],
        ],
    ],
];
