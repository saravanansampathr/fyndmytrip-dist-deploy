<?php

namespace App\Http\Controllers\Api;

use App\Domains\Booking\Models\Booking;
use App\Domains\Booking\Models\BookingPassenger;
use App\Domains\Booking\Models\BusSeatLock;
use App\Domains\Booking\Services\BookingStatusService;
use App\Domains\Booking\Services\CancellationPolicyService;
use App\Domains\Cashback\Services\CashbackService;
use App\Domains\Commission\Services\CommissionService;
use App\Domains\Inventory\Models\BusInventoryItem;
use App\Domains\Payments\Models\PaymentMethodRoute;
use App\Domains\Payments\Models\PaymentTransaction;
use App\Domains\Payments\Services\PaymentGatewayService;
use App\Domains\Suppliers\DTOs\BookingConfirmResult;
use App\Domains\Suppliers\DTOs\BookingHoldResult;
use App\Domains\Suppliers\DTOs\FareRuleResult;
use App\Domains\Suppliers\DTOs\SearchRequest;
use App\Domains\Suppliers\Exceptions\SupplierException;
use App\Domains\Suppliers\Services\BusSeatMapService;
use App\Domains\Suppliers\Services\MarkupService;
use App\Domains\Suppliers\Services\SupplierOrchestrator;
use App\Domains\Tenancy\Services\TenantContext;
use App\Domains\Wallet\Exceptions\InsufficientBalanceException;
use App\Domains\Wallet\Models\Wallet;
use App\Domains\Wallet\Models\WalletLedgerEntry;
use App\Domains\Wallet\Services\WalletService;
use App\Http\Controllers\Controller;
use App\Http\Resources\BookingResource;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Support\BusType;
use Barryvdh\DomPDF\Facade\Pdf;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Phase 3's "New Verticals & Real Booking Flows", started with Bus:
 * search -> hold -> confirm (wallet/gateway) -> ticket, against
 * MockBusSupplierAdapter until a real bus-operator API is wired in — the
 * exact same pipeline shape as FlightBookingController, simplified since a
 * bus route on this platform is always a single domestic leg (no
 * round-trip/multi-city, no cabin classes, no seat maps/ancillaries/
 * coupons in this first cut — those can layer on later without changing
 * this shape, the same way Flight grew them incrementally).
 *
 * store()/ticketPdf() below (the original manual-entry "book this
 * inventory item right now" test tool used from InventoryAdminPage's Bus
 * tab) are unchanged and still work exactly as before — the methods further
 * down are the new customer-facing flow, additive to it.
 */
class BusBookingController extends Controller
{
    public function __construct(
        protected MarkupService $markupService,
        protected SupplierOrchestrator $orchestrator,
        protected WalletService $walletService,
        protected BookingStatusService $statusService,
        protected PaymentGatewayService $paymentGatewayService,
        protected CashbackService $cashbackService,
        protected CommissionService $commissionService,
        protected BusSeatMapService $seatMapService,
        protected CancellationPolicyService $cancellationPolicyService,
    ) {}

    public function store(Request $request, BusInventoryItem $busInventoryItem)
    {
        $user = $request->user();
        $this->authorizeInventory($user, $busInventoryItem);

        $data = $request->validate([
            'passengers' => ['required', 'array', 'min:1', 'max:40'],
            'passengers.*.name' => ['required', 'string', 'max:191'],
            'passengers.*.seat_number' => ['nullable', 'string', 'max:10'],
            'reporting_time' => ['nullable', 'string', 'max:20'],
            'contact_email' => ['nullable', 'email', 'max:191'],
            'contact_phone' => ['nullable', 'string', 'max:30'],
        ]);

        $item = $busInventoryItem;
        $passengerCount = count($data['passengers']);

        $netAmount = BigDecimal::of((string) $item->net_price)->multipliedBy($passengerCount);

        $role = $this->markupRoleFor($user);
        $pricing = $this->markupService->applyMarkup(
            $user->tenant,
            Booking::MODULE_BUS,
            $role,
            (string) $netAmount->toScale(2, RoundingMode::HALF_UP),
            [
                'passenger_count' => $passengerCount,
                'destination' => $item->destination,
                // Bus routes on this platform are domestic only.
                'is_international' => false,
            ],
        );

        $grossAmount = BigDecimal::of($pricing['gross_amount']);

        $booking = DB::transaction(function () use ($item, $data, $user, $grossAmount, $netAmount, $pricing, $passengerCount) {
            $booking = Booking::create([
                'tenant_id' => $item->tenant_id ?? $user->tenant_id,
                'created_by_user_id' => $user->id,
                'channel' => Booking::channelForTenant($user->tenant),
                'module' => Booking::MODULE_BUS,
                'supplier_code' => 'OWN_INVENTORY',
                'supplier_booking_ref' => (string) random_int(10000000, 99999999),
                'gross_amount' => (string) $grossAmount->toScale(2, RoundingMode::HALF_UP),
                'supplier_net_amount' => (string) $netAmount->toScale(2, RoundingMode::HALF_UP),
                'markup_amount' => $pricing['markup_amount'],
                'travel_date_start' => $item->travel_date,
                'passenger_count' => $passengerCount,
                'contact_email' => $data['contact_email'] ?? $user->email,
                'contact_phone' => $data['contact_phone'] ?? $user->phone,
                'currency' => $item->currency,
                'status' => Booking::STATUS_TICKETED,
                'metadata' => [
                    'inventory_item_id' => $item->id,
                    'operator_name' => $item->operator_name,
                    'bus_type' => $item->bus_type,
                    'origin' => $item->origin,
                    'destination' => $item->destination,
                    'boarding_point' => $item->boarding_point,
                    'dropping_point' => $item->dropping_point,
                    'departure_time' => $item->departure_time,
                    'reporting_time' => $data['reporting_time'] ?? null,
                    'ticket_no' => 'BUS'.now()->format('Ymd').random_int(100000, 999999),
                ],
            ]);

            foreach ($data['passengers'] as $passenger) {
                $booking->passengers()->create([
                    'name' => $passenger['name'],
                    'seat_number' => $passenger['seat_number'] ?? null,
                    'passenger_type' => BookingPassenger::TYPE_ADULT,
                ]);
            }

            return $booking;
        });

        return response()->json(['data' => new BookingResource($booking->load(['passengers', 'tenant']))], 201);
    }

