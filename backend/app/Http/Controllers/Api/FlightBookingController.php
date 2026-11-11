<?php

namespace App\Http\Controllers\Api;

use App\Domains\Booking\Models\Booking;
use App\Domains\Booking\Models\BookingAncillary;
use App\Domains\Booking\Models\BookingPassenger;
use App\Domains\Booking\Services\BookingStatusService;
use App\Domains\Booking\Services\RouteClassificationService;
use App\Domains\Cashback\Services\CashbackService;
use App\Domains\Commission\Services\CommissionService;
use App\Domains\Coupons\Exceptions\InvalidCouponException;
use App\Domains\Coupons\Services\CouponService;
use App\Domains\Inventory\Models\FlightInventoryItem;
use App\Domains\Payments\Models\PaymentMethodRoute;
use App\Domains\Payments\Models\PaymentTransaction;
use App\Domains\Payments\Services\PaymentGatewayService;
use App\Domains\Suppliers\DTOs\BookingConfirmResult;
use App\Domains\Suppliers\DTOs\BookingHoldResult;
use App\Domains\Suppliers\DTOs\FareRuleResult;
use App\Domains\Suppliers\DTOs\SearchRequest;
use App\Domains\Suppliers\Exceptions\SupplierException;
use App\Domains\Suppliers\Services\FlightSeatMapService;
use App\Domains\Suppliers\Services\MarkupService;
use App\Domains\Suppliers\Services\SupplierOrchestrator;
use Barryvdh\DomPDF\Facade\Pdf;
use App\Domains\Wallet\Exceptions\InsufficientBalanceException;
use App\Domains\Wallet\Models\Wallet;
use App\Domains\Wallet\Models\WalletLedgerEntry;
use App\Domains\Wallet\Services\WalletService;
use App\Domains\Tenancy\Services\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Resources\BookingResource;
use App\Http\Resources\UserResource;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Demonstrates the full mock booking pipeline described in the Master Brief
 * (§6 Supplier integration, §7 Wallet) end to end: search -> hold -> pick
 * seats/meals/baggage/insurance -> apply a coupon -> confirm (pay), against
 * MockFlightSupplierAdapter until real TBO/TripJack/Akbar credentials are
 * configured. The same endpoints, unchanged in shape, will drive a real
 * supplier once one is registered in config/suppliers.php — only the
 * adapter class changes.
 */
class FlightBookingController extends Controller
{
    /** Share of the adult fare a lap infant (no seat) is charged. */
    public const INFANT_FARE_FACTOR = '0.10';

    public function __construct(
        protected SupplierOrchestrator $orchestrator,
        protected MarkupService $markupService,
        protected WalletService $walletService,
        protected BookingStatusService $statusService,
        protected FlightSeatMapService $seatMapService,
        protected CouponService $couponService,
        protected RouteClassificationService $routeClassificationService,
        protected PaymentGatewayService $paymentGatewayService,
        protected CashbackService $cashbackService,
        protected CommissionService $commissionService,
    ) {}

    public const CABIN_CLASSES = ['economy', 'premium_economy', 'business', 'first'];

    public const FARE_TYPES = ['regular', 'student', 'senior_citizen', 'armed_forces'];

    /**
     * "In flight bookings need group Bookings ... These group bookings only
     * shown to b2b not to b2c users" — the instant-bulk half of that ask
     * (the other half, staff-quoted deposit/fare-hold/name-list group
     * requests, is GroupBookingController). A B2C customer stays capped at
     * the standard single-PNR GDS limit; any B2B-capable role (everyone
     * with booking.create except customer) may search/hold up to
     * MAX_PASSENGERS_B2B_BULK directly, at the listed fare, no staff
     * involvement — same hold()/confirm()/wallet-or-gateway payment path
     * every other booking already uses.
     */
    public const MAX_PASSENGERS_STANDARD = 9;

    public const MAX_PASSENGERS_B2B_BULK = 20;

    protected function maxPassengersFor(?User $user): int
    {
        if (! $user || $user->role === User::ROLE_CUSTOMER) {
            return self::MAX_PASSENGERS_STANDARD;
        }

        return self::MAX_PASSENGERS_B2B_BULK;
    }

    /**
     * Metro clusters that Google Flights/Skyscanner-style "nearby airports"
     * cross-shopping is meaningful for in the Indian market — commuting
     * distance alternates a traveller would genuinely consider (Mumbai <->
     * Pune; old Goa/Dabolim <-> the newer Mopa airport). Only used when the
     * search request opts in via `include_nearby_airports`.
     */
    public const NEARBY_AIRPORTS = [
        'BOM' => ['BOM', 'PNQ'],
        'PNQ' => ['PNQ', 'BOM'],
        'GOI' => ['GOI', 'GOX'],
        'GOX' => ['GOX', 'GOI'],
    ];

    /**
     * One-way, round-trip, and multi-city are all just "one or more legs to
     * search" — a one-way is a single leg, a round-trip is two legs (the
     * second with origin/destination swapped), multi-city is 2-6 arbitrary
     * legs the customer builds directly. Each leg is searched and priced
     * independently (same route classification/markup rules as before)
     * and returned as its own array so the frontend can let the customer
     * pick one flight per leg, exactly like Tripodeal/MakeMyTrip/EaseMyTrip
     * do for round-trip and multi-city journeys.
     */
    public function search(Request $request)
    {
        $tripType = $request->input('trip_type', 'one_way');
        $maxPassengers = $this->maxPassengersFor($request->user());

        $data = $request->validate([
            'trip_type' => ['nullable', 'in:one_way,round_trip,multi_city'],
            'cabin_class' => ['nullable', 'in:'.implode(',', self::CABIN_CLASSES)],
            'fare_type' => ['nullable', 'in:'.implode(',', self::FARE_TYPES)],
            // Per-field cap is the wider B2B-bulk bound regardless of who's
            // asking — the real ceiling (9 for a guest/customer) is the
            // $passengerCount check just below, which also gives a clearer
            // "why" than a generic per-field validation message would.
            'adults' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_PASSENGERS_B2B_BULK],
            'children' => ['nullable', 'integer', 'min:0', 'max:'.self::MAX_PASSENGERS_B2B_BULK],
            'infants' => ['nullable', 'integer', 'min:0', 'max:'.self::MAX_PASSENGERS_B2B_BULK],
            'include_nearby_airports' => ['nullable', 'boolean'],
            // one_way / round_trip shape:
            'origin' => ['required_unless:trip_type,multi_city', 'string', 'size:3'],
            'destination' => ['required_unless:trip_type,multi_city', 'string', 'size:3'],
            'departure_date' => ['required_unless:trip_type,multi_city', 'date_format:Y-m-d', 'after_or_equal:today'],
            'return_date' => ['nullable', 'date_format:Y-m-d', 'after:departure_date'],
            // multi_city shape:
            'legs' => ['required_if:trip_type,multi_city', 'array', 'min:2', 'max:6'],
            'legs.*.origin' => ['required_with:legs', 'string', 'size:3'],
            'legs.*.destination' => ['required_with:legs', 'string', 'size:3'],
            'legs.*.departure_date' => ['required_with:legs', 'date_format:Y-m-d', 'after_or_equal:today'],
        ]);

