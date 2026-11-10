<?php

namespace App\Http\Controllers\Api;

use App\Domains\Booking\Models\Booking;
use App\Domains\Booking\Models\BookingPassenger;
use App\Domains\Booking\Services\BookingStatusService;
use App\Domains\Cashback\Services\CashbackService;
use App\Domains\Commission\Services\CommissionService;
use App\Domains\Inventory\Models\HotelInventoryItem;
use App\Domains\Payments\Models\PaymentMethodRoute;
use App\Domains\Payments\Models\PaymentTransaction;
use App\Domains\Payments\Services\PaymentGatewayService;
use App\Domains\Suppliers\DTOs\BookingConfirmResult;
use App\Domains\Suppliers\DTOs\BookingHoldResult;
use App\Domains\Suppliers\DTOs\FareRuleResult;
use App\Domains\Suppliers\DTOs\SearchRequest;
use App\Domains\Suppliers\Exceptions\SupplierException;
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
use Barryvdh\DomPDF\Facade\Pdf;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Phase 3's second "New Verticals & Real Booking Flows" vertical, after
 * Bus: search -> hold -> confirm (wallet/gateway) -> voucher, against
 * MockHotelSupplierAdapter until a real hotel-supplier API is wired in —
 * the exact same pipeline shape as BusBookingController/
 * FlightBookingController.
 *
 * Deliberately scoped down for this first cut, the same way Bus was:
 * one room type per booking (no mixing room types/rate plans in a single
 * stay), no availability calendar (an own-inventory row is a fixed
 * check-in/check-out window, not a per-night rate the customer can shift),
 * no international classification (manually-entered hotel inventory
 * carries no country field yet — same simplification store() below already
 * made), and the price quoted for an offer is per booking (one room's
 * stay), not multiplied by a "rooms requested" count — same convention
 * BusBookingController's real flow already established (a hold consumes
 * exactly one inventory unit regardless of how many people are named on
 * it). These can layer on later without changing this shape.
 *
 * store()/voucherPdf() below (the original manual-entry "book this
 * inventory item right now" test tool used from InventoryAdminPage's Hotels
 * tab) are unchanged and still work exactly as before — voucherPdf() in
 * particular is reused as-is by the new flow too, since it already works
 * off any Booking with module=hotel regardless of which flow created it.
 * The methods further down are the new customer-facing flow, additive to
 * store().
 */
class HotelBookingController extends Controller
{
    /**
     * Real-world OTA/hotel-website convention: a self-service booking engine
     * caps a single online transaction at roughly 5–9 rooms or 20–30 guests
     * before routing the customer to a group desk — this app's existing
     * rooms:10/guests:20 caps already sit at (or just past) that range, so
     * they stay the customer-facing ceiling unchanged. B2B roles (agents,
     * partner admins, etc.) get a raised "instant bulk" ceiling instead,
     * mirroring FlightBookingController::MAX_PASSENGERS_B2B_BULK — beyond
     * that, HotelGroupBookingController's staff-quoted flow takes over
     * (the industry's own "10+ rooms is a group booking" convention drives
     * HotelGroupBookingRequest::MINIMUM_ROOMS_COUNT).
     */
    public const MAX_ROOMS_STANDARD = 10;

    public const MAX_GUESTS_STANDARD = 20;

    public const MAX_ROOMS_B2B_BULK = 20;

    public const MAX_GUESTS_B2B_BULK = 50;

    public function __construct(
        protected MarkupService $markupService,
        protected SupplierOrchestrator $orchestrator,
        protected WalletService $walletService,
        protected BookingStatusService $statusService,
        protected PaymentGatewayService $paymentGatewayService,
        protected CashbackService $cashbackService,
        protected CommissionService $commissionService,
    ) {}