    public function ticketPdf(Request $request, Booking $booking)
    {
        abort_unless($booking->module === Booking::MODULE_BUS, 404);

        $booking->load([
            'passengers',
            'tenant:id,name,legal_name,logo_url,support_email,support_phone,business_address',
        ]);

        $tenant = $booking->tenant;

        // "No where markup should be shown as markup" — folded into
        // Service & Fees, same convention as flights/hotel.
        $baseFare = (float) $booking->supplier_net_amount;
        $serviceFee = round((float) $booking->markup_amount, 2);

        $priceRows = [['label' => 'Base Fare', 'amount' => $baseFare]];
        if ($serviceFee > 0) {
            $priceRows[] = ['label' => 'Service & Fees', 'amount' => $serviceFee];
        }

        $pdf = Pdf::loadView('pdf.bus-ticket', [
            'booking' => $booking,
            'brandName' => $tenant?->legal_name ?? $tenant?->name ?? 'FyndMyTrip',
            'logo' => \App\Support\PdfBranding::resolveLogo($tenant),
            'tenant' => $tenant,
            'socialLinks' => \App\Support\PdfBranding::socialLinks($tenant),
            'qrDataUri' => \App\Support\PdfBranding::qrDataUri($tenant),
            'playStoreUrl' => \App\Support\PdfBranding::appLinks($tenant)['play_store_url'],
            'appStoreUrl' => \App\Support\PdfBranding::appLinks($tenant)['app_store_url'],
            'generatedAt' => now()->format('d M Y, h:i A'),
            'priceRows' => $priceRows,
            'total' => (float) $booking->total_amount,
        ])->setPaper('a4');

        $filename = ($booking->booking_code ?? $booking->booking_reference).'-bus-ticket.pdf';

        return $request->boolean('download') ? $pdf->download($filename) : $pdf->stream($filename);
    }

    /**
     * Seat layout for a bus trip the customer is about to book — generated
     * fresh from the offer's own bus_type/seats_available the frontend
     * already has from search() (no DB lookup needed for MOCKBUS offers),
     * plus real bus_seat_locks reservations — see BusSeatMapService. Called
     * before hold(), while the customer is still choosing seats, so unlike
     * Flight's seatMap() (scoped to an existing HELD booking) this one
     * isn't tied to a booking at all yet.
     */
    public function seatMap(Request $request)
    {
        $data = $request->validate([
            'supplier_code' => ['required', 'string'],
            'external_id' => ['required', 'string'],
            'travel_date' => ['required', 'date_format:Y-m-d'],
            'bus_type' => ['nullable', 'string', 'max:100'],
            'seats_available' => ['nullable', 'integer', 'min:1', 'max:80'],
        ]);

        $totalSeats = $this->resolveTotalSeatsForMap(
            $data['supplier_code'],
            $data['external_id'],
            $data['travel_date'],
            $data['seats_available'] ?? null,
        );

        $busType = $data['bus_type'] ?? 'AC Seater';

        $map = $this->seatMapService->generate(
            $data['supplier_code'],
            $data['external_id'],
            $data['travel_date'],
            $busType,
            $totalSeats,
        );

        $map['rows'] = $this->withPerSeatPrices($request, $map['rows'], $data['supplier_code'], $data['external_id']);

        return response()->json(['data' => $map]);
    }

    /**
     * Attaches each seat's own real gross price (base net fare from
     * priceCheck() x that seat's fare_multiplier, markup applied for one
     * passenger) so the seat chart can show what picking that specific
     * seat actually costs, not just its category. Best-effort: if the
     * fresh price check fails for any reason, the map still renders —
     * hold() re-derives the real total independently, so a missing
     * per-seat price here never affects what's actually charged.
     */
    protected function withPerSeatPrices(Request $request, array $rows, string $supplierCode, string $externalId): array
    {
        // Guest-accessible (called from search()'s inline seat picker before
        // any login), so $user may be null — same TenantContext fallback as
        // search() itself, since a guest still needs a resolvable tenant to
        // price these seats against.
        $user = $request->user();
        $tenant = $user?->tenant ?? app(TenantContext::class)->get();

        try {
            if ($supplierCode === BusInventoryItem::SUPPLIER_CODE) {
                $fareRule = $this->ownInventoryPriceCheck($externalId);
            } else {
                $fareRule = $this->orchestrator->priceCheck($tenant, $supplierCode, $externalId);
            }
        } catch (\Throwable $e) {
            return $rows;
        }

        if (! $fareRule->isStillAvailable) {
            return $rows;
        }

        $role = $this->markupRoleFor($user);
        $baseNetPerSeat = BigDecimal::of($fareRule->confirmedNetAmount);
        $priceBySeat = [];

        foreach ($rows as $row) {
            foreach ($row['seats'] as $seat) {
                $multiplier = (string) $seat['fare_multiplier'];

                if (! isset($priceBySeat[$multiplier])) {
                    $netForSeat = (string) $baseNetPerSeat->multipliedBy($multiplier)->toScale(2, RoundingMode::HALF_UP);
                    $pricing = $this->markupService->applyMarkup($tenant, Booking::MODULE_BUS, $role, $netForSeat, [
                        'passenger_count' => 1,
                        'supplier_code' => $supplierCode,
                    ]);
                    $priceBySeat[$multiplier] = $pricing['gross_amount'];
                }
            }
        }

        foreach ($rows as &$row) {
            foreach ($row['seats'] as &$seat) {
                $seat['gross_amount'] = $priceBySeat[(string) $seat['fare_multiplier']] ?? null;
            }
            unset($seat);
        }
        unset($row);

        return $rows;
    }

