<?php

return [
    // Used by RouteClassificationService to decide domestic vs
    // international: a segment is domestic only when both its origin and
    // destination airports resolve to this country code.
    'home_country' => env('BOOKING_HOME_COUNTRY', 'IN'),

    // GST: the platform's home state (two-digit GST state code) when the
    // seller's GSTIN isn't set. FyndMyTrip is based in Chennai, Tamil Nadu.
    'gst_home_state' => env('GST_HOME_STATE', '33'),
];
