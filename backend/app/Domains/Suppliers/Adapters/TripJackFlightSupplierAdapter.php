<?php

namespace App\Domains\Suppliers\Adapters;

use App\Domains\Suppliers\Contracts\SupplierAdapterInterface;
use App\Domains\Suppliers\DTOs\BookingConfirmResult;
use App\Domains\Suppliers\DTOs\BookingHoldResult;
use App\Domains\Suppliers\DTOs\FareRuleResult;
use App\Domains\Suppliers\DTOs\SearchRequest;
use App\Domains\Suppliers\DTOs\SearchResultItem;
use App\Domains\Suppliers\DTOs\SupplierBalance;
use App\Domains\Suppliers\Exceptions\SupplierException;
use Illuminate\Support\Facades\Http;

/**
 * Real adapter for TripJack's Flight API.
 *
 * Unlike TBOFlightSupplierAdapter (built from TBO's own published API docs),
 * TripJack's official documentation lives in a JavaScript-rendered Postman
 * workspace that plain web-fetch tooling can't read. This adapter was built
 * by browsing that workspace directly (via a connected Chrome session) once
 * that became available, which is a materially better source than the first
 * research pass — see docs/supplier-integration-notes.md for the full
 * writeup, including exactly what was confirmed vs. inferred and why.
 *
 * CONFIRMED directly from the live Postman workspace (request URLs, request
 * headers, and request bodies — these were read off real, populated request
 * definitions, not guessed):
 *   - Base host: https://apitest.tripjack.com — TripJack does operate a
 *     dedicated *test* environment (separate from production), which
 *     directly answers "does TripJack have a test API?": yes, but reaching
 *     it still requires an `apikey` issued via TripJack's partner
 *     onboarding — there is no anonymous self-serve signup for one.
 *   - POST /fms/v1/air-search-all  — search
 *   - POST /booking/review-price   — re-confirm price for one or more
 *     `priceId`s returned by search; this is also what TripJack uses to
 *     turn a `priceId` into a `bookingId` (their term for a held-not-yet-
 *     ticketed booking).
 *   - POST /booking/fare-rule      — fare rules for a `bookingId`
 *   - POST /booking/seat-map       — seat map for a `bookingId`
 *   - POST /oms/v1/air/book        — books AND tickets a `bookingId` in one
 *     call, given traveller info (+ optional GST/delivery info) — TripJack
 *     does not expose a separate "Ticket" step the way TBO does.
 *   - POST /oms/v1/booking-details — retrieve a booking by `bookingId`
 *   - Auth: a single `apikey` request header (no token exchange, unlike
 *     TBO's Authenticate step).
 *   - Response envelope: `{"status":{"success":bool,"httpStatus":int},
 *     "errors":[{"errCode","message","details"}], ...}` on failure — this
 *     was confirmed from a real captured error response ("Insufficient
 *     Balance"), which is also proof the workspace author was hitting a
 *     real backend, not a mock.
 *
 * NOT confirmed — inferred from TripJack's generally-documented response
 * shape, since no successful (non-error) search/book response body was
 * available to read in the browsed workspace. Treat these field paths as
 * best-effort until the first real response can be captured and this
 * adapter's parsing is checked against it:
 *   - Search results live under `searchResult.tripInfos.ONWARD[]`, each
 *     with a segment list `sI` and a `totalPriceList[]` of fare options,
 *     each fare option carrying the `id` used as `priceId` and a fare
 *     breakdown under `fd.ADULT.fC` (`NF` net fare, `TF` total fare).
 *   - `GetSupplierAccountBalance` maps to a "Get Balance" GET request that
 *     exists in the workspace, but its URL field was left empty by the
 *     workspace author, so the real path could not be confirmed — this
 *     method throws until that's verified directly with TripJack.
 *   - No cancellation endpoint was found for the public Flight API itself;
 *     an "amendment" flow exists in the workspace but lives under what
 *     looks like the workspace author's own reseller admin backend, not a
 *     confirmed TripJack endpoint — cancelBooking() throws rather than
 *     guess at it.
 *
 * A note on the API key: the browsed workspace had a populated, real-
 * looking `apikey` value on several requests (evidently the workspace
 * author's own TripJack **test** credential, left in a collection they
 * published publicly). That value was deliberately NOT copied into this
 * codebase or used to make any live call — using someone else's exposed
 * credential without authorization isn't something this integration does,
 * even for a test/sandbox key. It's mentioned in
 * docs/supplier-integration-notes.md so you're aware it's out there, but
 * this adapter only ever reads `credentials['api_key']` that FyndMyTrip's
 * own tenant configuration supplies.
 *
 * Same interface-packing convention as TBOFlightSupplierAdapter: TripJack's
 * `priceId` (from search) is carried as-is in $externalId, and its
 * `bookingId` (from review-price / book) is carried as-is in
 * $supplierBookingRef — both are already single strings, so no packing
 * trick is needed here (simpler than TBO's TraceId+ResultIndex pair).
 */