        $adults = $data['adults'] ?? 1;
        $children = $data['children'] ?? 0;
        $infants = $data['infants'] ?? 0;

        if ($infants > $adults) {
            return response()->json(['message' => 'Each infant must travel with an adult — you cannot have more infants than adults.'], 422);
        }

        $passengerCount = $adults + $children + $infants;

        if ($passengerCount > $maxPassengers) {
            return response()->json([
                'message' => $maxPassengers === self::MAX_PASSENGERS_STANDARD
                    ? "You can search for up to {$maxPassengers} passengers at once. For more, request a Group Booking quote instead."
                    : "You can book up to {$maxPassengers} passengers at once here. For a larger group, submit a Group Booking request instead.",
            ], 422);
        }
        $cabinClass = $data['cabin_class'] ?? 'economy';
        $fareType = $data['fare_type'] ?? 'regular';

        $legs = match ($tripType) {
            'round_trip' => [
                ['origin' => strtoupper($data['origin']), 'destination' => strtoupper($data['destination']), 'departure_date' => $data['departure_date'], 'label' => 'Onward'],
                ['origin' => strtoupper($data['destination']), 'destination' => strtoupper($data['origin']), 'departure_date' => $data['return_date'], 'label' => 'Return'],
            ],
            'multi_city' => array_map(fn ($leg, $i) => [
                'origin' => strtoupper($leg['origin']),
                'destination' => strtoupper($leg['destination']),
                'departure_date' => $leg['departure_date'],
                'label' => 'Flight '.($i + 1),
            ], $data['legs'], array_keys($data['legs'])),
            default => [
                ['origin' => strtoupper($data['origin']), 'destination' => strtoupper($data['destination']), 'departure_date' => $data['departure_date'], 'label' => 'Flight'],
            ],
        };

        if ($tripType === 'round_trip' && empty($data['return_date'])) {
            return response()->json(['message' => 'A return date is required for a round trip search.'], 422);
        }

        // Guest-accessible — "browse without login, login required only to
        // book" (Platform Roadmap's B2C public storefront). $user is null
        // for an anonymous visitor; the acting tenant then falls back to
        // whichever storefront ResolveTenant matched from the request Host
        // header (same TenantContext HolidayPackageSearchController already
        // uses for guest package browsing), rather than a logged-in user's
        // own tenant_id.
        $user = $request->user();
        $tenant = $user?->tenant ?? app(\App\Domains\Tenancy\Services\TenantContext::class)->get();
        $role = $this->markupRoleFor($user);
        $includeNearby = $request->boolean('include_nearby_airports');
        $canViewMargin = $user?->hasPermission('pricing.view_margin') ?? false;

        abort_if(! $tenant, 422, 'Unable to resolve which storefront to search against.');

        $legResults = [];

        foreach ($legs as $sequence => $leg) {
            $originCandidates = $includeNearby ? (self::NEARBY_AIRPORTS[$leg['origin']] ?? [$leg['origin']]) : [$leg['origin']];
            $destinationCandidates = $includeNearby ? (self::NEARBY_AIRPORTS[$leg['destination']] ?? [$leg['destination']]) : [$leg['destination']];

            $offers = [];

            foreach ($originCandidates as $originCandidate) {
                foreach ($destinationCandidates as $destinationCandidate) {
                    if ($originCandidate === $destinationCandidate) {
                        continue;
                    }

                    $searchRequest = new SearchRequest(
                        module: Booking::MODULE_FLIGHT,
                        origin: $originCandidate,
                        destination: $destinationCandidate,
                        departureDate: $leg['departure_date'],
                        passengerCount: $passengerCount,
                        extra: ['cabin_class' => $cabinClass, 'fare_type' => $fareType],
                    );

                    array_push($offers, ...$this->orchestrator->search($tenant, $searchRequest));
                }
            }

            array_push($offers, ...$this->ownInventoryOffers($tenant, $leg['origin'], $leg['destination'], $leg['departure_date']));

            $legIsInternational = $this->routeClassificationService->isInternationalCode($leg['origin'], $leg['destination']);

            $offers = array_map(function (array $item) use ($tenant, $role, $leg, $passengerCount, $legIsInternational) {
                // Results show the fare per adult, so price one passenger;
                // hold() prices the whole party.
                $pricing = $this->markupService->applyMarkup($tenant, Booking::MODULE_FLIGHT, $role, $item['net_amount'], [
                    'passenger_count' => 1,
                    'origin' => $leg['origin'],
                    'destination' => $leg['destination'],
                    'is_international' => $legIsInternational,
                    'supplier_code' => $item['supplier_code'] ?? null,
                ]);

                return array_merge($item, $pricing);
            }, $offers);

            usort($offers, fn ($a, $b) => $a['gross_amount'] <=> $b['gross_amount']);

            // "Margin need to show to all even i am keeping markup to super
            // admin" — net_amount/markup_amount used to go out to every
            // caller regardless of role (the frontend just chose not to
            // render them prominently). Now actually withheld from the API
            // response unless the acting user holds pricing.view_margin
            // (implicitly only super_admin today — see HasPermissions), so
            // an agent/customer can no longer read supplier cost or margin
            // out of the network response either. gross_amount (the actual
            // sell price) is unaffected either way.
            if (! $canViewMargin) {
                $offers = array_map(function (array $offer) {
                    unset($offer['net_amount'], $offer['markup_amount']);

                    return $offer;
                }, $offers);
            }

            $legResults[] = [
                'sequence' => $sequence + 1,
                'label' => $leg['label'],
                'origin' => $leg['origin'],
                'destination' => $leg['destination'],
                'departure_date' => $leg['departure_date'],
                'offers' => $offers,
            ];
        }

