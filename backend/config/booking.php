<?php

return [
    // Used by RouteClassificationService to decide domestic vs
    // international: a segment is domestic only when both its origin and
    // destination airports resolve to this country code.
    'home_country' => env('BOOKING_HOME_COUNTRY', 'IN'),

    // GST: the platform's home state (two-digit GST state code) when the
    // seller's GSTIN isn't set. FyndMyTrip is based in Chennai, Tamil Nadu.
    'gst_home_state' => env('GST_HOME_STATE', '33'),

    // Customers pay in their own currency, converted from INR at the live
    // rate (open.er-api.com, cached 6h). Set e.g. "USD=0.012,AED=0.044" to
    // use fixed rates instead.
    'fx_fixed_rates' => env('FX_FIXED_RATES', ''),
];
