<?php

return [
    // Platform-default payment gateway. Per-tenant "Bring Your Own Gateway"
    // credentials live encrypted in payment_gateway_configs, not here.
    'payment_default' => [
        'gateway' => env('PAYMENT_DEFAULT_GATEWAY', 'razorpay'),
        'razorpay' => [
            'key' => env('RAZORPAY_KEY'),
            'secret' => env('RAZORPAY_SECRET'),
        ],
    ],

    // Platform-default SMS / WhatsApp providers. Per-tenant overrides live
    // encrypted in notification_gateway_configs.
    'sms_default' => [
        'provider' => env('SMS_DEFAULT_PROVIDER', 'msg91'),
    ],
    'whatsapp_default' => [
        'provider' => env('WHATSAPP_DEFAULT_PROVIDER', 'gupshup'),
    ],

    // AI Gateway microservice (Python FastAPI) — see System Architecture Document §5.
    // That document's architecture assumed infrastructure (a separately
    // deployed service, Redis/Horizon) this tenant doesn't have on shared
    // cPanel hosting, so it was never built — this entry is an unused
    // placeholder kept only in case a future deployment target makes it
    // viable. The storefront chat widget's actual "smart reply" upgrade
    // lives below, as a direct call from this app — see 'assistant'.
    'ai_gateway' => [
        'base_url' => env('AI_GATEWAY_BASE_URL', 'http://localhost:8100'),
        'token' => env('AI_GATEWAY_TOKEN'),
    ],

    // Storefront chat widget's optional free-tier upgrade — see
    // AssistantController. Unset by default: the widget keeps working on
    // its free, local rule-based replies alone until a real key (from
    // Google AI Studio, no card required for the free tier) is added here
    // and `php artisan optimize:clear` is run. Never share this key with
    // the frontend — it's used server-side only.
    'assistant' => [
        'gemini_key' => env('GEMINI_API_KEY'),
    ],

    // Holiday itinerary PDF: whether to fetch each package's remote gallery/
    // cover image over HTTP and embed it in the PDF. Defaults ON — direct
    // testing showed outbound HTTP from PHP was never the problem behind
    // this PDF's 503 (see HolidayPackagePdfController::download()'s
    // comments for what actually was). Kept as a kill switch in case this
    // host's outbound HTTP genuinely misbehaves in the future: set
    // PDF_EMBED_REMOTE_IMAGES=false in .env and run
    // `php artisan optimize:clear` to fall back to images-off without a
    // redeploy.
    'pdf_images' => [
        'embed_remote' => env('PDF_EMBED_REMOTE_IMAGES', true),
    ],

    // Platform Roadmap Phase 5: the shared PDF ticket/voucher branding
    // strip (see App\Support\PdfBranding + resources/views/pdf/partials/
    // brand-footer.blade.php) — Google Play / App Store links and the site
    // a ticket's QR code points to, when a tenant hasn't set its own in the
    // white-label profile (WhiteLabelController). All optional: unset,
    // that part of the strip is simply left out — never a fake/placeholder
    // link, same policy as everywhere else branding is shown.
    'app_links' => [
        'play_store_url' => env('PLATFORM_PLAY_STORE_URL'),
        'app_store_url' => env('PLATFORM_APP_STORE_URL'),
    ],
    'platform' => [
        // Fallback QR target for a booking with no tenant, or a tenant that
        // hasn't mapped a subdomain/custom_domain yet.
        'site_url' => env('PLATFORM_SITE_URL'),
    ],
];
