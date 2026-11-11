<?php

/*
| Default texts for every message the platform sends. Admins change them in
| Admin settings → Messages (stored in notification_templates); these are
| only the starting point and what a new event shows before anyone edits it.
| {{placeholders}} are filled from the booking.
*/

return [
    'placeholders' => [
        'customer_name' => 'Lead traveller or account name',
        'booking_code' => 'Booking ID, e.g. FMT-FL-000123',
        'service' => 'Flight, Hotel, Bus...',
        'trip' => 'Route, hotel or package name',
        'travel_date' => 'First travel date',
        'amount' => 'Total paid, with currency',
        'refund_amount' => 'Amount refunded, with currency',
        'ticket_numbers' => 'Ticket / PNR / voucher numbers',
        'status' => 'Booking status',
        'reason' => 'Reason (failures, cancellations)',
        'brand_name' => 'Your brand or the partner\'s brand',
        'support_email' => 'Support email',
        'support_phone' => 'Support phone',
        'manage_url' => 'Link to My Trips',
    ],

    'events' => [
        'booking_confirmed' => [
            'label' => 'Booking confirmed (paid)',
            'audience' => 'customer',
            'email' => [
                'subject' => 'Booking confirmed: {{booking_code}} · {{trip}}',
                'body' => "Dear {{customer_name}},\n\nThank you for booking with {{brand_name}}. Your {{service}} booking is confirmed.\n\nBooking ID: {{booking_code}}\nTrip: {{trip}}\nTravel date: {{travel_date}}\nAmount paid: {{amount}}\nTicket / reference: {{ticket_numbers}}\n\nYou can download your ticket and invoice from My Trips: {{manage_url}}\n\nNeed help? Email {{support_email}} or call {{support_phone}}.\n\n{{brand_name}}",
            ],
            'sms' => ['body' => '{{brand_name}}: {{service}} booking {{booking_code}} confirmed for {{travel_date}}. Ref {{ticket_numbers}}. Amount {{amount}}.'],
            'whatsapp' => ['body' => "Hi {{customer_name}}, your {{service}} booking *{{booking_code}}* ({{trip}}) on {{travel_date}} is confirmed. Reference: {{ticket_numbers}}. Amount paid: {{amount}}. – {{brand_name}}"],
        ],
        'booking_cancelled' => [
            'label' => 'Booking cancelled',
            'audience' => 'customer',
            'email' => [
                'subject' => 'Booking cancelled: {{booking_code}}',
                'body' => "Dear {{customer_name}},\n\nYour {{service}} booking {{booking_code}} ({{trip}}) has been cancelled.\n\nAny refund due will be processed as per the cancellation policy, and we will email you when it is done.\n\nNeed help? Email {{support_email}} or call {{support_phone}}.\n\n{{brand_name}}",
            ],
            'sms' => ['body' => '{{brand_name}}: booking {{booking_code}} has been cancelled. Any refund due will follow as per policy.'],
            'whatsapp' => ['body' => 'Hi {{customer_name}}, your booking *{{booking_code}}* ({{trip}}) has been cancelled. Any refund due will follow as per the cancellation policy. – {{brand_name}}'],
        ],
        'refund_completed' => [
            'label' => 'Refund completed',
            'audience' => 'customer',
            'email' => [
                'subject' => 'Refund processed: {{booking_code}}',
                'body' => "Dear {{customer_name}},\n\nWe have refunded {{refund_amount}} for booking {{booking_code}} ({{trip}}). Bank and card refunds can take 5 to 7 working days to show.\n\n{{brand_name}}",
            ],
            'sms' => ['body' => '{{brand_name}}: refund of {{refund_amount}} for booking {{booking_code}} has been processed.'],
            'whatsapp' => ['body' => 'Hi {{customer_name}}, we have refunded *{{refund_amount}}* for booking {{booking_code}}. Bank and card refunds can take 5-7 working days. – {{brand_name}}'],
        ],
        'booking_failed' => [
            'label' => 'Booking could not be completed',
            'audience' => 'customer',
            'email' => [
                'subject' => 'We could not complete booking {{booking_code}}',
                'body' => "Dear {{customer_name}},\n\nWe could not complete your {{service}} booking {{booking_code}} ({{trip}}). If any amount was taken, our team is already looking at it and will confirm the booking or refund you in full.\n\nNeed help? Email {{support_email}} or call {{support_phone}}.\n\n{{brand_name}}",
            ],
            'sms' => ['body' => '{{brand_name}}: booking {{booking_code}} could not be completed. If you were charged, we will confirm or refund in full.'],
            'whatsapp' => ['body' => 'Hi {{customer_name}}, we could not complete booking *{{booking_code}}*. If you were charged, our team will confirm it or refund you in full. – {{brand_name}}'],
        ],
        'staff_booking_attention' => [
            'label' => 'Staff alert: paid booking needs attention',
            'audience' => 'staff',
            'email' => [
                'subject' => 'Action needed: {{booking_code}} paid but not confirmed',
                'body' => "Booking {{booking_code}} ({{service}}, {{trip}}) was paid ({{amount}}) but could not be confirmed with the supplier.\n\nReason: {{reason}}\n\nOpen Admin settings → Failed bookings to issue it by hand or refund the customer.",
            ],
        ],
    ],
];