    public function store(Request $request, HotelInventoryItem $hotelInventoryItem)
    {
        $user = $request->user();
        $this->authorizeInventory($user, $hotelInventoryItem);

        $maxRooms = $this->maxRoomsFor($user);
        $maxGuests = $this->maxGuestsFor($user);

        $data = $request->validate([
            'rooms' => ['nullable', 'integer', 'min:1', "max:{$maxRooms}"],
            'guests' => ['required', 'array', 'min:1', "max:{$maxGuests}"],
            'guests.*.name' => ['required', 'string', 'max:191'],
            'contact_email' => ['nullable', 'email', 'max:191'],
            'contact_phone' => ['nullable', 'string', 'max:30'],
            'special_requests' => ['nullable', 'string', 'max:1000'],
        ]);

        $item = $hotelInventoryItem;
        $rooms = $data['rooms'] ?? 1;

        $netAmount = BigDecimal::of((string) $item->net_price)->multipliedBy($rooms);

        $role = $this->markupRoleFor($user);
        $pricing = $this->markupService->applyMarkup(
            $user->tenant,
            Booking::MODULE_HOTEL,
            $role,
            (string) $netAmount->toScale(2, RoundingMode::HALF_UP),
            [
                'passenger_count' => count($data['guests']),
                'destination' => $item->city,
                // Manually-entered hotel inventory carries no country field
                // yet, so there's no real data to classify domestic vs.
                // international from — defaults to domestic (a
                // booking_scope=international markup rule simply never
                // matches a hotel booking today) until inventory tracks that.
                'is_international' => false,
            ],
        );

        $grossAmount = BigDecimal::of($pricing['gross_amount']);

        $nights = null;
        if ($item->check_in_date && $item->check_out_date) {
            $nights = (int) $item->check_in_date->diffInDays($item->check_out_date);
        }

        $booking = DB::transaction(function () use ($item, $data, $user, $grossAmount, $netAmount, $pricing, $rooms, $nights) {
            $booking = Booking::create([
                'tenant_id' => $item->tenant_id ?? $user->tenant_id,
                'created_by_user_id' => $user->id,
                'channel' => Booking::channelForTenant($user->tenant),
                'module' => Booking::MODULE_HOTEL,
                'supplier_code' => 'OWN_INVENTORY',
                'supplier_booking_ref' => 'HTL'.now()->format('ymd').random_int(1000, 9999),
                'gross_amount' => (string) $grossAmount->toScale(2, RoundingMode::HALF_UP),
                'supplier_net_amount' => (string) $netAmount->toScale(2, RoundingMode::HALF_UP),
                'markup_amount' => $pricing['markup_amount'],
                'travel_date_start' => $item->check_in_date,
                'travel_date_end' => $item->check_out_date,
                'passenger_count' => count($data['guests']),
                'contact_email' => $data['contact_email'] ?? $user->email,
                'contact_phone' => $data['contact_phone'] ?? $user->phone,
                'currency' => $item->currency,
                'status' => Booking::STATUS_CONFIRMED,
                'metadata' => [
                    'inventory_item_id' => $item->id,
                    'hotel_name' => $item->hotel_name ?: $item->title,
                    'city' => $item->city,
                    'star_rating' => $item->star_rating,
                    'room_type' => $item->room_type,
                    'meal_plan' => $item->meal_plan,
                    'rooms' => $rooms,
                    'nights' => $nights,
                    'special_requests' => $data['special_requests'] ?? null,
                ],
            ]);

            foreach ($data['guests'] as $guest) {
                $booking->passengers()->create([
                    'name' => $guest['name'],
                    'passenger_type' => BookingPassenger::TYPE_ADULT,
                ]);
            }

            return $booking;
        });

        return response()->json(['data' => new BookingResource($booking->load(['passengers', 'tenant']))], 201);
    }

    public function voucherPdf(Request $request, Booking $booking)
    {
        abort_unless($booking->module === Booking::MODULE_HOTEL, 404);

        $booking->load([
            'passengers',
            'tenant:id,name,legal_name,logo_url,support_email,support_phone,business_address',
        ]);

        $tenant = $booking->tenant;

        // Same "never show markup as markup" convention as every other
        // module's customer-facing PDF (see FlightBookingController::
        // ticketPdf()'s docblock) — the platform's margin is folded into
        // "Taxes and fees", never itemised as markup.
        $baseFare = (float) $booking->supplier_net_amount;
        $taxesAndFees = round((float) $booking->markup_amount, 2);

        $pdf = Pdf::loadView('pdf.hotel-voucher', [
            'booking' => $booking,
            'brandName' => $tenant?->legal_name ?? $tenant?->name ?? 'FyndMyTrip',
            'logo' => \App\Support\PdfBranding::resolveLogo($tenant),
            'tenant' => $tenant,
            'socialLinks' => \App\Support\PdfBranding::socialLinks($tenant),
            'qrDataUri' => \App\Support\PdfBranding::qrDataUri($tenant),
            'playStoreUrl' => \App\Support\PdfBranding::appLinks($tenant)['play_store_url'],
            'appStoreUrl' => \App\Support\PdfBranding::appLinks($tenant)['app_store_url'],
            'generatedAt' => now()->format('d M Y, h:i A'),
            'baseFare' => $baseFare,
            'taxesAndFees' => $taxesAndFees,
            'total' => (float) $booking->total_amount,
        ])->setPaper('a4');

        $filename = ($booking->booking_code ?? $booking->booking_reference).'-hotel-voucher.pdf';

        return $request->boolean('download') ? $pdf->download($filename) : $pdf->stream($filename);
    }