    /**
     * Own-inventory offers decrement `capacity` as a plain headcount on
     * every hold, so it alone can't seed a stable seat-grid size (the grid
     * would shrink and seat numbers would shift as seats sell) — add back
     * the seats this trip has already handed out real locks for to
     * reconstruct the original total. MOCKBUS offers have no persisted
     * capacity at all, so the frontend's own seats_available figure
     * (itself deterministic per trip — see MockBusSupplierAdapter) is the
     * total.
     */
    protected function resolveTotalSeatsForMap(string $supplierCode, string $externalId, string $travelDate, ?int $seatsAvailable): int
    {
        if ($supplierCode === BusInventoryItem::SUPPLIER_CODE) {
            $item = $this->ownBusInventoryItemFromExternalId($externalId);
            $lockedCount = BusSeatLock::query()
                ->where('supplier_code', $supplierCode)
                ->where('external_id', $externalId)
                ->whereDate('travel_date', $travelDate)
                ->count();

            return max(1, (int) ($item->capacity ?? 0) + $lockedCount);
        }

        return max(4, (int) ($seatsAvailable ?? 40));
    }

    /**
     * A bus route on this platform is always one-way, single-leg — no
     * round-trip/multi-city concept the way flights have one, since a
     * return journey is just its own separate search in the other
     * direction. Merges MOCKBUS-adapter offers with the tenant's own
     * manually-entered BusInventoryItem rows (ownInventoryOffers()),
     * shaped identically so the frontend can't tell the difference.
     */
    public function search(Request $request)
    {
        $data = $request->validate([
            'origin' => ['required', 'string', 'max:100'],
            'destination' => ['required', 'string', 'max:100'],
            'departure_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'passengers' => ['nullable', 'integer', 'min:1', 'max:40'],
        ]);

        $passengerCount = $data['passengers'] ?? 1;
        // Guest-accessible — "browse without login, login required only to
        // book" (Platform Roadmap's B2C public storefront), same convention
        // FlightBookingController::search()/HotelBookingController::search()
        // already established. $user is null for an anonymous visitor; the
        // acting tenant then falls back to whichever storefront
        // ResolveTenant matched from the request Host header.
        $user = $request->user();
        $tenant = $user?->tenant ?? app(TenantContext::class)->get();
        $role = $this->markupRoleFor($user);
        $canViewMargin = $user?->hasPermission('pricing.view_margin') ?? false;

        abort_if(! $tenant, 422, 'Unable to resolve which storefront to search against.');

        $searchRequest = new SearchRequest(
            module: Booking::MODULE_BUS,
            origin: $data['origin'],
            destination: $data['destination'],
            departureDate: $data['departure_date'],
            passengerCount: $passengerCount,
        );

        $offers = $this->orchestrator->search($tenant, $searchRequest);
        array_push($offers, ...$this->ownInventoryOffers($tenant, $data['origin'], $data['destination'], $data['departure_date']));

        // Fetched once and matched in-memory per offer below — same
        // convention as CashbackService/MarkupService's candidate-list
        // pattern, so this costs one query for the whole results page.
        $cancellationCandidates = $this->cancellationPolicyService->activeCandidates($tenant?->id);

        $offers = array_map(function (array $item) use ($tenant, $role, $data, $passengerCount, $cancellationCandidates) {
            $pricing = $this->markupService->applyMarkup($tenant, Booking::MODULE_BUS, $role, $item['net_amount'], [
                'passenger_count' => $passengerCount,
                'origin' => $data['origin'],
                'destination' => $data['destination'],
                // Bus routes on this platform are domestic only — same
                // convention the original test-booking store() used.
                'is_international' => false,
                'supplier_code' => $item['supplier_code'] ?? null,
            ]);

            $merged = array_merge($item, $pricing);

            // "we will show least and mention like fares start with" — the
            // seat chart's cheapest tier (Last row on a berth layout; flat
            // on a Seater, so it's identical to gross_amount there), priced
            // for one passenger regardless of how many the search itself
            // was for.
            $seatType = BusType::seatType($item['bus_type'] ?? null);
            $minMultiplier = (string) $this->seatMapService->minFareMultiplier($seatType);
            $minNet = (string) BigDecimal::of($item['net_amount'])->multipliedBy($minMultiplier)->toScale(2, RoundingMode::HALF_UP);
            $minPricing = $this->markupService->applyMarkup($tenant, Booking::MODULE_BUS, $role, $minNet, [
                'passenger_count' => 1,
                'origin' => $data['origin'],
                'destination' => $data['destination'],
                'is_international' => false,
                'supplier_code' => $item['supplier_code'] ?? null,
            ]);
            $merged['fare_starts_at'] = $minPricing['gross_amount'];

            // "Same like flight we need to show the Cancellation policy in
            // view or like boarding and dropping" — a pre-booking preview
            // of whichever cancellation policy would actually apply to
            // this offer (falls back to the platform's default bands if
            // nothing tenant/supplier/route-specific is configured), shown
            // on the search card alongside boarding/dropping points.
            $policy = $this->cancellationPolicyService->bestMatchFromCandidates(
                $cancellationCandidates,
                Booking::MODULE_BUS,
                false,
                $item['supplier_code'] ?? null,
                $data['origin'],
                $data['destination'],
            );
            $merged['cancellation_policy'] = $this->cancellationPolicyService->previewBands($policy);

            return $merged;
        }, $offers);

        usort($offers, fn ($a, $b) => $a['gross_amount'] <=> $b['gross_amount']);

        // Same margin-withholding rule as FlightBookingController::search().
        if (! $canViewMargin) {
            $offers = array_map(function (array $offer) {
                unset($offer['net_amount'], $offer['markup_amount']);

                return $offer;
            }, $offers);
        }

        return response()->json([
            'data' => [
                'origin' => $data['origin'],
                'destination' => $data['destination'],
                'departure_date' => $data['departure_date'],
                'passengers' => $passengerCount,
                'can_view_margin' => $canViewMargin,
                'offers' => $offers,
            ],
        ]);
    }

