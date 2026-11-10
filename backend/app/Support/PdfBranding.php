<?php

namespace App\Support;

use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The FyndMyTrip platform logo, embedded into every generated PDF (holiday
 * itinerary, flight e-ticket, hotel voucher, bus ticket) — "need logo in
 * all PDF". Bundled with the app at resources/images/logo.png (a fixed,
 * static asset, not user data), so this reads it once from local disk and
 * hands templates a base64 data: URI — the same reliable pattern already
 * used for the day-by-day itinerary photos, and deliberately NOT a remote
 * fetch: a local asset has no network/host quirks to work around.
 *
 * Two variants: the full-color logo (resources/images/logo.png) for pages
 * with a light/white surface, and an all-white silhouette
 * (resources/images/logo-white.png, both transparent PNGs) for use directly
 * on a dark surface — the holiday itinerary's cover photo — without needing
 * an opaque backing box behind it.
 *
 * Platform Roadmap Phase 5 — "one shared PDF ticket/voucher template
 * partial... instead of styling each PDF controller separately. Logo,
 * social links, QR target and app-store URLs live in one CRM/branding
 * settings screen, not hardcoded." Every booking-PDF controller
 * (Flight/Bus/Hotel/Cab/Insurance/Cruise/Activity + the holiday itinerary)
 * used to always burn in this platform's own logo, even for a white-label
 * tenant with its own logo_url already sitting in WhiteLabelController's
 * profile — resolveLogo() is the fix: a tenant's own logo wins whenever one
 * is set, this platform's stays the fallback. socialLinks()/appLinks()/
 * qrDataUri() are the rest of that same branding strip (see
 * resources/views/pdf/partials/brand-footer.blade.php), each with the same
 * "never show a fake/placeholder value" rule already used for
 * business_address/support_phone/support_email elsewhere in these PDFs:
 * unset stays simply absent from the printed strip.
 */
class PdfBranding
{
    protected static array $cache = [];

    /** Sentinel stored in cache for a failed remote fetch — see embedRemoteImage(). */
    private const EMBED_FAILED = '__EMBED_FAILED__';

    public static function logoDataUri(): ?string
    {
        return self::read('logo.png');
    }

    public static function logoWhiteDataUri(): ?string
    {
        return self::read('logo-white.png');
    }

    /**
     * A tenant's own white-label logo (WhiteLabelController's logo_url)
     * when it has one and the fetch succeeds; this platform's own bundled
     * logo otherwise — so an unbranded/master booking, or a fetch failure,
     * degrades to exactly what every PDF showed before this existed.
     */
    public static function resolveLogo(?Tenant $tenant, bool $preferWhite = false): ?string
    {
        if ($tenant?->logo_url) {
            $embedded = self::embedRemoteImage($tenant->logo_url, 'pdf-branding-logo:');

            if ($embedded) {
                return $embedded;
            }
        }

        return $preferWhite ? self::logoWhiteDataUri() : self::logoDataUri();
    }

    /** @return array<string, string> only the handles the tenant (or platform) actually set */
    public static function socialLinks(?Tenant $tenant): array
    {
        return array_filter($tenant?->config['social_links'] ?? []);
    }

    /** @return array{play_store_url: ?string, app_store_url: ?string} tenant's own app, falling back to the platform's */
    public static function appLinks(?Tenant $tenant): array
    {
        $tenantLinks = $tenant?->config['app_links'] ?? [];

        return [
            'play_store_url' => $tenantLinks['play_store_url'] ?? config('services.app_links.play_store_url'),
            'app_store_url' => $tenantLinks['app_store_url'] ?? config('services.app_links.app_store_url'),
        ];
    }

    /**
     * What a ticket's QR code points at: the tenant's own live site — its
     * custom_domain if verified/mapped, else its platform subdomain — or,
     * for a booking with no tenant (or a tenant that hasn't mapped either
     * one yet), the platform's own configured site. Null when none of
     * those exist, so the QR block is simply left off (see the partial).
     */
    public static function siteUrl(?Tenant $tenant): ?string
    {
        if ($tenant?->custom_domain) {
            return 'https://'.$tenant->custom_domain;
        }

        if ($tenant?->subdomain) {
            return 'https://'.$tenant->subdomain.config('tenancy.default_hosting_suffix', '.fyndmytrip.com');
        }

        return config('services.platform.site_url');
    }

    /**
     * A scannable QR PNG for siteUrl(), fetched from a public no-auth QR
     * generation API and embedded as a base64 data: URI — same reasoning
     * and the same kill switch (services.pdf_images.embed_remote) as
     * HolidayPackagePdfController's remote image embedding: dompdf never
     * makes the network call itself, this app does, once per unique URL,
     * cached, with a silent graceful skip on any failure.
     */
    public static function qrDataUri(?Tenant $tenant): ?string
    {
        $target = self::siteUrl($tenant);

        if (! $target) {
            return null;
        }

        $qrApiUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=180x180&data='.urlencode($target);

        return self::embedRemoteImage($qrApiUrl, 'pdf-branding-qr:');
    }

    protected static function read(string $filename): ?string
    {
        if (array_key_exists($filename, self::$cache)) {
            return self::$cache[$filename];
        }

        $path = resource_path('images/'.$filename);

        if (! is_file($path)) {
            return self::$cache[$filename] = null;
        }

        try {
            $bytes = file_get_contents($path);

            if ($bytes === false) {
                return self::$cache[$filename] = null;
            }

            return self::$cache[$filename] = 'data:image/png;base64,'.base64_encode($bytes);
        } catch (\Throwable $e) {
            return self::$cache[$filename] = null;
        }
    }

    /**
     * Shared single-URL version of HolidayPackagePdfController::embedImages()
     * — same kill switch, same cache-with-sentinel-on-failure shape, its
     * own cache key prefix so a failed logo fetch and a failed QR fetch
     * never collide with each other or with the holiday-image cache.
     */
    protected static function embedRemoteImage(string $url, string $cachePrefix): ?string
    {
        if (! config('services.pdf_images.embed_remote')) {
            return null;
        }

        $cacheKey = $cachePrefix.md5($url);
        $cached = Cache::get($cacheKey, '__MISS__');

        if ($cached !== '__MISS__') {
            return $cached === self::EMBED_FAILED ? null : $cached;
        }

        $embedded = null;

        try {
            $response = Http::timeout(4)->connectTimeout(2)->get($url);

            if ($response->successful()) {
                $body = $response->body();
                $contentType = $response->header('Content-Type') ?: 'image/png';

                if (strlen($body) <= 2 * 1024 * 1024 && str_starts_with($contentType, 'image/')) {
                    $embedded = 'data:'.$contentType.';base64,'.base64_encode($body);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('pdf-branding: failed to embed remote image', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
        }

        Cache::put(
            $cacheKey,
            $embedded ?? self::EMBED_FAILED,
            $embedded ? now()->addDay() : now()->addMinutes(10)
        );

        return $embedded;
    }
}
