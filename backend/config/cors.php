<?php

return [
    // The frontend (demo.fyndmytrip.in) and the API (api-demo.fyndmytrip.in)
    // are deliberately separate subdomains/origins in this deployment, so
    // CORS has to be configured explicitly — Laravel does not allow
    // cross-origin API calls by default. Auth here uses a Sanctum personal
    // access token sent as a Bearer header (see frontend/src/api/client.ts),
    // not Sanctum's SPA cookie flow, so credentials support isn't required,
    // but is left on since EnsureFrontendRequestsAreStateful is still
    // registered globally in bootstrap/app.php.
    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_filter(explode(',', env('CORS_ALLOWED_ORIGINS', 'http://localhost:5173'))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,
];