    /**
     * A tenant's own manually-entered bus inventory for this exact origin/
     * destination/date, shaped and merged into the offer list exactly like
     * a real supplier's results — mirrors
     * FlightBookingController::ownInventoryOffers().
     */
    protected function ownInventoryOffers($tenant, string $origin, string $destination, string $departureDate): array
    {
        $tenantId = $tenant?->id;

        $items = BusInventoryItem::query()
            ->where('status', BusInventoryItem::STATUS_ACTIVE)
            ->where('origin', $origin)
            ->where('destination', $destination)
            ->whereDate('travel_date', $departureDate)
            ->where(function ($q) use ($tenantId) {
                $q->whereNull('tenant_id')
                    ->orWhere('tenant_id', $tenantId)
                    ->orWhere('share_with_network', true)
                    ->when(is_null($tenantId), fn ($q2) => $q2->orWhere('share_on_platform_site', true));
            })
            ->get();

        return $items->filter->isSellable()->map(fn (BusInventoryItem $item) => $item->toBusOfferArray())->values()->all();
    }

    /**
     * Holds a Booking for the one bus trip the customer picked — one
     * priceCheck()/holdBooking() supplier round-trip (or the own-inventory
     * equivalent), then persists the Booking + its single BookingSegment +
     * passengers, exactly like FlightBookingController::hold() but for
     * one leg only.
     */
    public function hold(Request $request)
    {
        return $this->performHold($request, $request->user());
    }

    /**
     * Guest checkout — "from home page to till booking, only during the
     * payment page you may ask book as guest or login and book", same
     * pattern already established for Holiday Packages/Flights/Hotels (see
     * FlightBookingController::guestHold()'s docblock): no auth:sanctum on
     * this route (see routes/api.php), so a browsing visitor reaches this
     * only once they've searched, picked seats, and filled in passenger
     * details — right where hold() would otherwise require an account. A
     * real customer account is transparently created from the guest
     * contact details given here and logged straight into (the frontend
     * dispatches the returned token the same way a normal login does), so
     * confirm/pay and viewing this booking again later all work exactly
     * like any other customer from this point on.
     *
     * If the email already belongs to an existing account this refuses to
     * silently issue a token for it — same anti-account-takeover guard as
     * the Holiday Packages/Flights/Hotels guest flows.
     */
    public function guestHold(Request $request)
    {
        $tenant = $request->attributes->get('resolved_tenant') ?? app(TenantContext::class)->get();
        abort_if(! $tenant, 422, 'Unable to resolve which storefront this booking is for.');

        $guest = $request->validate([
            'guest_name' => ['required', 'string', 'max:191'],
            'guest_email' => ['required', 'email', 'max:191'],
            'guest_phone' => ['nullable', 'string', 'max:20'],
        ]);

        // Checking "guest_email" alone isn't enough to guard against a
        // fatal DB error: users.phone carries its own (tenant_id, phone)
        // unique index (see create_users_table migration), and a guest can
        // easily reuse a phone number from an earlier booking under a
        // different email — that crashed live as an uncaught
        // unique-constraint-violation 500 the first time it happened
        // (see HotelBookingController::guestHold()'s identical fix),
        // instead of the friendly validation error below.
        $existing = User::query()
            ->where('tenant_id', $tenant->id)
            ->where(function ($query) use ($guest) {
                $query->where('email', $guest['guest_email']);
                if (! empty($guest['guest_phone'])) {
                    $query->orWhere('phone', $guest['guest_phone']);
                }
            })
            ->first();
        if ($existing) {
            $field = $existing->email === $guest['guest_email'] ? 'guest_email' : 'guest_phone';
            $message = $field === 'guest_email'
                ? 'An account already exists with this email. Please sign in to continue.'
                : 'An account already exists with this phone number. Please sign in to continue.';
            throw ValidationException::withMessages([$field => [$message]]);
        }

        $user = DB::transaction(function () use ($tenant, $guest) {
            $newUser = User::create([
                'tenant_id' => $tenant->id,
                'name' => $guest['guest_name'],
                'email' => $guest['guest_email'],
                'phone' => $guest['guest_phone'] ?? null,
                // Random — never told to the guest — since this is a real
                // account, not a passwordless one; see guestStore()'s
                // docblock for why.
                'password_hash' => Hash::make(Str::random(32)),
                'role' => User::ROLE_CUSTOMER,
                'status' => User::STATUS_ACTIVE,
            ]);

            Wallet::create([
                'tenant_id' => $tenant->id,
                'owner_type' => Wallet::OWNER_USER,
                'owner_id' => $newUser->id,
            ]);

            return $newUser;
        });

        $response = $this->performHold($request, $user);

        // Only decorate a successful hold (201) with the fresh account's
        // token — performHold() also returns plain error JsonResponses
        // (409/502) on a failed fare re-check or seat conflict, which
        // should pass through untouched. The guest account still exists at
        // that point (same trade-off HolidayBookingController::guestStore()
        // already accepts) — they can simply try again, now effectively
        // logged in.
        if ($response->getStatusCode() === 201) {
            $payload = $response->getData(true);
            $payload['user'] = (new UserResource($user))->toArray($request);
            $payload['token'] = $user->createToken('api')->plainTextToken;
            $payload['guest_checkout'] = true;

            return response()->json($payload, 201);
        }

        return $response;
    }