class TripJackFlightSupplierAdapter implements SupplierAdapterInterface
{
    public function code(): string
    {
        return 'TRIPJACK';
    }

    public function searchAvailability(SearchRequest $request, array $credentials): array
    {
        $routeInfos = [[
            'fromCityOrAirport' => ['code' => $request->origin],
            'toCityOrAirport' => ['code' => $request->destination],
            'travelDate' => $request->departureDate,
        ]];

        if ($request->returnDate) {
            $routeInfos[] = [
                'fromCityOrAirport' => ['code' => $request->destination],
                'toCityOrAirport' => ['code' => $request->origin],
                'travelDate' => $request->returnDate,
            ];
        }

        $response = $this->post($credentials, '/fms/v1/air-search-all', [
            'searchQuery' => [
                'cabinClass' => 'ECONOMY',
                'paxInfo' => [
                    'ADULT' => (string) $request->passengerCount,
                    'CHILD' => '0',
                    'INFANT' => '0',
                ],
                'routeInfos' => $routeInfos,
                'searchModifiers' => [
                    'isDirectFlight' => false,
                    'isConnectingFlight' => true,
                ],
            ],
        ]);

        $legs = $response['searchResult']['tripInfos']['ONWARD'] ?? [];

        $items = [];

        foreach ($legs as $leg) {
            $segments = $leg['sI'] ?? [];
            $firstSegment = $segments[0] ?? [];

            foreach ($leg['totalPriceList'] ?? [] as $priceOption) {
                $fare = $priceOption['fd']['ADULT']['fC'] ?? [];

                $items[] = new SearchResultItem(
                    supplierCode: $this->code(),
                    externalId: (string) ($priceOption['id'] ?? ''),
                    netAmount: number_format((float) ($fare['NF'] ?? $fare['TF'] ?? 0), 2, '.', ''),
                    currency: 'INR',
                    carrierOrProvider: $firstSegment['fD']['aI']['name'] ?? ($firstSegment['fD']['aI']['code'] ?? 'Unknown'),
                    fareBasis: $priceOption['fBC'] ?? 'N/A',
                    segments: $segments,
                    raw: $priceOption,
                );
            }
        }

        return $items;
    }

    public function priceCheck(string $externalId, array $credentials): FareRuleResult
    {
        $response = $this->post($credentials, '/booking/review-price', [
            'priceIds' => [$externalId],
        ]);

        $success = (bool) ($response['status']['success'] ?? false);
        $trip = $response['tripInfos'][0] ?? $response['searchResult']['tripInfos']['ONWARD'][0] ?? [];
        $fare = $trip['totalPriceList'][0]['fd']['ADULT']['fC'] ?? [];

        return new FareRuleResult(
            isStillAvailable: $success,
            confirmedNetAmount: number_format((float) ($fare['NF'] ?? $fare['TF'] ?? 0), 2, '.', ''),
            isRefundable: (bool) ($trip['isRefundable'] ?? false),
            rules: $response['fareRules'] ?? [],
            taxBreakup: $this->taxBreakup($trip['totalPriceList'][0]['fd']['ADULT']['afC']['TAF'] ?? [], $fare),
        );
    }

    public function holdBooking(string $externalId, array $passengers, array $credentials): BookingHoldResult
    {
        $review = $this->post($credentials, '/booking/review-price', [
            'priceIds' => [$externalId],
        ]);

        if (! ($review['status']['success'] ?? false)) {
            throw new SupplierException($this->code(), 'TripJack review-price did not confirm availability: '.json_encode($review['errors'] ?? $review));
        }

        $bookingId = $review['bookingId'] ?? $review['bookingID'] ?? null;

        if (! $bookingId) {
            throw new SupplierException($this->code(), 'TripJack review-price response did not include a bookingId.');
        }

        $travellerInfo = array_map(fn (array $p) => $this->toTripJackTraveller($p), $passengers);

        $response = $this->post($credentials, '/oms/v1/air/book', [
            'bookingId' => $bookingId,
            'travellerInfo' => $travellerInfo,
            'deliveryInfo' => [
                'emails' => array_values(array_filter(array_column($passengers, 'email'))) ?: ['noreply@fyndmytrip.com'],
                'contacts' => array_values(array_filter(array_column($passengers, 'contact_no'))) ?: ['0000000000'],
            ],
        ]);

        if (! ($response['status']['success'] ?? false)) {
            throw new SupplierException($this->code(), 'TripJack air/book call failed: '.json_encode($response['errors'] ?? $response));
        }

        $reviewTrip = $review['tripInfos'][0] ?? $review['searchResult']['tripInfos']['ONWARD'][0] ?? [];
        $fare = $reviewTrip['totalPriceList'][0]['fd']['ADULT']['fC'] ?? [];

        return new BookingHoldResult(
            supplierBookingRef: (string) ($response['bookingId'] ?? $bookingId),
            holdExpiresAt: new \DateTimeImmutable('+30 minutes'),
            netAmount: number_format((float) ($fare['NF'] ?? $fare['TF'] ?? 0), 2, '.', ''),
        );
    }