    /**
     * A hotel stay is always one room type, one property, one booking —
     * no multi-room-type cart the way multi-city flights chain several
     * legs. Merges MOCKHOTEL-adapter offers with the tenant's own
     * manually-entered HotelInventoryItem rows (ownInventoryOffers()),
     * shaped identically so the frontend can't tell the difference.
     */
    public function search(Request $request)
    {
        // Guest-accessible — "browse without login, login required only to
        // book" (Platform Roadmap's B2C public storefront), same convention
        // FlightBookingController::search() already established. $user is
        // null for an anonymous visitor; the acting tenant then falls back
        // to whichever storefront ResolveTenant matched from the request
        // Host header rather than a logged-in user's own tenant_id.
        $user = $request->user();
        $maxRooms = $this->maxRoomsFor($user);
        $maxGuests = $this->maxGuestsFor($user);

        $data = $request->validate([
            'city' => ['required', 'string', 'max:100'],
            'check_in_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'check_out_date' => ['required', 'date_format:Y-m-d', 'after:check_in_date'],
            'rooms' => ['nullable', 'integer', 'min:1', "max:{$maxRooms}"],
            'guests' => ['nullable', 'integer', 'min:1', "max:{$maxGuests}"],
        ], [
            'rooms.max' => $maxRooms === self::MAX_ROOMS_STANDARD
                ? "You can search for up to {$maxRooms} rooms at once. For more, request a Group Booking quote instead."
                : "You can book up to {$maxRooms} rooms at once here. For a larger group, submit a Group Booking request instead.",
            'guests.max' => $maxGuests === self::MAX_GUESTS_STANDARD
                ? "You can search for up to {$maxGuests} guests at once. For more, request a Group Booking quote instead."
                : "You can book up to {$maxGuests} guests at once here. For a larger group, submit a Group Booking request instead.",
        ]);

        $rooms = $data['rooms'] ?? 1;
        $guestCount = $data['guests'] ?? 1;
        $nights = (int) ((strtotime($data['check_out_date']) - strtotime($data['check_in_date'])) / 86400);
        $tenant = $user?->tenant ?? app(TenantContext::class)->get();
        $role = $this->markupRoleFor($user);
        $canViewMargin = $user?->hasPermission('pricing.view_margin') ?? false;

        abort_if(! $tenant, 422, 'Unable to resolve which storefront to search against.');

        $searchRequest = new SearchRequest(
            module: Booking::MODULE_HOTEL,
            origin: $data['city'],
            destination: null,
            departureDate: $data['check_in_date'],
            returnDate: $data['check_out_date'],
            passengerCount: $guestCount,
        );

        $offers = $this->orchestrator->search($tenant, $searchRequest);
        array_push($offers, ...$this->ownInventoryOffers($tenant, $data['city'], $data['check_in_date'], $data['check_out_date']));

        $offers = array_map(function (array $item) use ($tenant, $role, $data, $guestCount, $rooms) {
            // A supplier quotes one room's stay; the guest is buying $rooms
            // of them, so results show (and checkout charges) the full stay.
            $item['net_amount_per_room'] = $item['net_amount'];
            $item['net_amount'] = (string) BigDecimal::of((string) $item['net_amount'])->multipliedBy($rooms)->toScale(2, RoundingMode::HALF_UP);
            $item['rooms'] = $rooms;
            $pricing = $this->markupService->applyMarkup($tenant, Booking::MODULE_HOTEL, $role, $item['net_amount'], [
                'passenger_count' => $guestCount,
                'destination' => $data['city'],
                // Same domestic-only convention store() above already uses.
                'is_international' => false,
                'supplier_code' => $item['supplier_code'] ?? null,
            ]);

            return array_merge($item, $pricing);
        }, $offers);

        usort($offers, fn ($a, $b) => $a['gross_amount'] <=> $b['gross_amount']);

        // Same margin-withholding rule as FlightBookingController::search().
        if (! $canViewMargin) {
            $offers = array_map(function (array $offer) {
                unset($offer['net_amount'], $offer['net_amount_per_room'], $offer['markup_amount']);

                return $offer;
            }, $offers);
        }

        return response()->json([
            'data' => [
                'city' => $data['city'],
                'check_in_date' => $data['check_in_date'],
                'check_out_date' => $data['check_out_date'],
                'nights' => $nights,
                'rooms' => $rooms,
                'guests' => $guestCount,
                'can_view_margin' => $canViewMargin,
                'offers' => $offers,
            ],
        ]);
    }

