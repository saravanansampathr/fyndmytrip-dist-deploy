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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Real adapter for TBO (Travel Boutique Online / Tek Travels)'s Flight API,
 * built directly from TBO's own public API documentation at
 * https://apidoc.tektravels.com/flight/ (Authenticate, Search, FareQuote,
 * Book, Ticket, GetBookingDetails, SendChangeRequest) — see
 * docs/supplier-integration-notes.md in this repo for the full research
 * writeup, including exactly which pages this was verified against.
 *
 * IMPORTANT — this adapter has NOT been exercised against a live TBO
 * environment. TBO does not publish self-service sandbox credentials: a
 * UserName/Password pair (their "test environment credentials") is issued
 * only after registering as an agency and being onboarded by TBO's API
 * integration team (apiintegrationteam@tbo.com). The request/response
 * shapes below are transcribed from TBO's own documentation as accurately
 * as could be extracted, but until real credentials are plugged in and a
 * first live call is made, treat this as "ready to test", not "tested".
 *
 * Two real gaps to close once credentials exist and this gets its first
 * live run — both flagged inline below with TODO:
 *   1. TBO's Book call requires passenger Title/Nationality/ContactNo/
 *      Email/Address fields that booking_passengers does not currently
 *      capture (see Database Design Document §4.2) — the fields it lacks
 *      are filled with conservative defaults for now.
 *   2. TBO's TraceId (from Search) must be replayed on FareQuote/Book/
 *      Ticket, and ResultIndex identifies which search result was chosen.
 *      SupplierAdapterInterface's methods only carry a single $externalId
 *      string, so this adapter packs both into one value as
 *      "{TraceId}|{ResultIndex}" and unpacks it internally — every other
 *      adapter (including MockFlightSupplierAdapter) is unaffected since
 *      that packing is a private implementation detail of this class.
 */
class TBOFlightSupplierAdapter implements SupplierAdapterInterface
{
    public function code(): string
    {
        return 'TBO';
    }

    public function searchAvailability(SearchRequest $request, array $credentials): array
    {
        $token = $this->authenticate($credentials);
        $baseUrl = $this->baseUrl($credentials);

        $segments = [[
            'Origin' => $request->origin,
            'Destination' => $request->destination,
            'FlightCabinClass' => 2, // Economy
            'PreferredDepartureTime' => $request->departureDate.'T00:00:00',
            'PreferredArrivalTime' => $request->departureDate.'T00:00:00',
        ]];

        if ($request->returnDate) {
            $segments[] = [
                'Origin' => $request->destination,
                'Destination' => $request->origin,
                'FlightCabinClass' => 2,
                'PreferredDepartureTime' => $request->returnDate.'T00:00:00',
                'PreferredArrivalTime' => $request->returnDate.'T00:00:00',
            ];
        }

        $response = $this->post($baseUrl.'/Search', [
            'EndUserIp' => $this->endUserIp($credentials),
            'TokenId' => $token,
            'AdultCount' => $request->passengerCount,
            'ChildCount' => 0,
            'InfantCount' => 0,
            'JourneyType' => $request->returnDate ? 2 : 1,
            'Segments' => $segments,
        ]);

        $traceId = $response['Response']['TraceId'] ?? $response['TraceId'] ?? null;
        $results = $response['Response']['Results'][0] ?? $response['Results'][0] ?? [];

        if (! $traceId) {
            throw new SupplierException($this->code(), 'TBO Search response did not include a TraceId.');
        }

        return array_map(function (array $result) use ($traceId) {
            $fare = $result['Fare'] ?? [];
            $segment = $result['Segments'][0][0] ?? [];

            return new SearchResultItem(
                supplierCode: $this->code(),
                externalId: $traceId.'|'.($result['ResultIndex'] ?? ''),
                netAmount: number_format((float) ($fare['OfferedFare'] ?? $fare['PublishedFare'] ?? 0), 2, '.', ''),
                currency: $fare['Currency'] ?? 'INR',
                carrierOrProvider: $segment['Airline']['AirlineName'] ?? ($segment['Airline']['AirlineCode'] ?? 'Unknown'),
                fareBasis: $segment['FareClass'] ?? $segment['Airline']['FareClass'] ?? 'N/A',
                segments: $result['Segments'][0] ?? [],
                raw: $result,
            );
        }, $results);
    }

