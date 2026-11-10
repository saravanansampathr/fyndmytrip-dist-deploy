<?php

namespace App\Http\Controllers\Api;

use App\Domains\Booking\Models\Booking;
use App\Domains\Holiday\Models\HolidayPackage;
use App\Domains\Suppliers\Services\MarkupService;
use App\Domains\Tenancy\Services\TenantContext;
use App\Domains\Wallet\Models\Wallet;
use App\Http\Controllers\Controller;
use App\Http\Resources\BookingResource;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Turns a browsed HolidayPackage into a real Booking (module = 'holiday'),
 * reusing the single generic booking schema per Booking's own docblock —
 * no separate holiday_bookings table. Unlike FlightBookingController::
 * hold(), there is no live supplier price-check step here (the package's
 * catalog sell_price already IS the confirmed retail price, computed at
 * +10% over the supplier net price when the package/tier was saved — see
 * HolidayPackage::priceWithMarkup()), so this goes straight from "picked a
 * package + traveler mix" to a held booking. Traveler occupancy_type is
 * holiday-specific detail that doesn't belong on the generic
 * BookingPassenger row, so it lives in metadata.travelers alongside a
 * snapshot of the itinerary at booking time (so a later catalog edit never
 * changes what a customer already booked).
 *
 * Two markup layers stack here — the backlog gap flagged as "Partners and
 * agents or Whitelabel users need option to keep markup on holidays what
 * they're getting from admin": HolidayPackage::priceWithMarkup() already
 * bakes in the DMC/admin's own catalog markup (package sell_price vs
 * net_price) — that part was already live. What was missing is a SECOND,
 * per-tenant markup on top of that catalog price, via the very same
 * MarkupRuleController/MarkupService every other module already uses
 * (module=holiday was already a selectable option there, it just had no
 * effect until now) — so an Agent or White-Label reselling this package
 * can mark it up again for their own customers, capped the same
 * ancestor-ceiling way flight markup is.
 */
class HolidayBookingController extends Controller
{
    public function __construct(protected MarkupService $markupService) {}

    public function store(Request $request, string $slug)
    {
        $booking = $this->createBookingForUser($request, $slug, $request->user());

        return response()->json(['data' => new BookingResource($booking)], 201);
    }

    /**
     * Guest checkout — "direct customers can book by login/signup or as a
     * guest" — no auth:sanctum on this route (see routes/api.php). Rather
     * than inventing a separate "guest" concept in the schema, this
     * transparently creates a real customer account from the contact
     * details given here (same shape as AuthController::register()) and
     * logs straight into it, so payment, wallet, cashback and viewing this
     * booking again later all work exactly like any other customer from
     * this point on — the person just never had to fill in a password
     * first. They can set one anytime via "Forgot password".
     *
     * If the email already belongs to an existing account this refuses to
     * silently issue a token for it — anyone could otherwise "guest
     * checkout" with someone else's known email and take over their
     * account — and asks them to sign in normally instead.
     */
    public function guestStore(Request $request, string $slug)
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
                // account, not a passwordless one, and Hash::check() would
                // otherwise fail confusingly on a null/empty hash if they
                // ever did try "forgot password" straight away.
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

        $booking = $this->createBookingForUser($request, $slug, $user);
        $token = $user->createToken('api')->plainTextToken;

