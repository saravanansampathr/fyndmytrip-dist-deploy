<?php

namespace App\Http\Controllers\Api;

use App\Domains\Holiday\Models\HolidayPackage;
use App\Http\Controllers\Controller;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Generates a branded, shareable PDF itinerary for a holiday package — the
 * "downloadable PDF with good design so that we can share and anyone can
 * share from portal or back end" backlog item. Deliberately public (no
 * auth:sanctum) like HolidayPackageSearchController's guest-browsing routes:
 * a link only useful for sharing is useless if the recipient needs to log
 * in first, so anyone with the link (an agent's customer, a prospect on
 * WhatsApp) can open or download it directly.
 *
 * Uses the same visibility rule as the search controller (platform-wide,
 * or explicitly opted into network/direct-site sharing) so this never
 * exposes a tenant's private/unlisted package to an outsider who merely
 * guesses a slug.
 */
class HolidayPackagePdfController extends Controller
{
    /** Sentinel stored in cache for a failed image fetch — see embedImages(). */
    private const EMBED_FAILED = '__EMBED_FAILED__';

    public function download(Request $request, string $slug)
    {
        $user = $request->user();

        $package = HolidayPackage::query()
            ->where('slug', $slug)
            ->where('status', HolidayPackage::STATUS_ACTIVE)
            ->where(function ($q) use ($user) {
                if (! $user) {
                    $q->whereNull('tenant_id')
                        ->orWhere('share_on_platform_site', true)
                        ->orWhere('share_with_network', true);

                    return;
                }

                $q->whereNull('tenant_id')
                    ->orWhere('tenant_id', $user->tenant_id)
                    ->orWhere('share_with_network', true)
                    ->when(is_null($user->tenant_id), fn ($q2) => $q2->orWhere('share_on_platform_site', true));
            })
            ->with(['itineraryDays', 'priceTiers', 'tenant', 'media'])
            ->firstOrFail();
        app(HolidayPackageSearchController::class)->presentPrices($request, $package, withTiers: true);

        $occupancyLabels = [
            'adult_sharing' => 'Adult (sharing)',
            'adult_single' => 'Adult (single)',
            'child_with_bed' => 'Child (with bed)',
            'child_without_bed' => 'Child (without bed)',
            'infant' => 'Infant',
        ];

        // No per-day image field on the itinerary day itself — cycle
        // through the package's own gallery (falling back to the cover
        // image) so each day still gets "proper image and itinerary" per
        // the backlog request, without inventing a new schema column.
        $galleryImages = $package->media
            ->where('media_type', \App\Domains\Holiday\Models\HolidayPackageMedia::TYPE_IMAGE)
            ->pluck('url')
            ->filter()
            ->values();
        if ($galleryImages->isEmpty() && $package->cover_image_url) {
            $galleryImages = collect([$package->cover_image_url]);
        }

        // dompdf fetches <img src="https://..."> itself only when remote
        // URLs are explicitly enabled, and even then that fetch happens from
        // wherever this app is hosted — on a shared cPanel box, outbound
        // HTTP from PHP can be flaky, or the image host briefly unreachable.
        // Fetching each *unique* image ourselves (short timeout, graceful
        // skip on any failure) and handing dompdf an inline base64 data: URI
        // sidesteps that — dompdf never makes a network call for these
        // images. (This was *not* the cause of the 503 that used to happen
        // here — see the note on the `download` branch below for what
        // actually was. Direct testing proved a request with zero outbound
        // calls and a request that fetches these images over plain
        // Http::get() both succeed identically once that real cause was
        // fixed.) config('services.pdf_images.embed_remote') is a kill
        // switch (config/services.php) in case this host's outbound HTTP
        // ever turns out to be the problem after all; `?no_images=1` forces
        // images off for testing regardless of that flag.
        if ($request->boolean('no_images') || ! config('services.pdf_images.embed_remote')) {
            $embeddedByUrl = [];
            $embeddedCover = null;
        } else {
            $urlsToEmbed = $galleryImages->unique()->values();
            if ($package->cover_image_url) {
                $urlsToEmbed->push($package->cover_image_url);
            }
            $embeddedByUrl = $this->embedImages($urlsToEmbed->unique()->values()->all());
            $embeddedCover = $package->cover_image_url ? ($embeddedByUrl[$package->cover_image_url] ?? null) : null;
        }

        $dayImages = [];
        if ($galleryImages->isNotEmpty()) {
            foreach ($package->itineraryDays as $i => $day) {
                $url = $galleryImages[$i % $galleryImages->count()];
                $embedded = $embeddedByUrl[$url] ?? null;
                if ($embedded) {
                    $dayImages[$day->id] = $embedded;
                }
            }
        }

        // Inclusion icon strip ("HOTEL / MEAL / TRANSFER / SIGHTSEEING") —
        // derived from whichever of these categories the package's own
        // inclusions actually mention, rather than a fixed hardcoded set.
        $inclusionCatalog = [
            'Hotel' => ['hotel', 'accommodation', 'stay'],
            'Meals' => ['meal', 'breakfast', 'lunch', 'dinner'],
            'Transfers' => ['transfer', 'transport', 'coach', 'bus', 'car'],
            'Sightseeing' => ['sightseeing', 'tour', 'excursion', 'city tour'],
            'Flights' => ['flight', 'airfare', 'air ticket'],
            'Visa' => ['visa'],
            'Insurance' => ['insurance'],
        ];
        $inclusionText = strtolower(implode(' ', $package->inclusions ?? []));
        $inclusionIcons = [];
        foreach ($inclusionCatalog as $label => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($inclusionText, $keyword)) {
                    $inclusionIcons[] = $label;
                    break;
                }
            }
        }

        $pdf = Pdf::loadView('pdf.holiday-itinerary', [
            'package' => $package,
            'coverImage' => $embeddedCover,
            'brandName' => $package->tenant?->name ?? 'FyndMyTrip',
            'logo' => \App\Support\PdfBranding::resolveLogo($package->tenant, preferWhite: true),
            'socialLinks' => \App\Support\PdfBranding::socialLinks($package->tenant),
            'qrDataUri' => \App\Support\PdfBranding::qrDataUri($package->tenant),
            'playStoreUrl' => \App\Support\PdfBranding::appLinks($package->tenant)['play_store_url'],
            'appStoreUrl' => \App\Support\PdfBranding::appLinks($package->tenant)['app_store_url'],
            'occupancyLabels' => $occupancyLabels,
            'dayImages' => $dayImages,
            'inclusionIcons' => $inclusionIcons,
            'generatedAt' => now()->format('d M Y'),
        ])->setPaper('a4');

        $filename = $package->slug.'-itinerary.pdf';

        // Always an inline response, never $pdf->download(). Direct testing
        // found the real cause of this endpoint's 503: it tracked the
        // `download` flag itself, not image embedding — `?no_images=1` alone
        // (no images, inline) succeeded every time, while the exact same
        // request plus `download=1` (still zero images) still 503'd. The two
        // response methods render identically; the only difference is the
        // Content-Disposition header (`attachment` vs `inline`) and an added
        // Content-Length. Something at the host level intervenes on the
        // attachment-disposition response specifically (most likely a
        // security/AV module scanning "downloadable" responses, common on
        // shared hosting) — invisibly to Laravel's log, dompdf, and any log
        // this account can see, so no amount of try/catch here can work
        // around it. The fix is to never ask the server for that response at
        // all: this always streams inline, and the frontend fetches it as a
        // blob and triggers the actual file save client-side (see
        // downloadHolidayItinerary() in the frontend's api/holidayPackages.ts)
        // — the exact same pattern already used for flight e-tickets and
        // other booking PDFs in this app, just extended to this one page
        // that hadn't adopted it yet. The `download` query param is now a
        // no-op, kept only so any old links out in the wild don't 404.
        return $pdf->stream($filename);
    }

    /**
     * Fetches a set of image URLs *concurrently* (Http::pool) and returns a
     * [url => data-URI-or-null] map. Never throws — a missing/broken image
     * should mean "no image for this slot", not a 500 (or, with a slow host
     * and enough images fetched one-at-a-time, a 503 from the whole request
     * running past the server's execution-time limit). Each result is
     * capped at 4MB and cached (success for a day, failure for 10 minutes —
     * long enough to stop a briefly-flaky host being retried on every
     * single PDF download, short enough that a fixed image recovers soon).
     */
    protected function embedImages(array $urls): array
    {
        $result = [];
        $toFetch = [];

        foreach ($urls as $url) {
            if (! $url) {
                continue;
            }

            if (str_starts_with($url, 'data:')) {
                $result[$url] = $url;

                continue;
            }

            $cacheKey = 'holiday-pdf-image:'.md5($url);
            $cached = Cache::get($cacheKey, '__MISS__');
            if ($cached !== '__MISS__') {
                // Cache::get() can't tell "stored null" apart from "not
                // found" (the underlying repository treats a null value as
                // a miss and falls through to the default), so a cached
                // failure is stored as the sentinel below, never as null.
                $result[$url] = $cached === self::EMBED_FAILED ? null : $cached;

                continue;
            }

            $toFetch[] = $url;
        }

        if (empty($toFetch)) {
            return $result;
        }

        // Fetched one at a time with a plain Http::get() rather than
        // Http::pool(): with normally 0-1 images per package the concurrency
        // wasn't buying much, and a single easy-handle request per image
        // keeps this simple. (An earlier version of this comment blamed
        // Http::pool() for this endpoint's 503 — that was wrong; see the
        // `download` branch in download() above for the real cause and fix.)
        foreach ($toFetch as $url) {
            $embedded = null;

            try {
                $response = Http::timeout(4)->connectTimeout(2)->get($url);
                if ($response->successful()) {
                    $body = $response->body();
                    $contentType = $response->header('Content-Type') ?: 'image/jpeg';
                    if (strlen($body) <= 4 * 1024 * 1024 && str_starts_with($contentType, 'image/')) {
                        $embedded = 'data:'.$contentType.';base64,'.base64_encode($body);
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('holiday-itinerary-pdf: failed to embed image', [
                    'url' => $url,
                    'error' => $e->getMessage(),
                ]);
            }

            Cache::put(
                'holiday-pdf-image:'.md5($url),
                $embedded ?? self::EMBED_FAILED,
                $embedded ? now()->addDay() : now()->addMinutes(10)
            );
            $result[$url] = $embedded;
        }

        return $result;
    }
}