    public function priceCheck(string $externalId, array $credentials): FareRuleResult
    {
        [$traceId, $resultIndex] = $this->splitExternalId($externalId);
        $token = $this->authenticate($credentials);

        $response = $this->post($this->baseUrl($credentials).'/FareQuote', [
            'EndUserIp' => $this->endUserIp($credentials),
            'TokenId' => $token,
            'TraceId' => $traceId,
            'ResultIndex' => $resultIndex,
        ]);

        $result = $response['Response']['Results'] ?? $response['Results'] ?? [];
        $fare = $result['Fare'] ?? [];

        return new FareRuleResult(
            isStillAvailable: ! ($response['Response']['Error']['ErrorCode'] ?? $response['Error']['ErrorCode'] ?? 1),
            confirmedNetAmount: number_format((float) ($fare['OfferedFare'] ?? $fare['PublishedFare'] ?? 0), 2, '.', ''),
            isRefundable: (bool) ($result['IsRefundable'] ?? false),
            rules: $result['FareRules'] ?? [],
            taxBreakup: $this->taxBreakup($fare),
        );
    }

    public function holdBooking(string $externalId, array $passengers, array $credentials): BookingHoldResult
    {
        [$traceId, $resultIndex] = $this->splitExternalId($externalId);
        $token = $this->authenticate($credentials);

        $tboPassengers = array_map(fn (array $p) => $this->toTboPassenger($p), $passengers);

        $response = $this->post($this->baseUrl($credentials).'/Book', [
            'EndUserIp' => $this->endUserIp($credentials),
            'TokenId' => $token,
            'TraceId' => $traceId,
            'ResultIndex' => $resultIndex,
            'Passengers' => $tboPassengers,
        ]);

        $result = $response['Response'] ?? $response;
        $status = $result['Response']['Status'] ?? $result['Status'] ?? null;

        if ($status !== 1 && $status !== 'Successful') {
            throw new SupplierException($this->code(), 'TBO Book call did not return a successful status: '.json_encode($result['Error'] ?? $result));
        }

        $pnr = $result['Response']['PNR'] ?? $result['PNR'] ?? null;
        $bookingId = $result['Response']['BookingId'] ?? $result['BookingId'] ?? null;

        if (! $pnr || ! $bookingId) {
            throw new SupplierException($this->code(), 'TBO Book call did not return a PNR/BookingId.');
        }

        // TBO's own booking-hold window for non-LCC fares is typically
        // communicated via LastTicketDate on the search result rather than
        // a fixed duration — 30 minutes is a conservative placeholder until
        // that field is threaded through from Search/FareQuote.
        return new BookingHoldResult(
            supplierBookingRef: "{$pnr}:{$bookingId}",
            holdExpiresAt: new \DateTimeImmutable('+30 minutes'),
            netAmount: number_format((float) ($result['Response']['Fare']['OfferedFare'] ?? 0), 2, '.', ''),
        );
    }

    public function confirmBooking(string $supplierBookingRef, array $credentials): BookingConfirmResult
    {
        [$pnr, $bookingId] = $this->splitBookingRef($supplierBookingRef);
        $token = $this->authenticate($credentials);

        $response = $this->post($this->baseUrl($credentials).'/Ticket', [
            'EndUserIp' => $this->endUserIp($credentials),
            'TokenId' => $token,
            'TraceId' => $credentials['last_trace_id'] ?? '',
            'PNR' => $pnr,
            'BookingId' => (int) $bookingId,
        ]);

        $result = $response['Response'] ?? $response;
        $passengers = $result['Response']['FlightItinerary']['Passenger'] ?? $result['FlightItinerary']['Passenger'] ?? [];

        $ticketNumbers = array_values(array_filter(array_map(
            fn ($p) => $p['Ticket']['TicketNumber'] ?? null,
            is_array($passengers) ? $passengers : [],
        )));

        return new BookingConfirmResult(
            supplierBookingRef: $supplierBookingRef,
            isTicketed: $ticketNumbers !== [],
            ticketNumbers: $ticketNumbers,
        );
    }