        return response()->json([
            'data' => [
                'trip_type' => $tripType,
                'cabin_class' => $cabinClass,
                'fare_type' => $fareType,
                'can_view_margin' => $canViewMargin,
                'adults' => $adults,
                'children' => $children,
                'infants' => $infants,
                'legs' => $legResults,
            ],
        ]);
    }

    public function ancillaryCatalog()
    {
        return response()->json(['data' => config('ancillaries')]);
    }

    /**
     * A tenant's own manually-entered flight inventory (see FlightInventoryItem)
     * for this exact origin/destination/date, shaped and merged into the
     * offer list exactly like a real supplier's results — the frontend
     * can't tell the difference, and price/duration/stops filters and
     * sorting all just work. Visibility mirrors
     * HolidayPackageSearchController::scopeVisibleTo(): the platform-wide
     * (tenant_id NULL) catalog, this tenant's own rows, and anything opted
     * into share_with_network / share_on_platform_site.
     */
    protected function ownInventoryOffers($tenant, string $origin, string $destination, string $departureDate): array
    {
        $tenantId = $tenant?->id;

        $items = FlightInventoryItem::query()
            ->where('status', FlightInventoryItem::STATUS_ACTIVE)
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

        return $items->filter->isSellable()->map(fn (FlightInventoryItem $item) => $item->toFlightOfferArray())->values()->all();
    }

    /**
     * Holds ONE Booking covering every leg the customer picked — one flight
     * per leg for round-trip/multi-city, exactly one leg for one-way. Each
     * leg still goes through its own supplier priceCheck()/holdBooking()
     * call (a real GDS never lets you skip re-confirming a fare right
     * before hold), and the booking's gross/net amounts are the sum across
     * legs. confirm()/payGateway() below settle that combined total once;
     * finalizeTicketing() then confirms every leg's supplier hold in turn.
     */
    public function hold(Request $request)
    {
        return $this->performHold($request, $request->user());
    }

    /**
     * Guest checkout — "from home page to till booking, only during the
     * payment page you may ask book as guest or login and book", same
     * pattern already established for Holiday Packages (see
     * HolidayBookingController::guestStore()'s docblock): no auth:sanctum
     * on this route (see routes/api.php), so a browsing visitor reaches
     * this only once they've searched, picked a fare, and filled in
     * passenger details — right where hold() would otherwise require an
     * account. A real customer account is transparently created from the
     * guest contact details given here and logged straight into (the
     * frontend dispatches the returned token the same way a normal login
     * does), so seats/extras/coupon/wallet-or-gateway payment and viewing
     * this booking again later all work exactly like any other customer
     * from this point on.
     *
     * If the email already belongs to an existing account this refuses to
     * silently issue a token for it — same anti-account-takeover guard as
     * the Holiday Packages guest flow.
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
        // (409/422/502) on a failed fare re-check, which should pass
        // through untouched. The guest account still exists at that point
        // (same trade-off HolidayBookingController::guestStore() already
        // accepts) — they can simply try again, now effectively logged in.
        if ($response->getStatusCode() === 201) {
            $payload = $response->getData(true);
            $payload['user'] = (new UserResource($user))->toArray($request);
            $payload['token'] = $user->issueApiToken();
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
        $maxPassengers = $this->maxPassengersFor($user);

        $data = $request->validate([
            'legs' => ['required', 'array', 'min:1', 'max:6'],
            'legs.*.supplier_code' => ['required', 'string'],
            'legs.*.external_id' => ['required', 'string'],
            'legs.*.segments' => ['nullable', 'array'],
            'travel_date' => ['required', 'date_format:Y-m-d'],
            'travel_date_end' => ['nullable', 'date_format:Y-m-d'],
            'contact_email' => ['nullable', 'email', 'max:191'],
            'contact_phone' => ['nullable', 'string', 'max:30'],
            'whatsapp_opt_in' => ['nullable', 'boolean'],
            // Standard customer stays at the classic single-PNR GDS cap of
            // 9; any B2B-capable role may hold up to MAX_PASSENGERS_B2B_BULK
            // in one go — see maxPassengersFor()'s docblock.
            'passengers' => ['required', 'array', 'min:1', "max:{$maxPassengers}"],
            'passengers.*.title' => ['nullable', 'string', 'in:Mr,Mrs,Ms,Miss,Master,Dr'],
            'passengers.*.first_name' => ['required', 'string', 'max:100'],
            'passengers.*.last_name' => ['required', 'string', 'max:100'],
            'passengers.*.passenger_type' => ['nullable', 'in:adult,child,infant'],
            'passengers.*.dob' => ['nullable', 'date'],
            'passengers.*.gender' => ['nullable', 'in:male,female,other,unspecified'],
            'passengers.*.document_type' => ['nullable', 'in:passport,aadhaar,pan,voter_id,driving_license,other'],
            'passengers.*.document_number' => ['nullable', 'string', 'max:60'],
            'passengers.*.passport_issue_date' => ['nullable', 'date'],
            'passengers.*.passport_expiry_date' => ['nullable', 'date'],
            'passengers.*.passport_country' => ['nullable', 'string', 'size:2'],
        ]);

        // International trips need each traveller's passport: number,
        // date of birth, and an expiry after the last travel date.
        $international = collect($data['legs'])->flatMap(fn ($leg) => $leg['segments'] ?? [])
            ->contains(fn ($seg) => is_array($seg) && $this->routeClassificationService->isInternationalCode($seg['origin'] ?? null, $seg['destination'] ?? null));
        if ($international) {
            $lastDate = $data['travel_date_end'] ?? $data['travel_date'];
            $request->validate([
                'passengers.*.document_number' => ['required', 'string', 'min:5', 'max:20'],
                'passengers.*.dob' => ['required', 'date', 'before:today'],
                'passengers.*.passport_expiry_date' => ['required', 'date', 'after:'.$lastDate],
            ], [
                'passengers.*.document_number.required' => 'Passport number is required for international travel.',
                'passengers.*.dob.required' => 'Date of birth is required for international travel.',
                'passengers.*.passport_expiry_date.required' => 'Passport expiry date is required for international travel.',
                'passengers.*.passport_expiry_date.after' => 'The passport must be valid beyond the travel date.',
            ]);
        }

        $tenant = $user->tenant;
        $role = $this->markupRoleFor($user);

        $totalNet = \Brick\Math\BigDecimal::of('0.00');
        $totalGross = \Brick\Math\BigDecimal::of('0.00');
        $totalMarkup = \Brick\Math\BigDecimal::of('0.00');
        $legHolds = [];
        $passengerCount = count($data['passengers']);
        // Results quote the fare "per adult"; the booking is for every
        // passenger. Adults and children each take a seat and pay the fare;
        // a lap infant pays INFANT_FARE_FACTOR of it.
        $infantCount = collect($data['passengers'])->where('passenger_type', 'infant')->count();
        $fareUnits = BigDecimal::of($passengerCount - $infantCount)
            ->plus(BigDecimal::of(self::INFANT_FARE_FACTOR)->multipliedBy($infantCount));

        foreach ($data['legs'] as $leg) {
            if ($leg['supplier_code'] === FlightInventoryItem::SUPPLIER_CODE) {
                $fareRule = $this->ownInventoryPriceCheck($leg['external_id']);
            } else {
                try {
                    $fareRule = $this->orchestrator->priceCheck($tenant, $leg['supplier_code'], $leg['external_id']);
                } catch (SupplierException $e) {
                    return response()->json(['message' => $e->getMessage()], 502);
                }
            }

            if (! $fareRule->isStillAvailable) {
                return response()->json(['message' => 'One of the selected fares is no longer available. Please search again.'], 409);
            }

            $firstSegment = $leg['segments'][0] ?? null;
            $legNet = (string) BigDecimal::of($fareRule->confirmedNetAmount)->multipliedBy($fareUnits)->toScale(2, RoundingMode::HALF_UP);
            $pricing = $this->markupService->applyMarkup($tenant, Booking::MODULE_FLIGHT, $role, $legNet, [
                'passenger_count' => $passengerCount,
                'origin' => $firstSegment['origin'] ?? null,
                'destination' => $firstSegment['destination'] ?? null,
                // Computed straight from this leg's own codes rather than
                // Booking::is_international, which isn't set until after
                // every leg is held and segments are persisted (see the
                // is_international update further down in this method).
                'is_international' => $this->routeClassificationService->isInternationalCode($firstSegment['origin'] ?? null, $firstSegment['destination'] ?? null),
                'supplier_code' => $leg['supplier_code'],
            ]);

            $totalNet = $totalNet->plus($legNet);
            $totalGross = $totalGross->plus($pricing['gross_amount']);
            $totalMarkup = $totalMarkup->plus($pricing['markup_amount']);

            // The supplier's own priceCheck() re-confirmation is the
            // authoritative source for refundability (it can differ from
            // what search-time quoted) — recorded per leg here so it can
            // override whatever the client submitted when segments are
            // persisted below, rather than trusting a client-supplied flag.
            $legHolds[] = ['leg' => $leg, 'is_refundable' => $fareRule->isRefundable];
        }

        $booking = Booking::create([
            'tenant_id' => $tenant->id,
            'created_by_user_id' => $user->id,
            'channel' => Booking::channelForTenant($tenant),
            'module' => Booking::MODULE_FLIGHT,
            'supplier_code' => $data['legs'][0]['supplier_code'],
            'gross_amount' => (string) $totalGross->toScale(2, \Brick\Math\RoundingMode::HALF_UP),
            'supplier_net_amount' => (string) $totalNet->toScale(2, \Brick\Math\RoundingMode::HALF_UP),
            'markup_amount' => (string) $totalMarkup->toScale(2, \Brick\Math\RoundingMode::HALF_UP),
            'travel_date_start' => $data['travel_date'],
            'travel_date_end' => $data['travel_date_end'] ?? null,
            'passenger_count' => count($data['passengers']),
            'contact_email' => $data['contact_email'] ?? $user->email,
            'contact_phone' => $data['contact_phone'] ?? $user->phone,
            // WhatsApp opt-in is a metadata flag rather than its own column —
            // it's just a preference on how to notify this booking's contact
            // number, read by whichever WhatsApp gateway integration sends
            // booking updates (see NotificationGatewayConfig), not a
            // structural booking field.
            'metadata' => ['whatsapp_opt_in' => $data['whatsapp_opt_in'] ?? false],
        ]);

        foreach ($data['passengers'] as $passenger) {
            $booking->passengers()->create([
                ...$passenger,
                'name' => trim($passenger['first_name'].' '.$passenger['last_name']),
                'passenger_type' => $passenger['passenger_type'] ?? BookingPassenger::TYPE_ADULT,
            ]);
        }

        $sequence = 1;
        foreach ($data['legs'] as $legIndex => $leg) {
            // Authoritative refundability from this leg's own priceCheck()
            // re-confirmation (see $legHolds above) — overrides whatever
            // the client sent, since that's derived from the search-time
            // quote and shouldn't be trusted for what prints on the ticket.
            $confirmedRefundable = $legHolds[$legIndex]['is_refundable'] ?? null;

            foreach ($leg['segments'] ?? [] as $segment) {
                $rawPayload = $segment;
                if ($confirmedRefundable !== null) {
                    $rawPayload['is_refundable'] = $confirmedRefundable;
                }

                $booking->segments()->create([
                    'sequence' => $sequence++,
                    'origin' => $segment['origin'] ?? null,
                    'destination' => $segment['destination'] ?? null,
                    'departure_at' => $segment['departure_at'] ?? null,
                    'arrival_at' => $segment['arrival_at'] ?? null,
                    'carrier_or_provider' => $segment['carrier_or_provider'] ?? null,
                    'fare_basis' => $segment['fare_basis'] ?? null,
                    'raw_supplier_payload' => $rawPayload,
                ]);
            }
        }

        // "International ticket booking: travel date to min 6 month need
        // passport validity, not on the booking date, on the travel/
        // journey date" — checked here (segments are already persisted, so
        // RouteClassificationService can classify the route) and BEFORE
        // any supplier hold call, so a passenger with an invalid/missing
        // passport fails fast without spending a supplier hold on a
        // booking that could never be confirmed anyway.
        if ($this->routeClassificationService->isInternational($booking->fresh('segments'))) {
            $travelDate = \Carbon\Carbon::parse($data['travel_date']);
            $minExpiry = $travelDate->copy()->addMonths(6);

            foreach ($data['passengers'] as $index => $passenger) {
                $expiry = $passenger['passport_expiry_date'] ?? null;
                $documentType = $passenger['document_type'] ?? null;
                $documentNumber = $passenger['document_number'] ?? null;

                $problem = match (true) {
                    $documentType !== 'passport' || ! $documentNumber => 'needs a passport number',
                    ! $expiry => 'needs a passport expiry date',
                    \Carbon\Carbon::parse($expiry)->lt($minExpiry) => "'s passport must be valid for at least 6 months after the travel date (".$travelDate->toDateString().'), not just past the booking date',
                    default => null,
                };

                if ($problem !== null) {
                    $this->statusService->transition($booking, Booking::STATUS_FAILED, $user, 'International booking rejected: passport validation failed.');

                    return response()->json([
                        'message' => 'Passenger '.($index + 1).' '.$problem.' for this international booking.',
                    ], 422);
                }
            }
        }

        $legSupplierRefs = [];
        $earliestHoldExpiry = null;

        foreach ($data['legs'] as $leg) {
            if ($leg['supplier_code'] === FlightInventoryItem::SUPPLIER_CODE) {
                try {
                    $hold = $this->ownInventoryHold($leg['external_id']);
                } catch (\RuntimeException $e) {
                    $this->statusService->transition($booking, Booking::STATUS_FAILED, $user, $e->getMessage());

                    return response()->json(['message' => $e->getMessage()], 409);
                }
            } else {
                try {
                    $hold = $this->orchestrator->hold($tenant, $leg['supplier_code'], $leg['external_id'], $data['passengers']);
                } catch (SupplierException $e) {
                    $this->statusService->transition($booking, Booking::STATUS_FAILED, $user, $e->getMessage());

                    return response()->json(['message' => $e->getMessage()], 502);
                }
            }

            $legSupplierRefs[] = ['supplier_code' => $leg['supplier_code'], 'supplier_booking_ref' => $hold->supplierBookingRef];

            if ($earliestHoldExpiry === null || $hold->holdExpiresAt < $earliestHoldExpiry) {
                $earliestHoldExpiry = $hold->holdExpiresAt;
            }
        }

        $booking->load('segments');
        $booking->update([
            'supplier_booking_ref' => $legSupplierRefs[0]['supplier_booking_ref'],
            'is_international' => $this->routeClassificationService->isInternational($booking),
            'metadata' => array_merge($booking->metadata ?? [], ['leg_supplier_refs' => $legSupplierRefs]),
        ]);
        $this->statusService->transition($booking, Booking::STATUS_HELD, $user, 'Supplier hold confirmed.');

        return response()->json([
            'data' => new BookingResource($booking->fresh(['passengers', 'segments'])),
            'hold_expires_at' => $earliestHoldExpiry->format(DATE_ATOM),
        ], 201);
    }

    /**
     * Seat layout for the (single-segment) flight this booking is on, with
     * availability worked out from the deterministic mock map plus real
     * flight_seat_locks reservations — see FlightSeatMapService.
     */
    public function seatMap(Request $request, Booking $booking)
    {
        $segment = $booking->segments()->orderBy('sequence')->first();

        if (! $segment || ! ($segment->raw_supplier_payload['flight_number'] ?? null)) {
            return response()->json(['message' => 'No flight is attached to this booking yet.'], 422);
        }

        $flightNumber = $segment->raw_supplier_payload['flight_number'];
        $aircraftType = $segment->raw_supplier_payload['aircraft_type'] ?? 'Airbus A320';
        $travelDate = $booking->travel_date_start->toDateString();

        $map = $this->seatMapService->generate($flightNumber, $travelDate, $aircraftType);

        $currentSelections = $booking->passengers()
            ->whereNotNull('seat_number')
            ->pluck('seat_number', 'id');

        return response()->json([
            'data' => $map,
            'current_selections' => $currentSelections,
            'extra_legroom_fee' => FlightSeatMapService::EXTRA_LEGROOM_FEE,
        ]);
    }

    /**
     * Seats + meals + baggage + insurance + misc. services, one entry per
     * passenger. Re-submittable while the booking is still 'held' — each
     * call replaces the booking's previous selections from scratch.
     */
    public function extras(Request $request, Booking $booking)
    {
        if ($booking->status !== Booking::STATUS_HELD) {
            return response()->json(['message' => "Extras can only be set while a booking is 'held'; it is currently '{$booking->status}'."], 409);
        }

        $data = $request->validate([
            'passengers' => ['required', 'array', 'min:1'],
            'passengers.*.passenger_id' => ['required', 'integer'],
            'passengers.*.seat_number' => ['nullable', 'string', 'max:5'],
            'passengers.*.meal_code' => ['nullable', 'string'],
            'passengers.*.baggage_code' => ['nullable', 'string'],
            'passengers.*.insurance_code' => ['nullable', 'string'],
            'passengers.*.other_codes' => ['nullable', 'array'],
            'passengers.*.other_codes.*' => ['string'],
        ]);

        $passengerIds = $booking->passengers()->pluck('id')->all();

        foreach ($data['passengers'] as $row) {
            if (! in_array($row['passenger_id'], $passengerIds, true)) {
                return response()->json(['message' => 'One or more passengers do not belong to this booking.'], 422);
            }
        }

        $segment = $booking->segments()->orderBy('sequence')->first();
        $flightNumber = $segment?->raw_supplier_payload['flight_number'] ?? null;
        $aircraftType = $segment?->raw_supplier_payload['aircraft_type'] ?? 'Airbus A320';
        $travelDate = $booking->travel_date_start->toDateString();

        $catalog = config('ancillaries');
        $catalogIndex = [];
        foreach (['meals', 'baggage', 'other', 'insurance'] as $group) {
            foreach ($catalog[$group] as $entry) {
                $catalogIndex[$entry['code']] = $entry;
            }
        }

        try {
            DB::transaction(function () use ($data, $booking, $flightNumber, $aircraftType, $travelDate, $catalogIndex) {
                // Wipe previous selections so this endpoint is safely
                // re-submittable (e.g. the customer goes back a step).
                $this->seatMapService->releaseForBooking($booking->id);
                $booking->ancillaries()->delete();
                $booking->passengers()->update(['seat_number' => null]);

                $extrasTotal = \Brick\Math\BigDecimal::of('0.00');

                foreach ($data['passengers'] as $row) {
                    $passenger = $booking->passengers()->findOrFail($row['passenger_id']);

                    if (! empty($row['seat_number']) && $flightNumber) {
                        $seatNumber = strtoupper($row['seat_number']);
                        $map = $this->seatMapService->generate($flightNumber, $travelDate, $aircraftType);
                        $seatInfo = collect($map['rows'])->flatMap(fn ($r) => $r['seats'])->firstWhere('seat_number', $seatNumber);

                        if (! $seatInfo || ! $seatInfo['available']) {
                            throw new \RuntimeException("Seat {$seatNumber} is not available.");
                        }

                        $this->seatMapService->reserve($flightNumber, $travelDate, $seatNumber, $booking->id, $passenger->id);
                        $passenger->update(['seat_number' => $seatNumber]);

                        if ((float) $seatInfo['fare'] > 0) {
                            $booking->ancillaries()->create([
                                'booking_passenger_id' => $passenger->id,
                                'category' => BookingAncillary::CATEGORY_OTHER,
                                'code' => 'extra_legroom_seat',
                                'label' => "Extra legroom seat {$seatNumber}",
                                'amount' => $seatInfo['fare'],
                            ]);
                            $extrasTotal = $extrasTotal->plus($seatInfo['fare']);
                        }
                    }

                    foreach (['meal_code' => BookingAncillary::CATEGORY_MEAL, 'baggage_code' => BookingAncillary::CATEGORY_BAGGAGE, 'insurance_code' => BookingAncillary::CATEGORY_INSURANCE] as $field => $category) {
                        $code = $row[$field] ?? null;

                        if (! $code || ! isset($catalogIndex[$code]) || (float) $catalogIndex[$code]['amount'] <= 0) {
                            continue;
                        }

                        $entry = $catalogIndex[$code];
                        $booking->ancillaries()->create([
                            'booking_passenger_id' => $passenger->id,
                            'category' => $category,
                            'code' => $code,
                            'label' => $entry['label'],
                            'amount' => $entry['amount'],
                        ]);
                        $extrasTotal = $extrasTotal->plus($entry['amount']);
                    }

                    foreach ($row['other_codes'] ?? [] as $code) {
                        if (! isset($catalogIndex[$code]) || (float) $catalogIndex[$code]['amount'] <= 0) {
                            continue;
                        }

                        $entry = $catalogIndex[$code];
                        $booking->ancillaries()->create([
                            'booking_passenger_id' => $passenger->id,
                            'category' => BookingAncillary::CATEGORY_OTHER,
                            'code' => $code,
                            'label' => $entry['label'],
                            'amount' => $entry['amount'],
                        ]);
                        $extrasTotal = $extrasTotal->plus($entry['amount']);
                    }
                }

                $booking->extras_amount = (string) $extrasTotal->toScale(2, \Brick\Math\RoundingMode::HALF_UP);
                $booking->total_amount = $booking->recalculateTotal();
                $booking->save();
            });
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return new BookingResource($booking->fresh(['passengers', 'segments', 'ancillaries']));
    }

    public function applyCoupon(Request $request, Booking $booking)
    {
        if ($booking->status !== Booking::STATUS_HELD) {
            return response()->json(['message' => "A coupon can only be applied while a booking is 'held'; it is currently '{$booking->status}'."], 409);
        }

        $data = $request->validate(['code' => ['required', 'string', 'max:30']]);
        $user = $request->user();

        $baseAmount = \Brick\Math\BigDecimal::of((string) $booking->gross_amount)
            ->plus((string) $booking->extras_amount);

        try {
            $coupon = $this->couponService->validate($data['code'], $user->tenant, $user, $booking->module, (string) $baseAmount);
        } catch (InvalidCouponException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $discount = $this->couponService->calculateDiscount($coupon, (string) $baseAmount);

        $booking->coupon_code = $coupon->code;
        $booking->discount_amount = $discount;
        $booking->total_amount = $booking->recalculateTotal();
        $booking->save();

        return new BookingResource($booking->fresh(['passengers', 'segments', 'ancillaries']));
    }

    public function removeCoupon(Request $request, Booking $booking)
    {
        $booking->coupon_code = null;
        $booking->discount_amount = '0.00';
        $booking->total_amount = $booking->recalculateTotal();
        $booking->save();

        return new BookingResource($booking->fresh(['passengers', 'segments', 'ancillaries']));
    }

    /**
     * "Need option to adjust the wallet amount and pay balance through
     * payment gateway, or full pay through payment gateway by unchecking
     * the wallet" — the customer picks how much of the payable amount
     * (0 up to the full amount) to draw from their wallet; whatever's left
     * is routed to the payment gateway. Paying 100% from the wallet
     * confirms and tickets immediately, same single-step experience as
     * before. Any remainder — including "no wallet at all" when
     * wallet_amount is 0 or omitted — opens a PaymentTransaction and hands
     * the frontend a "requires_payment" response; the booking stays 'held'
     * until payGateway() below reports the gateway succeeded, and that's
     * also where the wallet leg (if any) actually gets debited — see its
     * docblock for why the debit is deferred rather than happening here.
     *
     * International bookings still may never be paid by a direct wallet
     * debit (they may still top up/recharge the wallet separately via
     * WalletController::topup — that's unrelated to how a *booking* is
     * paid), so wallet_amount must be zero for those; the frontend hides
     * the wallet option entirely for an international booking.
     */
    public function confirm(Request $request, Booking $booking)
    {
        $user = $request->user();

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

        if ($booking->is_international && $requestedWalletAmount->isGreaterThan(0)) {
            return response()->json(['message' => 'Wallet payment is not available for international bookings — pay the full amount via payment gateway.'], 422);
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

        // Fully covered by the wallet — confirm and ticket in one step,
        // exactly like the old domestic-only flow.
        if (! $remainder->isPositive()) {
            $couponCode = $booking->coupon_code;
            $discountAmount = (string) $booking->discount_amount;

            try {
                DB::transaction(function () use ($booking, $wallet, $payable, $walletAmount, $couponCode, $discountAmount, $user) {
                    if ($couponCode) {
                        $this->couponService->redeemWithAmount($couponCode, $booking->tenant, $user, $booking, $discountAmount);
                    }

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
            } catch (InvalidCouponException $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            }

            $this->statusService->transition($booking, Booking::STATUS_CONFIRMED, $user, 'Wallet payment captured.');

            return $this->finalizeTicketing($booking, $user);
        }

        // Partial (or zero) wallet + gateway for the remainder — nothing is
        // debited yet, see payGateway().
        $transaction = $this->paymentGatewayService->initiate(
            $booking,
            (string) $remainder,
            $walletAmount,
            $requestedWalletAmount->isGreaterThan(0) ? $wallet : null,
        );

        return response()->json([
            'requires_payment' => true,
            'data' => new BookingResource($booking->fresh(['passengers', 'segments', 'ancillaries'])),
            'payment' => [
                'transaction_id' => $transaction->id,
                'gateway' => $transaction->gateway,
                'amount' => $transaction->amount,
                'wallet_amount_applied' => $walletAmount,
                'currency' => $transaction->currency,
                // So the frontend's method picker can show which gateway
                // each option (credit card, UPI, ...) will route to
                // before the customer commits to one.
                'available_methods' => $this->paymentGatewayService->availableMethods($booking->tenant),
            ],
        ]);
    }

    /**
     * "The gateway succeeded" — the server-to-server webhook moment a real
     * Razorpay/PayU/etc. integration would drive; here it's called by the
     * frontend's mock payment step (see PaymentGatewayService's docblock).
     * Valid for any booking (domestic or international) with a pending
     * transaction — confirm() opens one whenever the payable amount isn't
     * fully covered by the wallet.
     *
     * The wallet leg recorded on the transaction (if any — see confirm())
     * is debited right here, in the same DB transaction as the gateway
     * settle, so the two either both happen or neither does: a customer
     * who never finishes this step never has their wallet touched, even
     * though confirm() already decided the split.
     */
    public function payGateway(Request $request, Booking $booking)
    {
        $user = $request->user();

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
        $couponCode = $booking->coupon_code;
        $discountAmount = (string) $booking->discount_amount;

        try {
            DB::transaction(function () use ($booking, $transaction, $payable, $couponCode, $discountAmount, $user, $method) {
                if ($couponCode) {
                    $this->couponService->redeemWithAmount($couponCode, $booking->tenant, $user, $booking, $discountAmount);
                }

                $walletAmount = BigDecimal::of((string) $transaction->wallet_amount);
                if ($walletAmount->isGreaterThan(0)) {
                    // WalletService::debit() below re-locks this row itself
                    // (SELECT ... FOR UPDATE) inside its own nested
                    // transaction, so a plain find() here is enough.
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
        } catch (InvalidCouponException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (InsufficientBalanceException $e) {
            return response()->json(['message' => $e->getMessage()], 402);
        }

        $this->statusService->transition($booking, Booking::STATUS_CONFIRMED, $user, "Payment gateway ({$transaction->gateway}) + wallet captured.");

        return $this->finalizeTicketing($booking, $user);
    }

    /**
     * Shared tail for both payment paths once the booking is CONFIRMED:
     * ticket with the supplier — once per leg (round-trip/multi-city hold
     * a separate supplier reference per leg, see hold()) — then credit any
     * cashback the rule engine awards — see CashbackService.
     */
    protected function finalizeTicketing(Booking $booking, User $user)
    {
        $legRefs = $booking->metadata['leg_supplier_refs'] ?? [
            ['supplier_code' => $booking->supplier_code, 'supplier_booking_ref' => $booking->supplier_booking_ref],
        ];

        $ticketNumbers = [];

        foreach ($legRefs as $ref) {
            if ($ref['supplier_code'] === FlightInventoryItem::SUPPLIER_CODE) {
                $confirmResult = $this->ownInventoryConfirm($ref['supplier_booking_ref']);
            } else {
                try {
                    $confirmResult = $this->orchestrator->confirm($booking->tenant, $ref['supplier_code'], $ref['supplier_booking_ref']);
                } catch (SupplierException $e) {
                    $this->statusService->transition($booking, Booking::STATUS_FAILED, $user, $e->getMessage());

                    return response()->json(['message' => $e->getMessage()], 502);
                }
            }

            array_push($ticketNumbers, ...$confirmResult->ticketNumbers);
        }

        $booking->update(['metadata' => array_merge($booking->metadata ?? [], ['ticket_numbers' => $ticketNumbers])]);
        $this->statusService->transition($booking, Booking::STATUS_TICKETED, $user, 'Supplier ticketing confirmed.');

        $this->cashbackService->applyCashback($booking, $user);
        $this->commissionService->applyCommission($booking);

        return new BookingResource($booking->fresh(['passengers', 'segments', 'ancillaries']));
    }

    /**
     * Own-inventory equivalents of SupplierOrchestrator::priceCheck()/
     * hold()/confirm() for a leg whose supplier_code is FlightInventoryItem::
     * SUPPLIER_CODE — there's no real supplier to call, so these just
     * re-check/reserve/consume the FlightInventoryItem row directly. The
     * external_id an offer was searched with ("inv-{id}") and the
     * supplier_booking_ref a hold produces ("OWNINV-{id}-{token}") both
     * carry the FlightInventoryItem id so these three steps agree on which row
     * they're operating on without any extra state.
     */
    protected function ownFlightInventoryItemFromExternalId(string $externalId): FlightInventoryItem
    {
        $id = (int) str_replace('inv-', '', $externalId);

        return FlightInventoryItem::query()->findOrFail($id);
    }

    protected function ownInventoryPriceCheck(string $externalId): FareRuleResult
    {
        $item = $this->ownFlightInventoryItemFromExternalId($externalId);

        return new FareRuleResult(
            isStillAvailable: $item->isSellable(),
            confirmedNetAmount: (string) $item->net_price,
            isRefundable: (bool) $item->is_refundable,
        );
    }

    protected function ownInventoryHold(string $externalId): BookingHoldResult
    {
        $item = $this->ownFlightInventoryItemFromExternalId($externalId);

        if (! $item->isSellable()) {
            throw new \RuntimeException('This own-inventory fare is no longer available.');
        }

        if ($item->capacity !== null) {
            $updated = FlightInventoryItem::query()
                ->where('id', $item->id)
                ->where('capacity', '>', 0)
                ->decrement('capacity');

            if (! $updated) {
                throw new \RuntimeException('This own-inventory fare just sold out.');
            }
        }

        return new BookingHoldResult(
            supplierBookingRef: FlightInventoryItem::SUPPLIER_CODE.'-'.$item->id.'-'.uniqid(),
            holdExpiresAt: new \DateTimeImmutable('+30 minutes'),
            netAmount: (string) $item->net_price,
        );
    }

    protected function ownInventoryConfirm(string $supplierBookingRef): BookingConfirmResult
    {
        // "OWN_INVENTORY-{id}-{token}" -> id is the second dash-delimited part.
        $parts = explode('-', $supplierBookingRef);
        $itemId = $parts[1] ?? null;

        return new BookingConfirmResult(
            supplierBookingRef: $supplierBookingRef,
            isTicketed: true,
            ticketNumbers: ['OWNINV-'.($itemId ?? '0').'-'.strtoupper(substr(uniqid(), -6))],
        );
    }

    public function show(Request $request, Booking $booking)
    {
        return new BookingResource($booking->load(['passengers', 'segments', 'statusHistory', 'ancillaries']));
    }

    /**
     * The e-ticket PDF — laid out against the real sample tickets the user
     * shared (their own existing FyndMyTrip-branded PNR slips): a header
     * with the operating entity's contact block, a Booking Time/ID/Status
     * strip against per-segment airline+PNR badges, a Flight Detail table
     * with layover rows, a Passenger Details table with a barcode per
     * passenger, an optional Fare Details box, and numbered Important
     * Information terms. Kept behind auth (unlike the public holiday
     * itinerary PDF) since it carries passenger names/passport numbers.
     *
     * ?fare=0 renders the passenger-facing copy with the Fare Details box
     * left out entirely — "option to choose with or without fare options
     * ticket pdf". Defaults to including it.
     */
    public function ticketPdf(Request $request, Booking $booking)
    {
        abort_if($booking->isAwaitingPayment(), 409, 'Your ticket is issued once payment is complete.');

        $booking->load([
            'passengers',
            'segments',
            'ancillaries.passenger',
            'tenant:id,name,legal_name,logo_url,support_email,support_phone,business_address',
        ]);

        $includeFare = $request->boolean('fare', true);

        // The commission this platform keeps is never itemised as
        // "markup" on a document a traveller may see — "no where markup
        // should be shows as markup, It needed to added in tax alone".
        // It's folded into the tax/fee lines instead, the same way the
        // reference ticket folds its own OTA fee into "Management Fee" +
        // "Management Fee GST" rather than calling it out separately. The
        // fold assumes the fee is itself GST-inclusive at 18% purely so
        // the printed lines read like a real invoice rather than one
        // opaque number.
        $priceRows = [];
        if ($includeFare) {
            $baseFare = (float) $booking->supplier_net_amount;
            $markup = (float) $booking->markup_amount;
            $serviceFee = $markup > 0 ? round($markup / 1.18, 2) : 0.0;
            $gst = $markup > 0 ? round($markup - $serviceFee, 2) : 0.0;
            $extras = (float) $booking->extras_amount;
            $discount = (float) $booking->discount_amount;

            $priceRows[] = ['label' => 'Base Fare', 'amount' => $baseFare];
            if ($serviceFee > 0) {
                $priceRows[] = ['label' => 'Taxes & Fees', 'amount' => $serviceFee];
            }
            if ($gst > 0) {
                $priceRows[] = ['label' => 'GST (18%)', 'amount' => $gst];
            }
            if ($extras > 0) {
                $priceRows[] = ['label' => 'Add-on Services (meals / seats / baggage)', 'amount' => $extras];
            }
            if ($discount > 0) {
                $priceRows[] = ['label' => 'Discount', 'amount' => -$discount];
            }
        }

        $tenant = $booking->tenant;

        $pdf = Pdf::loadView('pdf.flight-eticket', [
            'booking' => $booking,
            'brandName' => $tenant?->legal_name ?? $tenant?->name ?? 'FyndMyTrip',
            'logo' => \App\Support\PdfBranding::resolveLogo($tenant),
            'tenant' => $tenant,
            'socialLinks' => \App\Support\PdfBranding::socialLinks($tenant),
            'qrDataUri' => \App\Support\PdfBranding::qrDataUri($tenant),
            'playStoreUrl' => \App\Support\PdfBranding::appLinks($tenant)['play_store_url'],
            'appStoreUrl' => \App\Support\PdfBranding::appLinks($tenant)['app_store_url'],
            'generatedAt' => now()->format('d M Y, h:i A'),
            'includeFare' => $includeFare,
            'priceRows' => $priceRows,
            'total' => (float) $booking->total_amount,
        ])->setPaper('a4');

        $suffix = $includeFare ? '-eticket' : '-eticket-no-fare';
        $filename = ($booking->booking_code ?? $booking->booking_reference).$suffix.'.pdf';

        return $request->boolean('download') ? $pdf->download($filename) : $pdf->stream($filename);
    }

    public function index(Request $request)
    {
        $bookings = Booking::query()
            ->where('module', Booking::MODULE_FLIGHT)
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 25));

        return BookingResource::collection($bookings);
    }

    protected function markupRoleFor(?User $user): string
    {
        // A guest browsing without an account (Platform Roadmap's public
        // storefront) prices exactly like a signed-in B2C customer would —
        // 'b2c' is already the default for every role that isn't an
        // agent/sub-agent/partner_admin, so null just falls through to it.
        return match ($user?->role) {
            User::ROLE_AGENT, User::ROLE_PARTNER_ADMIN => 'agent',
            User::ROLE_SUB_AGENT => 'sub_agent',
            default => 'b2c',
        };
    }
}