    /**
     * Shared by hold() (logged-in customer/agent/etc.) and guestHold() (a
     * fresh account just created for a guest) — everything past "who is
     * booking this" is identical either way.
     */
    protected function performHold(Request $request, User $user)
    {
        $data = $request->validate([
            'supplier_code' => ['required', 'string'],
            'external_id' => ['required', 'string'],
            'segment' => ['nullable', 'array'],
            'travel_date' => ['required', 'date_format:Y-m-d'],
            'contact_email' => ['nullable', 'email', 'max:191'],
            'contact_phone' => ['nullable', 'string', 'max:30'],
            'bus_type' => ['nullable', 'string', 'max:100'],
            'seats_available' => ['nullable', 'integer', 'min:1', 'max:80'],
            // A bus booking on this platform can select at most 6 seats in
            // one go (matches BusSeatLayoutGrid's own cap) — tightened down
            // from the old blanket 40-passenger ceiling shared with Flight.
            'passengers' => ['required', 'array', 'min:1', 'max:6'],
            'passengers.*.name' => ['required', 'string', 'max:191'],
            'passengers.*.seat_number' => ['required', 'string', 'max:10'],
            'passengers.*.gender' => ['required', 'in:male,female,other,unspecified'],
            // Prefix drives gender client-side (mirrors Flight's Title ->
            // TITLE_GENDER mapping) — nullable/independent of gender here
            // since the frontend already resolves and sends gender itself;
            // this just persists the prefix onto the passenger record.
            'passengers.*.title' => ['nullable', 'in:Mr,Mrs,Ms,Miss,Master,Dr'],
            // Plain numeric age, captured on this passenger-details step —
            // deliberately not a full dob (that's Flight-only, for
            // passport/MRZ purposes on international travel).
            'passengers.*.age' => ['required', 'integer', 'min:1', 'max:120'],
            // The boarding/dropping point the customer actually chose from
            // the offer's boarding_points/dropping_points list, overriding
            // the single default the offer/segment carried.
            'boarding_point' => ['nullable', 'string', 'max:150'],
            'dropping_point' => ['nullable', 'string', 'max:150'],
        ]);

        $tenant = $user->tenant;
        $role = $this->markupRoleFor($user);
        $passengerCount = count($data['passengers']);
        $segment = $data['segment'] ?? [];

        if ($data['supplier_code'] === BusInventoryItem::SUPPLIER_CODE) {
            $fareRule = $this->ownInventoryPriceCheck($data['external_id']);
        } else {
            try {
                $fareRule = $this->orchestrator->priceCheck($tenant, $data['supplier_code'], $data['external_id']);
            } catch (SupplierException $e) {
                return response()->json(['message' => $e->getMessage()], 502);
            }
        }

        if (! $fareRule->isStillAvailable) {
            return response()->json(['message' => 'This bus trip is no longer available. Please search again.'], 409);
        }

        // Real per-seat-category fares: each selected seat's own net fare is
        // the offer's base net amount x that seat's fare_multiplier (Single
        // Lower, Double Berth, Last row, ...) — summed across every
        // passenger, then markup is applied ONCE on the total so a
        // TYPE_FLAT_PER_PAX rule still scales by the real passenger count
        // rather than by however many times applyMarkup() got called.
        $totalSeatsForMap = $this->resolveTotalSeatsForMap(
            $data['supplier_code'],
            $data['external_id'],
            $data['travel_date'],
            $data['seats_available'] ?? null,
        );
        $busTypeForMap = $data['bus_type'] ?? $segment['bus_type'] ?? $segment['fare_basis'] ?? 'AC Seater';

        $pricingMap = $this->seatMapService->generate($data['supplier_code'], $data['external_id'], $data['travel_date'], $busTypeForMap, $totalSeatsForMap);
        $multiplierBySeat = [];
        foreach ($pricingMap['rows'] as $row) {
            foreach ($row['seats'] as $seat) {
                $multiplierBySeat[$seat['seat_number']] = $seat['fare_multiplier'];
            }
        }

        $baseNetPerSeat = BigDecimal::of($fareRule->confirmedNetAmount);
        $totalNetAmount = BigDecimal::of('0.00');
        foreach ($data['passengers'] as $passenger) {
            $seatNumber = strtoupper(trim($passenger['seat_number']));
            $multiplier = (string) ($multiplierBySeat[$seatNumber] ?? 1.0);
            $totalNetAmount = $totalNetAmount->plus($baseNetPerSeat->multipliedBy($multiplier));
        }
        $totalNetAmount = $totalNetAmount->toScale(2, RoundingMode::HALF_UP);

        $pricing = $this->markupService->applyMarkup($tenant, Booking::MODULE_BUS, $role, (string) $totalNetAmount, [
            'passenger_count' => $passengerCount,
            'origin' => $segment['origin'] ?? null,
            'destination' => $segment['destination'] ?? null,
            'is_international' => false,
            'supplier_code' => $data['supplier_code'],
        ]);

        $booking = Booking::create([
            'tenant_id' => $tenant->id,
            'created_by_user_id' => $user->id,
            'channel' => Booking::channelForTenant($tenant),
            'module' => Booking::MODULE_BUS,
            'supplier_code' => $data['supplier_code'],
            'is_international' => false,
            'gross_amount' => $pricing['gross_amount'],
            'supplier_net_amount' => (string) $totalNetAmount,
            'markup_amount' => $pricing['markup_amount'],
            'travel_date_start' => $data['travel_date'],
            'passenger_count' => $passengerCount,
            'contact_email' => $data['contact_email'] ?? $user->email,
            'contact_phone' => $data['contact_phone'] ?? $user->phone,
            // Populates the same metadata keys bus-ticket.blade.php reads
            // (operator_name/bus_type/origin/destination/boarding_point/
            // dropping_point/departure_time) from the $segment array
            // already carried by the search->hold hand-off, so a ticket PDF
            // for a real-flow booking renders identically to one created by
            // the legacy store() tool above instead of showing blanks.
            'metadata' => [
                'operator_name' => $segment['carrier_or_provider'] ?? null,
                'bus_type' => $segment['bus_type'] ?? $segment['fare_basis'] ?? null,
                'origin' => $segment['origin'] ?? null,
                'destination' => $segment['destination'] ?? null,
                // The customer's chosen boarding/dropping point (from the
                // offer's boarding_points/dropping_points list) wins over
                // the segment's single default, same as raw_supplier_payload
                // below — both are what the ticket PDF/admin views read.
                'boarding_point' => $data['boarding_point'] ?? $segment['boarding_point'] ?? $segment['origin'] ?? null,
                'dropping_point' => $data['dropping_point'] ?? $segment['dropping_point'] ?? $segment['destination'] ?? null,
                'departure_time' => isset($segment['departure_at']) ? \Illuminate\Support\Carbon::parse($segment['departure_at'])->format('h:i A') : null,
                'reporting_time' => null,
                'ticket_no' => 'BUS'.now()->format('Ymd').random_int(100000, 999999),
            ],
        ]);

        // Seats were already chosen on the seat-layout step (BusSeatLayoutGrid,
        // fed by seatMap() above), but a seat can still be taken out from
        // under the customer between fetching that map and submitting this
        // hold — re-check each one against a freshly generated map (same
        // re-generate-per-passenger pattern as FlightBookingController::
        // extras()) and reserve it in bus_seat_locks so no one else can grab
        // it either, aborting the whole hold on the first conflict.
        // ($totalSeatsForMap/$busTypeForMap were already resolved above, for
        // the per-seat pricing pass.)
        try {
            foreach ($data['passengers'] as $passenger) {
                $seatNumber = strtoupper(trim($passenger['seat_number']));
                $gender = $passenger['gender'];

                $map = $this->seatMapService->generate($data['supplier_code'], $data['external_id'], $data['travel_date'], $busTypeForMap, $totalSeatsForMap);
                $seatInfo = collect($map['rows'])->flatMap(fn ($r) => $r['seats'])->firstWhere('seat_number', $seatNumber);

                $reservable = $seatInfo && (
                    $seatInfo['state'] === 'available'
                    || ($seatInfo['state'] === 'female_only' && $gender === 'female')
                );

                if (! $reservable) {
                    throw new \RuntimeException("Seat {$seatNumber} is no longer available. Please pick another seat.");
                }

                $bookingPassenger = $booking->passengers()->create([
                    'name' => $passenger['name'],
                    'title' => $passenger['title'] ?? null,
                    'age' => $passenger['age'],
                    'seat_number' => $seatNumber,
                    'gender' => $gender,
                    'passenger_type' => BookingPassenger::TYPE_ADULT,
                ]);

                $this->seatMapService->reserve($data['supplier_code'], $data['external_id'], $data['travel_date'], $seatNumber, $gender, $booking->id, $bookingPassenger->id);
            }
        } catch (\RuntimeException $e) {
            $this->seatMapService->releaseForBooking($booking->id);
            $this->statusService->transition($booking, Booking::STATUS_FAILED, $user, $e->getMessage());

            return response()->json(['message' => $e->getMessage()], 409);
        }

        if ($segment !== []) {
            $rawPayload = $segment;
            $rawPayload['is_refundable'] = $fareRule->isRefundable;
            // Same override as the metadata block above — the segment's
            // raw payload should reflect what the customer actually picked,
            // not just the offer's single default boarding/dropping point.
            if (isset($data['boarding_point'])) {
                $rawPayload['boarding_point'] = $data['boarding_point'];
            }
            if (isset($data['dropping_point'])) {
                $rawPayload['dropping_point'] = $data['dropping_point'];
            }

            $booking->segments()->create([
                'sequence' => 1,
                'origin' => $segment['origin'] ?? null,
                'destination' => $segment['destination'] ?? null,
                'departure_at' => $segment['departure_at'] ?? null,
                'arrival_at' => $segment['arrival_at'] ?? null,
                'carrier_or_provider' => $segment['carrier_or_provider'] ?? null,
                'fare_basis' => $segment['fare_basis'] ?? null,
                'raw_supplier_payload' => $rawPayload,
            ]);
        }

        if ($data['supplier_code'] === BusInventoryItem::SUPPLIER_CODE) {
            try {
                $hold = $this->ownInventoryHold($data['external_id']);
            } catch (\RuntimeException $e) {
                $this->seatMapService->releaseForBooking($booking->id);
                $this->statusService->transition($booking, Booking::STATUS_FAILED, $user, $e->getMessage());

                return response()->json(['message' => $e->getMessage()], 409);
            }
        } else {
            try {
                $hold = $this->orchestrator->hold($tenant, $data['supplier_code'], $data['external_id'], $data['passengers']);
            } catch (SupplierException $e) {
                $this->seatMapService->releaseForBooking($booking->id);
                $this->statusService->transition($booking, Booking::STATUS_FAILED, $user, $e->getMessage());

                return response()->json(['message' => $e->getMessage()], 502);
            }
        }

        $booking->update(['supplier_booking_ref' => $hold->supplierBookingRef]);
        $this->statusService->transition($booking, Booking::STATUS_HELD, $user, 'Supplier hold confirmed.');

        return response()->json([
            'data' => new BookingResource($booking->fresh(['passengers', 'segments'])),
            'hold_expires_at' => $hold->holdExpiresAt->format(DATE_ATOM),
        ], 201);
    }