    public function retrieveBooking(string $supplierBookingRef, array $credentials): array
    {
        [$pnr, $bookingId] = $this->splitBookingRef($supplierBookingRef);
        $token = $this->authenticate($credentials);

        return $this->post($this->baseUrl($credentials).'/GetBookingDetails', [
            'EndUserIp' => $this->endUserIp($credentials),
            'TokenId' => $token,
            'PNR' => $pnr,
            'BookingId' => (int) $bookingId,
        ]);
    }

    public function cancelBooking(string $supplierBookingRef, array $credentials): bool
    {
        [, $bookingId] = $this->splitBookingRef($supplierBookingRef);
        $token = $this->authenticate($credentials);

        $response = $this->post($this->baseUrl($credentials).'/SendChangeRequest', [
            'EndUserIp' => $this->endUserIp($credentials),
            'TokenId' => $token,
            'BookingId' => (int) $bookingId,
            'RequestType' => 1, // FullCancellation
            'CancellationType' => 3, // Others
            'Remarks' => 'Cancelled via FyndMyTrip',
        ]);

        $status = $response['Response']['ResponseStatus'] ?? $response['ResponseStatus'] ?? null;

        return in_array($status, [1, 'Successful'], true);
    }

    public function getRefundStatus(string $supplierBookingRef, array $credentials): string
    {
        // TBO reports refund status as part of the change-request lifecycle
        // rather than a standalone endpoint — GetBookingDetails' ticket
        // status field is the closest documented equivalent until a real
        // test booking's actual response shape can be inspected.
        $details = $this->retrieveBooking($supplierBookingRef, $credentials);

        return $details['Response']['FlightItinerary']['Passenger'][0]['Ticket']['Status'] ?? 'unknown';
    }

    public function getSupplierAccountBalance(array $credentials): SupplierBalance
    {
        // Not documented as a public flight-API endpoint at
        // apidoc.tektravels.com/flight/ — TBO exposes agency balance via a
        // separate accounts API only visible after agency onboarding.
        throw new SupplierException($this->code(), 'TBO does not expose account balance via the public flight API; use the agency portal or the (undocumented-here) accounts API once onboarded.');
    }

    /**
     * Authenticates once per TTL window and caches the TokenId — TBO's
     * Authenticate response does not document an exact token lifetime, so
     * a conservative 10-minute cache is used; every call still re-checks
     * ErrorCode and re-authenticates transparently on failure.
     */
    protected function authenticate(array $credentials): string
    {
        $cacheKey = 'tbo_token:'.md5(($credentials['username'] ?? '').($credentials['client_id'] ?? ''));

        return Cache::remember($cacheKey, now()->addMinutes(10), function () use ($credentials) {
            $response = $this->post($this->baseUrl($credentials).'/Authenticate', [
                'ClientId' => $credentials['client_id'] ?? 'ApiIntegrationNew',
                'UserName' => $credentials['username'] ?? '',
                'Password' => $credentials['password'] ?? '',
                'EndUserIp' => $this->endUserIp($credentials),
            ]);

            $status = $response['Status'] ?? null;
            $tokenId = $response['TokenId'] ?? null;

            if ($status !== 1 || ! $tokenId) {
                throw new SupplierException($this->code(), 'TBO Authenticate failed: '.($response['Error']['ErrorMessage'] ?? 'unknown error'));
            }

            return $tokenId;
        });
    }