        return response()->json([
            'data' => new BookingResource($booking),
            'user' => new UserResource($user),
            'token' => $token,
            'guest_checkout' => true,
        ], 201);
    }

    /**
     * Shared by store() (logged-in customer/agent) and guestStore() (fresh
     * account just created for a guest) — everything past "who is booking
     * this" is identical either way.
     */
    protected function createBookingForUser(Request $request, string $slug, User $user): Booking
    {
        $package = HolidayPackage::query()
            ->where('slug', $slug)
            ->where('status', HolidayPackage::STATUS_ACTIVE)
            ->where(fn ($q) => $q->whereNull('tenant_id')->orWhere('tenant_id', $user->tenant_id))
            ->with(['itineraryDays', 'priceTiers'])
            ->firstOrFail();

        $data = $request->validate([
            'travel_date' => ['required', 'date_format:Y-m-d'],
            'contact_email' => ['nullable', 'email', 'max:191'],
            'contact_phone' => ['nullable', 'string', 'max:30'],
            'travelers' => ['required', 'array', 'min:1', 'max:20'],
            'travelers.*.name' => ['required', 'string', 'max:191'],
            'travelers.*.occupancy_type' => ['required', 'in:adult_sharing,adult_single,child_with_bed,child_without_bed,infant'],
        ]);

        $tierByOccupancy = $package->priceTiers->keyBy('occupancy_type');

        // "Gross" here is the tenant's own COST — the admin/DMC's catalog
        // price, already inclusive of the platform's own markup — not yet
        // what the tenant charges its customer. That comes from applying
        // this tenant's own MarkupRule (if any) on top, below.
        $catalogAmount = \Brick\Math\BigDecimal::zero();
        $netAmount = \Brick\Math\BigDecimal::zero();
        $travelers = [];

        foreach ($data['travelers'] as $traveler) {
            $tier = $tierByOccupancy->get($traveler['occupancy_type']);
            $sellPrice = $tier->sell_price ?? $package->sell_price;
            $netPrice = $tier->net_price ?? $package->net_price;

            $catalogAmount = $catalogAmount->plus((string) $sellPrice);
            $netAmount = $netAmount->plus((string) $netPrice);

            $travelers[] = [
                'name' => $traveler['name'],
                'occupancy_type' => $traveler['occupancy_type'],
                'price' => (string) $sellPrice,
            ];
        }

        $role = $this->markupRoleFor($user);

        $resellerPricing = $this->markupService->applyMarkup(
            $user->tenant,
            Booking::MODULE_HOLIDAY,
            $role,
            (string) $catalogAmount->toScale(2, \Brick\Math\RoundingMode::HALF_UP),
            [
                'passenger_count' => count($travelers),
                'destination' => $package->destination_city ?? $package->route_summary,
                'is_international' => $package->booking_scope === HolidayPackage::SCOPE_INTERNATIONAL,
                'supplier_code' => $package->supplier_code,
            ],
        );

        $grossAmount = \Brick\Math\BigDecimal::of($resellerPricing['gross_amount']);

        $booking = DB::transaction(function () use ($package, $data, $user, $grossAmount, $netAmount, $catalogAmount, $resellerPricing, $travelers) {
            return Booking::create([
                'tenant_id' => $user->tenant_id,
                'created_by_user_id' => $user->id,
                'channel' => Booking::channelForTenant($user->tenant),
                'module' => Booking::MODULE_HOLIDAY,
                'supplier_code' => $package->supplier_code,
                'gross_amount' => (string) $grossAmount->toScale(2, \Brick\Math\RoundingMode::HALF_UP),
                'supplier_net_amount' => (string) $netAmount->toScale(2, \Brick\Math\RoundingMode::HALF_UP),
                // Total markup over true supplier cost = the DMC/admin's
                // own catalog markup PLUS this tenant's reseller markup,
                // so it still reads as "full markup vs net" like every
                // other module's Booking row.
                'markup_amount' => (string) $grossAmount->minus($netAmount)->toScale(2, \Brick\Math\RoundingMode::HALF_UP),
                'travel_date_start' => $data['travel_date'],
                'travel_date_end' => \Carbon\Carbon::createFromFormat('Y-m-d', $data['travel_date'])
                    ->addDays($package->duration_nights)->toDateString(),
                'passenger_count' => count($travelers),
                'contact_email' => $data['contact_email'] ?? $user->email,
                'contact_phone' => $data['contact_phone'] ?? $user->phone,
                'currency' => $package->currency,
                'status' => Booking::STATUS_HELD,
                'metadata' => [
                    'package_id' => $package->id,
                    'package_slug' => $package->slug,
                    'package_name' => $package->name,
                    'route_summary' => $package->route_summary,
                    'hotel_category' => $package->hotel_category,
                    'itinerary_snapshot' => $package->itineraryDays->map(fn ($day) => [
                        'day_number' => $day->day_number,
                        'title' => $day->title,
                    ])->all(),
                    'travelers' => $travelers,
                    // Audit trail for the two stacked markup layers — what
                    // the tenant was charged by the platform/DMC (already
                    // inclusive of the catalog markup) vs what it then
                    // added on top for its own customer.
                    'catalog_amount' => (string) $catalogAmount->toScale(2, \Brick\Math\RoundingMode::HALF_UP),
                    'reseller_markup_amount' => $resellerPricing['markup_amount'],
                ],
            ]);
        });

        return $booking;
    }

    /**
     * Mirrors FlightBookingController::markupRoleFor() — kept as a small
     * per-controller duplicate rather than a shared trait, matching this
     * codebase's existing tolerance for the same 4-line role mapping
     * appearing per booking controller.
     */
    protected function markupRoleFor(User $user): string
    {
        return match ($user->role) {
            User::ROLE_AGENT, User::ROLE_PARTNER_ADMIN => 'agent',
            User::ROLE_SUB_AGENT => 'sub_agent',
            default => 'b2c',
        };
    }
}
