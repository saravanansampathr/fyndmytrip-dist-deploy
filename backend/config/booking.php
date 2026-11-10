<?php

return [
    // Used by RouteClassificationService to decide domestic vs
    // international: a segment is domestic only when both its origin and
    // destination airports resolve to this country code.
    'home_country' => env('BOOKING_HOME_COUNTRY', 'IN'),
];