    /**
     * Confirms payment for a HELD bus booking — wallet, gateway, or a
     * wallet+gateway split — identical logic to
     * FlightBookingController::confirm() (no international restriction:
     * bus bookings are never international), minus coupon/ancillary
     * handling this first cut doesn't have.
     */
    public function confirm(Request $request, Booking $booking)
    {
        $user = $request->user();
        abort_unless($booking->module === Booking::MODULE_BUS, 404);

        if ($booking->status !== Booking::STATUS_HELD) {
            return response()->json(['message' => "Booking must be 'held' to confirm; it is currently '{$booking->status}'."], 409);
        }

        $data = $request->validate([
            'wallet_amount' => ['nullable', 'numeric', 'min:0'],
        ]);

        $payable = BigDecimal::of($booking->recalculateTotal());
        $requestedWalletAmount = BigDecimal::of((string) ($data['wallet_amount'] ?? '0'));

        if ($requestedWalletAmount->isGreaterThan($payable)) {
            return response()->json(['message' => 'Wallet amount cannot exceed the payable amount.'], 422);
        }

        $wallet = Wallet::query()->firstOrCreate([
            'tenant_id' => $booking->tenant_id,
            'owner_type' => Wallet::OWNER_USER,
            'owner_id' => $user->id,
        ]);

        if ($requestedWalletAmount->isGreaterThan(0) && $requestedWalletAmount->isGreaterThan(BigDecimal::of($wallet->balance))) {
            return response()->json([
                'message' => 'Wallet amount exceeds your available balance.',
                'wallet_balance' => (string) $wallet->balance,
            ], 402);
        }

        $walletAmount = (string) $requestedWalletAmount->toScale(2, RoundingMode::HALF_UP);
        $remainder = $payable->minus($requestedWalletAmount)->toScale(2, RoundingMode::HALF_UP);

        if (! $remainder->isPositive()) {
            try {
                DB::transaction(function () use ($booking, $wallet, $payable, $walletAmount, $user) {
                    $this->walletService->debit(
                        $wallet,
                        $walletAmount,
                        WalletLedgerEntry::REF_BOOKING_PAYMENT,
                        $booking->id,
                        "Payment for booking {$booking->booking_reference}",
                        $user->id,
                    );

                    $booking->total_amount = (string) $payable;
                    $booking->save();
                });
            } catch (InsufficientBalanceException $e) {
                return response()->json(['message' => $e->getMessage()], 402);
            }

            $this->statusService->transition($booking, Booking::STATUS_CONFIRMED, $user, 'Wallet payment captured.');

            return $this->finalizeTicketing($booking, $user);
        }

        $transaction = $this->paymentGatewayService->initiate(
            $booking,
            (string) $remainder,
            $walletAmount,
            $requestedWalletAmount->isGreaterThan(0) ? $wallet : null,
        );

        return response()->json([
            'requires_payment' => true,
            'data' => new BookingResource($booking->fresh(['passengers', 'segments'])),
            'payment' => [
                'transaction_id' => $transaction->id,
                'gateway' => $transaction->gateway,
                'amount' => $transaction->amount,
                'wallet_amount_applied' => $walletAmount,
                'currency' => $transaction->currency,
                'available_methods' => $this->paymentGatewayService->availableMethods($booking->tenant),
            ],
        ]);
    }