    public function confirmBooking(string $supplierBookingRef, array $credentials): BookingConfirmResult
    {
        $details = $this->retrieveBooking($supplierBookingRef, $credentials);

        $tickets = $details['travellerInfo'] ?? $details['passengerInfo'] ?? [];

        $ticketNumbers = array_values(array_filter(array_map(
            fn ($t) => $t['ticketNumber'] ?? $t['tN'] ?? null,
            is_array($tickets) ? $tickets : [],
        )));

        return new BookingConfirmResult(
            supplierBookingRef: $supplierBookingRef,
            isTicketed: $ticketNumbers !== [] || (($details['bookingStatus'] ?? null) === 'TICKETED'),
            ticketNumbers: $ticketNumbers,
        );
    }

    public function retrieveBooking(string $supplierBookingRef, array $credentials): array
    {
        return $this->post($credentials, '/oms/v1/booking-details', [
            'bookingId' => $supplierBookingRef,
        ]);
    }

    public function cancelBooking(string $supplierBookingRef, array $credentials): bool
    {
        throw new SupplierException($this->code(), 'No TripJack cancellation endpoint was confirmed during research — the only amendment/cancellation flow found ("Submit Amendment") lives under a third party\'s own reseller admin panel, not a verified TripJack endpoint. Confirm the real cancellation contract with TripJack directly before implementing this.');
    }

    public function getRefundStatus(string $supplierBookingRef, array $credentials): string
    {
        $details = $this->retrieveBooking($supplierBookingRef, $credentials);

        return $details['bookingStatus'] ?? $details['status']['success'] ?? 'unknown';
    }

    public function getSupplierAccountBalance(array $credentials): SupplierBalance
    {
        throw new SupplierException($this->code(), 'TripJack exposes a "Get Balance" endpoint, but its URL was not populated in the researched source — confirm the real path with TripJack before implementing this.');
    }

    protected function toTripJackTraveller(array $passenger): array
    {
        [$firstName, $lastName] = $this->splitName($passenger['name'] ?? 'Traveller');
        $isChild = ! empty($passenger['dob']) && now()->diffInYears($passenger['dob']) < 12;

        return [
            'ti' => match ($passenger['gender'] ?? 'unspecified') {
                'female' => 'Ms',
                default => 'Mr',
            },
            'fN' => $firstName,
            'lN' => $lastName,
            'pt' => $isChild ? 'CHILD' : 'ADULT',
        ];
    }

    protected function splitName(string $name): array
    {
        $parts = preg_split('/\s+/', trim($name), 2);

        return [$parts[0] ?? $name, $parts[1] ?? ''];
    }

    protected function baseUrl(array $credentials): string
    {
        return rtrim($credentials['base_url'] ?? config('suppliers.tripjack.base_url'), '/');
    }

    protected function post(array $credentials, string $path, array $payload): array
    {
        $url = $this->baseUrl($credentials).'/'.ltrim($path, '/');
        $apiKey = $credentials['api_key'] ?? config('suppliers.tripjack.api_key');

        if (! $apiKey) {
            throw new SupplierException($this->code(), 'No TripJack apikey configured — set TRIPJACK_API_KEY once TripJack issues one via partner onboarding.');
        }

        try {
            $response = Http::timeout(15)
                ->withHeaders(['apikey' => $apiKey])
                ->acceptJson()
                ->asJson()
                ->post($url, $payload);
        } catch (\Throwable $e) {
            throw new SupplierException($this->code(), "TripJack request to {$url} failed: {$e->getMessage()}");
        }

        if ($response->failed()) {
            throw new SupplierException($this->code(), "TripJack request to {$url} returned HTTP {$response->status()}: {$response->body()}");
        }

        return $response->json() ?? [];
    }

    /**
     * TripJack's afC.TAF per-tax amounts (AGST = airline GST, YQ, YR, OT,
     * ...), falling back to fC.TAF as one line.
     *
     * @return array<string, string>
     */
    protected function taxBreakup(array $taf, array $fare): array
    {
        $out = [];
        foreach ($taf as $code => $amount) {
            if ((float) $amount == 0.0) {
                continue;
            }
            $code = strtoupper((string) $code) === 'AGST' ? 'K3' : strtoupper((string) $code);
            $out[$code] = number_format((float) $amount + (float) ($out[$code] ?? 0), 2, '.', '');
        }
        if ($out === [] && (float) ($fare['TAF'] ?? 0) > 0) {
            $out['OT'] = number_format((float) $fare['TAF'], 2, '.', '');
        }

        return $out;
    }
}
