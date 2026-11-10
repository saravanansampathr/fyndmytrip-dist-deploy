<?php

return [
    // Redis-cached search results TTL — Master Brief §6: "short TTL (e.g.
    // 3-5 min) to reduce duplicate supplier calls and cost".
    'search_cache_ttl_seconds' => (int) env('SUPPLIER_SEARCH_CACHE_TTL', 240),

    // Circuit breaker: after this many consecutive failures within the
    // window, a supplier is skipped by the orchestrator for cooldown_seconds.
    'circuit_breaker' => [
        'failure_threshold' => (int) env('SUPPLIER_CB_FAILURE_THRESHOLD', 3),
        'cooldown_seconds' => (int) env('SUPPLIER_CB_COOLDOWN_SECONDS', 60),
    ],

    // Registered adapters keyed by supplier code — mirrors the `suppliers`
    // table's adapter_class column (Database Design Document §8.1). Real
    // TBO/TripJack/Akbar adapters register here once credentials exist.
    'adapters' => [
        'MOCKAIR' => \App\Domains\Suppliers\Adapters\MockFlightSupplierAdapter::class,
        'TBO' => \App\Domains\Suppliers\Adapters\TBOFlightSupplierAdapter::class,
        'TRIPJACK' => \App\Domains\Suppliers\Adapters\TripJackFlightSupplierAdapter::class,
        'AKBAR' => \App\Domains\Suppliers\Adapters\AkbarTravelsFlightSupplierAdapter::class,
        // Phase 3 (New Verticals & Real Booking Flows) — Bus, started first
        // since it already had the most groundwork. A deterministic fake
        // supplier until a real bus-operator API is wired in, same
        // stand-in role MOCKAIR plays for flights.
        'MOCKBUS' => \App\Domains\Suppliers\Adapters\MockBusSupplierAdapter::class,
        // Phase 3's second vertical — Hotel, same stand-in role MOCKBUS
        // plays for bus and MOCKAIR plays for flights.
        'MOCKHOTEL' => \App\Domains\Suppliers\Adapters\MockHotelSupplierAdapter::class,
        // Phase 3's third vertical — Cab.
        'MOCKCAB' => \App\Domains\Suppliers\Adapters\MockCabSupplierAdapter::class,
        // Phase 3's fourth vertical — Insurance.
        'MOCKINSURANCE' => \App\Domains\Suppliers\Adapters\MockInsuranceSupplierAdapter::class,
        // Phase 3's fifth vertical — Cruise.
        'MOCKCRUISE' => \App\Domains\Suppliers\Adapters\MockCruiseSupplierAdapter::class,
        // Phase 3's sixth and final item — Activities.
        'MOCKACTIVITY' => \App\Domains\Suppliers\Adapters\MockActivitySupplierAdapter::class,
    ],

    // Per-supplier credential field templates for the CRM's Supplier
    // Connections screen (SupplierConnectionController@index) — which text
    // fields to render, and which are secrets that should be masked once
    // saved and never re-sent to the frontend in plaintext.
    'credential_fields' => [
        'MOCKAIR' => [],
        'TBO' => [
            ['key' => 'client_id', 'label' => 'Client ID', 'secret' => false, 'placeholder' => 'ApiIntegrationNew'],
            ['key' => 'username', 'label' => 'Username', 'secret' => false],
            ['key' => 'password', 'label' => 'Password', 'secret' => true],
            ['key' => 'base_url', 'label' => 'API base URL (optional override)', 'secret' => false],
        ],
        'TRIPJACK' => [
            ['key' => 'api_key', 'label' => 'API key', 'secret' => true],
            ['key' => 'base_url', 'label' => 'API base URL (optional override)', 'secret' => false],
        ],
        'AKBAR' => [
            ['key' => 'api_key', 'label' => 'API key', 'secret' => true],
            ['key' => 'api_secret', 'label' => 'API secret', 'secret' => true],
            ['key' => 'base_url', 'label' => 'API base URL', 'secret' => false],
        ],
        'MOCKBUS' => [],
        'MOCKHOTEL' => [],
        'MOCKCAB' => [],
        'MOCKINSURANCE' => [],
        'MOCKCRUISE' => [],
        'MOCKACTIVITY' => [],
    ],

    // TBO / Tek Travels Flight API — see backend/docs/supplier-integration-notes.md
    // for the full research writeup. No self-service sandbox exists: TBO issues
    // a UserName/Password pair (their "test environment credentials") only after
    // agency registration and onboarding by apiintegrationteam@tbo.com. Until
    // then this adapter is wired and ready but unexercised against a live TBO
    // endpoint.
    'tbo' => [
        'base_url' => env('TBO_API_BASE_URL', 'http://api.tektravels.com/BookingEngineService_Air/AirService.svc/rest'),
        'client_id' => env('TBO_CLIENT_ID', 'ApiIntegrationNew'),
        'username' => env('TBO_USERNAME'),
        'password' => env('TBO_PASSWORD'),
    ],

    // TripJack Flight API — see backend/docs/supplier-integration-notes.md.
    // TripJack does run a dedicated test host (apitest.tripjack.com,
    // confirmed by browsing their live Postman workspace), but reaching it
    // still needs an `apikey` issued via TripJack's partner onboarding —
    // there's no public self-serve signup for one.
    'tripjack' => [
        'base_url' => env('TRIPJACK_API_BASE_URL', 'https://apitest.tripjack.com'),
        'api_key' => env('TRIPJACK_API_KEY'),
    ],
];