    /**
     * The gateway leg of confirm() — mirrors
     * FlightBookingController::payGateway() exactly, minus coupons.
     */
    public function payGateway(Request $request, Booking $booking)
    {
        $user = $request->user();
        abort_unless($booking->module === Booking::MODULE_BUS, 404);

        if ($booking->status !== Booking::STATUS_HELD) {
            return response()->json(['message' => "Booking must be 'held' to pay; it is currently '{$booking->status}'."], 409);
        }

        $transaction = PaymentTransaction::query()
            ->where('booking_id', $booking->id)
            ->where('status', PaymentTransaction::STATUS_INITIATED)
            ->latest()
            ->first();

        if (! $transaction) {
            return response()->json(['message' => 'No pending payment was found for this booking. Call confirm first.'], 409);
        }

        $data = $request->validate([
            'method' => ['required', 'string', 'in:'.implode(',', PaymentMethodRoute::METHODS)],
        ]);
        $method = $data['method'];
        $payable = $booking->recalculateTotal();

        try {
            DB::transaction(function () use ($booking, $transaction, $payable, $user, $method) {
                $walletAmount = BigDecimal::of((string) $transaction->wallet_amount);
                if ($walletAmount->isGreaterThan(0)) {
                    $wallet = $transaction->wallet_id ? Wallet::withoutTenantScope()->find($transaction->wallet_id) : null;

                    abort_if(! $wallet, 500, 'Wallet leg on this payment could not be resolved.');

                    $this->walletService->debit(
                        $wallet,
                        (string) $walletAmount,
                        WalletLedgerEntry::REF_BOOKING_PAYMENT,
                        $booking->id,
                        "Payment for booking {$booking->booking_reference}",
                        $user->id,
                    );
                }

                $this->paymentGatewayService->settle($transaction, $booking->tenant, $method);

                $booking->total_amount = $payable;
                $booking->save();
            });
        } catch (InsufficientBalanceException $e) {
            return response()->json(['message' => $e->getMessage()], 402);
        }

        $this->statusService->transition($booking, Booking::STATUS_CONFIRMED, $user, "Payment gateway ({$transaction->gateway}) + wallet captured.");

        return $this->finalizeTicketing($booking, $user);
    }