    /**
     * @return array{0: string, 1: string}
     */
    protected function splitExternalId(string $externalId): array
    {
        $parts = explode('|', $externalId, 2);

        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            throw new SupplierException($this->code(), "Malformed TBO external_id '{$externalId}' — expected '{TraceId}|{ResultIndex}'.");
        }

        return $parts;
    }

    /**
     * @return array{0: string, 1: string}
     */
    protected function splitBookingRef(string $supplierBookingRef): array
    {
        $parts = explode(':', $supplierBookingRef, 2);

        if (count($parts) !== 2) {
            throw new SupplierException($this->code(), "Malformed TBO supplier_booking_ref '{$supplierBookingRef}' — expected '{PNR}:{BookingId}'.");
        }

        return $parts;
    }

    /**
     * Maps our generic passenger shape (name, dob, gender — see
     * booking_passengers in the Database Design Document §4.2) onto TBO's
     * required Book payload. TODO: once booking_passengers grows contact/
     * nationality/title columns for real supplier submission, replace
     * these placeholders with real values rather than defaults.
     */
    protected function toTboPassenger(array $passenger): array
    {
        [$firstName, $lastName] = $this->splitName($passenger['name'] ?? 'Traveller');
        $isChild = ! empty($passenger['dob']) && now()->diffInYears($passenger['dob']) < 12;

        return [
            'Title' => match ($passenger['gender'] ?? 'unspecified') {
                'female' => 'Ms',
                default => 'Mr',
            },
            'FirstName' => $firstName,
            'LastName' => $lastName,
            'PaxType' => $isChild ? 2 : 1, // 1=Adult, 2=Child, 3=Infant
            'Gender' => match ($passenger['gender'] ?? 'unspecified') {
                'female' => 2,
                default => 1,
            },
            'Nationality' => 'IN', // TODO: capture per-passenger once the schema supports it.
            'DateOfBirth' => $passenger['dob'] ?? null,
            'ContactNo' => $passenger['contact_no'] ?? '0000000000',
            'Email' => $passenger['email'] ?? 'noreply@fyndmytrip.com',
            'IsLeadPax' => true,
        ];
    }

    protected function splitName(string $name): array
    {
        $parts = preg_split('/\s+/', trim($name), 2);

        return [$parts[0] ?? $name, $parts[1] ?? ''];
    }

    protected function endUserIp(array $credentials): string
    {
        return $credentials['end_user_ip'] ?? request()?->ip() ?? '127.0.0.1';
    }

    protected function baseUrl(array $credentials): string
    {
        return rtrim($credentials['base_url'] ?? config('suppliers.tbo.base_url'), '/');
    }

    /**
     * @throws SupplierException
     */
    protected function post(string $url, array $payload): array
    {
        try {
            $response = Http::timeout(15)->acceptJson()->asJson()->post($url, $payload);
        } catch (\Throwable $e) {
            throw new SupplierException($this->code(), "TBO request to {$url} failed: {$e->getMessage()}");
        }

        if ($response->failed()) {
            throw new SupplierException($this->code(), "TBO request to {$url} returned HTTP {$response->status()}.");
        }

        return $response->json() ?? [];
    }

    /**
     * TBO's Fare.TaxBreakup ([{key: 'K3', value: 256}, ...], 'TotalTax'
     * left out), falling back to Fare.Tax as one line.
     *
     * @return array<string, string>
     */
    protected function taxBreakup(array $fare): array
    {
        $out = [];
        foreach ($fare['TaxBreakup'] ?? [] as $row) {
            $key = strtoupper((string) ($row['key'] ?? ''));
            if ($key === '' || $key === 'TOTALTAX' || (float) ($row['value'] ?? 0) == 0.0) {
                continue;
            }
            $out[$key] = number_format((float) $row['value'], 2, '.', '');
        }
        if ($out === [] && (float) ($fare['Tax'] ?? 0) > 0) {
            $out['OT'] = number_format((float) $fare['Tax'], 2, '.', '');
        }

        return $out;
    }
}