    /**
     * A tenant's own manually-entered hotel inventory for this exact city/
     * check-in/check-out window, shaped and merged into the offer list
     * exactly like a real supplier's results — mirrors
     * FlightBookingController::ownInventoryOffers() /
     * BusBookingController::ownInventoryOffers(). No availability calendar
     * in this first cut: an own-inventory row's check_in_date/
     * check_out_date must match the search exactly, the same convention
     * Bus already uses for its travel_date.
     */
    protected function ownInventoryOffers($tenant, string $city, string $checkInDate, string $checkOutDate): array
    {
        $tenantId = $tenant?->id;

        $items = HotelInventoryItem::query()
            ->where('status', HotelInventoryItem::STATUS_ACTIVE)
            ->where('city', $city)
            ->whereDate('check_in_date', $checkInDate)
            ->whereDate('check_out_date', $checkOutDate)
            ->where(function ($q) use ($tenantId) {
                $q->whereNull('tenant_id')
                    ->orWhere('tenant_id', $tenantId)
                    ->orWhere('share_with_network', true)
                    ->when(is_null($tenantId), fn ($q2) => $q2->orWhere('share_on_platform_site', true));
            })
            ->get();

        return $items->filter->isSellable()->map(fn (HotelInventoryItem $item) => $item->toHotelOfferArray())->values()->all();
    }

    /**
     * Holds a Booking for the one hotel room the customer picked — one
     * priceCheck()/holdBooking() supplier round-trip (or the own-inventory
     * equivalent), then persists the Booking + its single BookingSegment
     * (the stay itself) + guests, exactly like BusBookingController::hold()
     * but for a stay instead of a transit leg.
     */
    public function hold(Request $request)
    {
        return $this->performHold($request, $request->user());
    }