    /**
     * Shared tail for both payment paths once the booking is CONFIRMED —
     * ticket with the supplier, then credit any cashback the rule engine
     * awards. Mirrors FlightBookingController::finalizeTicketing(), minus
     * the multi-leg loop (a bus booking has exactly one supplier ref).
     */
    protected function finalizeTicketing(Booking $booking, User $user)
    {
        if ($booking->supplier_code === BusInventoryItem::SUPPLIER_CODE) {
            $confirmResult = $this->ownInventoryConfirm($booking->supplier_booking_ref);
        } else {
            try {
                $confirmResult = $this->orchestrator->confirm($booking->tenant, $booking->supplier_code, $booking->supplier_booking_ref);
            } catch (SupplierException $e) {
                $this->statusService->transition($booking, Booking::STATUS_FAILED, $user, $e->getMessage());

                return response()->json(['message' => $e->getMessage()], 502);
            }
        }

        $booking->update(['metadata' => array_merge($booking->metadata ?? [], ['ticket_numbers' => $confirmResult->ticketNumbers])]);
        $this->statusService->transition($booking, Booking::STATUS_TICKETED, $user, 'Supplier ticketing confirmed.');

        $this->cashbackService->applyCashback($booking, $user);
        $this->commissionService->applyCommission($booking);

        return new BookingResource($booking->fresh(['passengers', 'segments']));
    }

    protected function ownBusInventoryItemFromExternalId(string $externalId): BusInventoryItem
    {
        $id = (int) str_replace('inv-', '', $externalId);

        return BusInventoryItem::query()->findOrFail($id);
    }

    protected function ownInventoryPriceCheck(string $externalId): FareRuleResult
    {
        $item = $this->ownBusInventoryItemFromExternalId($externalId);

        return new FareRuleResult(
            isStillAvailable: $item->isSellable(),
            confirmedNetAmount: (string) $item->net_price,
            isRefundable: (bool) $item->is_refundable,
        );
    }

    protected function ownInventoryHold(string $externalId): BookingHoldResult
    {
        $item = $this->ownBusInventoryItemFromExternalId($externalId);

        if (! $item->isSellable()) {
            throw new \RuntimeException('This own-inventory bus trip is no longer available.');
        }

        if ($item->capacity !== null) {
            $updated = BusInventoryItem::query()
                ->where('id', $item->id)
                ->where('capacity', '>', 0)
                ->decrement('capacity');

            if (! $updated) {
                throw new \RuntimeException('This own-inventory bus trip just sold out.');
            }
        }

        return new BookingHoldResult(
            supplierBookingRef: BusInventoryItem::SUPPLIER_CODE.'-'.$item->id.'-'.uniqid(),
            holdExpiresAt: new \DateTimeImmutable('+30 minutes'),
            netAmount: (string) $item->net_price,
        );
    }

    protected function ownInventoryConfirm(string $supplierBookingRef): BookingConfirmResult
    {
        $parts = explode('-', $supplierBookingRef);
        $itemId = $parts[1] ?? null;

        return new BookingConfirmResult(
            supplierBookingRef: $supplierBookingRef,
            isTicketed: true,
            ticketNumbers: ['OWNINV-'.($itemId ?? '0').'-'.strtoupper(substr(uniqid(), -6))],
        );
    }

    /**
     * The booking detail read for the new customer-facing flow — mirrors
     * FlightBookingController::show().
     */
    public function show(Request $request, Booking $booking)
    {
        abort_unless($booking->module === Booking::MODULE_BUS, 404);

        return new BookingResource($booking->load(['passengers', 'segments', 'statusHistory']));
    }

    protected function authorizeInventory(User $user, BusInventoryItem $item): void
    {
        if ($user->isPlatformStaff()) {
            return;
        }

        abort_if(
            $item->tenant_id !== null && $item->tenant_id !== $user->tenant_id,
            403,
            'You cannot book another tenant\'s inventory.'
        );
    }

    /**
     * Mirrors FlightBookingController::markupRoleFor() — nullable so
     * search()'s and seatMap()'s guest (anonymous) paths can call this too;
     * a guest prices exactly like a signed-in B2C customer, which 'b2c'
     * already is the default for.
     */
    protected function markupRoleFor(?User $user): string
    {
        return match ($user?->role) {
            User::ROLE_AGENT, User::ROLE_PARTNER_ADMIN => 'agent',
            User::ROLE_SUB_AGENT => 'sub_agent',
            default => 'b2c',
        };
    }
}
