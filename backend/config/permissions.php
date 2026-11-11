<?php

// Every right the code checks, with the wording shown on Admin settings →
// Roles. Adding a right here makes it appear on that screen.
return [
    'Bookings' => [
        'booking.view' => 'See bookings',
        'booking.create' => 'Make bookings',
        'booking.cancel' => 'Cancel bookings',
        'booking.refund' => 'Approve refunds',
        'booking.manage' => 'Change booking status, issue tickets by hand',
        'group_booking.request' => 'Ask for group flight fares',
        'group_booking.manage' => 'Quote group flight requests',
        'hotel_group_booking.request' => 'Ask for group hotel rates',
        'hotel_group_booking.manage' => 'Quote group hotel requests',
    ],
    'Money' => [
        'wallet.view' => 'See wallet and statements',
        'wallet.topup' => 'Top up the wallet',
        'wallet.credit.manage' => 'Give credit limits',
        'payments.offline.submit' => 'Send bank transfer / cheque deposits',
        'payments.offline.review' => 'Approve bank transfer / cheque deposits',
        'pricing.view_margin' => 'See margins and supplier cost',
    ],
    'Pricing and rules' => [
        'markup.view' => 'See margin rules',
        'markup.manage' => 'Change margin rules',
        'coupon.manage' => 'Manage coupons',
        'policy.manage' => 'Manage cancellation policies',
    ],
    'Accounts and setup' => [
        'tenant.view' => 'See sub-accounts',
        'tenant.create' => 'Create sub-accounts',
        'tenant.manage_users' => 'Add and edit users',
        'supplier.configure' => 'Supplier connections',
        'payment_gateway.configure' => 'Payment gateways',
        'notification_gateway.configure' => 'SMS, WhatsApp and email providers',
    ],
    'Support' => [
        'ticket.view' => 'See support tickets',
        'ticket.create' => 'Raise support tickets',
        'ticket.manage' => 'Answer and assign support tickets',
    ],
];