    /**
     * Guest checkout — "from home page to till booking, only during the
     * payment page you may ask book as guest or login and book", same
     * pattern already established for Holiday Packages and Flights (see
     * FlightBookingController::guestHold()'s docblock): no auth:sanctum on
     * this route (see routes/api.php), so a browsing visitor reaches this
     * only once they've searched, picked a room, and filled in guest
     * details — right where hold() would otherwise require an account. A
     * real customer account is transparently created from the guest
     * contact details given here and logged straight into (the frontend
     * dispatches the returned token the same way a normal login does), so
     * confirm/pay and viewing this booking again later all work exactly
     * like any other customer from this point on.
     *
     * If the email already belongs to an existing account this refuses to
     * silently issue a token for it — same anti-account-takeover guard as
     * the Holiday Packages/Flights guest flows.
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
        // unique-constraint-violation 500 the first time it happened,
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
        $maxRooms = $this->maxRoomsFor($user);
        $maxGuests = $this->maxGuestsFor($user);

        $data = $request->validate([
            'supplier_code' => ['required', 'string'],
            'external_id' => ['required', 'string'],
            'segment' => ['nullable', 'array'],
            'check_in_date' => ['required', 'date_format:Y-m-d'],
            'check_out_date' => ['required', 'date_format:Y-m-d', 'after:check_in_date'],
            'rooms' => ['nullable', 'integer', 'min:1', "max:{$maxRooms}"],
            'contact_email' => ['nullable', 'email', 'max:191'],
            'contact_phone' => ['nullable', 'string', 'max:30'],
            'special_requests' => ['nullable', 'string', 'max:1000'],
            'guests' => ['required', 'array', 'min:1', "max:{$maxGuests}"],
            'guests.*.name' => ['required', 'string', 'max:191'],
            'guests.*.age' => ['required', 'integer', 'min:1', 'max:120'],
        ]);

        $tenant = $user->tenant;
        $role = $this->markupRoleFor($user);
        $guestCount = count($data['guests']);
        $segment = $data['segment'] ?? [];

        if ($data['supplier_code'] === HotelInventoryItem::SUPPLIER_CODE) {
            $fareRule = $this->ownInventoryPriceCheck($data['external_id']);
        } else {
            try {
                $fareRule = $this->orchestrator->priceCheck($tenant, $data['supplier_code'], $data['external_id']);
            } catch (SupplierException $e) {
                return response()->json(['message' => $e->getMessage()], 502);
            }
        }

        if (! $fareRule->isStillAvailable) {
            return response()->json(['message' => 'This room is no longer available. Please search again.'], 409);
        }

        $rooms = (int) ($data['rooms'] ?? 1);
        $stayNet = (string) BigDecimal::of($fareRule->confirmedNetAmount)->multipliedBy($rooms)->toScale(2, RoundingMode::HALF_UP);

        $pricing = $this->markupService->applyMarkup($tenant, Booking::MODULE_HOTEL, $role, $stayNet, [
            'passenger_count' => $guestCount,
            'destination' => $segment['origin'] ?? null,
            'is_international' => false,
            'supplier_code' => $data['supplier_code'],
        ]);

        $booking = Booking::create([
            'tenant_id' => $tenant->id,
            'created_by_user_id' => $user->id,
            'channel' => Booking::channelForTenant($tenant),
            'module' => Booking::MODULE_HOTEL,
            'supplier_code' => $data['supplier_code'],
            'is_international' => false,
            'gross_amount' => $pricing['gross_amount'],
            'supplier_net_amount' => (string) $stayNet,
            'markup_amount' => $pricing['markup_amount'],
            'travel_date_start' => $data['check_in_date'],
            'travel_date_end' => $data['check_out_date'],
            'passenger_count' => $guestCount,
            'contact_email' => $data['contact_email'] ?? $user->email,
            'contact_phone' => $data['contact_phone'] ?? $user->phone,
            // Populates the same metadata keys hotel-voucher.blade.php reads
            // (hotel_name/city/room_type/meal_plan/star_rating/nights) from
            // the $segment array already carried by the search->hold
            // hand-off — same fix as BusBookingController::hold(), so a
            // voucher PDF for a real-flow booking doesn't show blanks.
            'metadata' => [
                'rooms' => $data['rooms'] ?? 1,
                'special_requests' => $data['special_requests'] ?? null,
                'hotel_name' => $segment['carrier_or_provider'] ?? $segment['destination'] ?? null,
                'city' => $segment['origin'] ?? null,
                'room_type' => $segment['fare_basis'] ?? null,
                'meal_plan' => $segment['meal_plan'] ?? null,
                'star_rating' => $segment['star_rating'] ?? null,
                'nights' => $segment['nights'] ?? null,
            ],
        ]);

        foreach ($data['guests'] as $guest) {
            $booking->passengers()->create([
                'name' => $guest['name'],
                'age' => $guest['age'],
                'passenger_type' => BookingPassenger::TYPE_ADULT,
            ]);
        }

        if ($segment !== []) {
            $rawPayload = $segment;
            $rawPayload['is_refundable'] = $fareRule->isRefundable;

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

        if ($data['supplier_code'] === HotelInventoryItem::SUPPLIER_CODE) {
            try {
                $hold = $this->ownInventoryHold($data['external_id']);
            } catch (\RuntimeException $e) {
                $this->statusService->transition($booking, Booking::STATUS_FAILED, $user, $e->getMessage());

                return response()->json(['message' => $e->getMessage()], 409);
            }
        } else {
            try {
                $hold = $this->orchestrator->hold($tenant, $data['supplier_code'], $data['external_id'], $data['guests']);
            } catch (SupplierException $e) {
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
     * Confirms payment for a HELD hotel booking — wallet, gateway, or a
     * wallet+gateway split — identical logic to
     * BusBookingController::confirm() (no international restriction: a
     * hotel booking is never international in this first cut), minus
     * coupon/ancillary handling this first cut doesn't have.
     */
    public function confirm(Request $request, Booking $booking)
    {
        $user = $request->user();
        abort_unless($booking->module === Booking::MODULE_HOTEL, 404);

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
     * BusBookingController::payGateway() exactly, minus coupons.
     */
    public function payGateway(Request $request, Booking $booking)
    {
        $user = $request->user();
        abort_unless($booking->module === Booking::MODULE_HOTEL, 404);

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
     * confirms with the supplier, then credits any cashback the rule
     * engine awards. Mirrors BusBookingController::finalizeTicketing().
     * A hotel booking has no "ticket_numbers" of its own the way flight/bus
     * do — the supplier confirmation number IS the booking's own
     * supplier_booking_ref, so ticket_numbers is left empty and the voucher
     * PDF shows the confirmation number instead (see the hotel-voucher
     * template, unchanged).
     */
    protected function finalizeTicketing(Booking $booking, User $user)
    {
        if ($booking->supplier_code === HotelInventoryItem::SUPPLIER_CODE) {
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
        $this->statusService->transition($booking, Booking::STATUS_TICKETED, $user, 'Supplier confirmation received.');

        $this->cashbackService->applyCashback($booking, $user);
        $this->commissionService->applyCommission($booking);

        return new BookingResource($booking->fresh(['passengers', 'segments']));
    }

    protected function ownHotelInventoryItemFromExternalId(string $externalId): HotelInventoryItem
    {
        $id = (int) str_replace('inv-', '', $externalId);

        return HotelInventoryItem::query()->findOrFail($id);
    }

    protected function ownInventoryPriceCheck(string $externalId): FareRuleResult
    {
        $item = $this->ownHotelInventoryItemFromExternalId($externalId);

        return new FareRuleResult(
            isStillAvailable: $item->isSellable(),
            confirmedNetAmount: (string) $item->net_price,
            isRefundable: (bool) $item->is_refundable,
        );
    }

    protected function ownInventoryHold(string $externalId): BookingHoldResult
    {
        $item = $this->ownHotelInventoryItemFromExternalId($externalId);

        if (! $item->isSellable()) {
            throw new \RuntimeException('This own-inventory room is no longer available.');
        }

        if ($item->capacity !== null) {
            $updated = HotelInventoryItem::query()
                ->where('id', $item->id)
                ->where('capacity', '>', 0)
                ->decrement('capacity');

            if (! $updated) {
                throw new \RuntimeException('This own-inventory room just sold out.');
            }
        }

        return new BookingHoldResult(
            supplierBookingRef: HotelInventoryItem::SUPPLIER_CODE.'-'.$item->id.'-'.uniqid(),
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
     * BusBookingController::show().
     */
    public function show(Request $request, Booking $booking)
    {
        abort_unless($booking->module === Booking::MODULE_HOTEL, 404);

        return new BookingResource($booking->load(['passengers', 'segments', 'statusHistory']));
    }

    protected function authorizeInventory(User $user, HotelInventoryItem $item): void
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
     * search()'s guest (anonymous) path can call this too; a guest prices
     * exactly like a signed-in B2C customer, which 'b2c' already is the
     * default for.
     */
    protected function markupRoleFor(?User $user): string
    {
        return match ($user?->role) {
            User::ROLE_AGENT, User::ROLE_PARTNER_ADMIN => 'agent',
            User::ROLE_SUB_AGENT => 'sub_agent',
            default => 'b2c',
        };
    }

    /** Mirrors FlightBookingController::maxPassengersFor() — see this controller's class docblock. */
    protected function maxRoomsFor(?User $user): int
    {
        if (! $user || $user->role === User::ROLE_CUSTOMER) {
            return self::MAX_ROOMS_STANDARD;
        }

        return self::MAX_ROOMS_B2B_BULK;
    }

    protected function maxGuestsFor(?User $user): int
    {
        if (! $user || $user->role === User::ROLE_CUSTOMER) {
            return self::MAX_GUESTS_STANDARD;
        }

        return self::MAX_GUESTS_B2B_BULK;
    }
}
