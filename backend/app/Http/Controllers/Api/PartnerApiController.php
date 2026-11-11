<?php

namespace App\Http\Controllers\Api;

use App\Domains\Auth\Models\ApiPartnerCredential;
use App\Domains\Booking\Models\Booking;
use App\Domains\Wallet\Models\Wallet;
use App\Http\Controllers\Controller;
use App\Http\Resources\BookingResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The FyndMyTrip partner (B2B / Connect) API — /api/partner/v1.
 *
 * A partner swaps its client_id + client_secret for a one-hour bearer token
 * (POST /token), then searches, holds, pays from its prepaid wallet,
 * reads and cancels bookings with the same engines the portal uses. Calls
 * act as the API partner account's API user, so that account's margin
 * rules, wallet and reports apply unchanged.
 */
class PartnerApiController extends Controller
{
    /** URL segment => [booking module, controller]. */
    public const MODULES = [
        'flights' => [Booking::MODULE_FLIGHT, FlightBookingController::class],
        'hotels' => [Booking::MODULE_HOTEL, HotelBookingController::class],
        'bus' => [Booking::MODULE_BUS, BusBookingController::class],
        'cabs' => [Booking::MODULE_CAB, CabBookingController::class],
        'insurance' => [Booking::MODULE_INSURANCE, InsuranceBookingController::class],
        'cruises' => [Booking::MODULE_CRUISE, CruiseBookingController::class],
        'activities' => [Booking::MODULE_ACTIVITY, ActivityBookingController::class],
    ];

    public const TOKEN_MINUTES = 60;

    public function token(Request $request)
    {
        $data = $request->validate([
            'client_id' => ['required', 'string', 'max:64'],
            'client_secret' => ['required', 'string', 'max:255'],
        ]);

        $throttle = 'partner-token:'.$request->ip();
        if (RateLimiter::tooManyAttempts($throttle, 20)) {
            return response()->json(['message' => 'Too many attempts. Try again in a minute.'], 429);
        }
        RateLimiter::hit($throttle, 60);

        $credential = ApiPartnerCredential::withoutTenantScope()->where('client_id', $data['client_id'])->first();
        if (! $credential || ! $credential->is_active || ! Hash::check($data['client_secret'], $credential->client_secret_hash)) {
            return response()->json(['message' => 'Invalid client_id or client_secret.'], 401);
        }

        $user = User::query()
            ->where('tenant_id', $credential->tenant_id)
            ->where('role', User::ROLE_API_USER)
            ->where('status', User::STATUS_ACTIVE)
            ->orderBy('id')
            ->first();
        if (! $user) {
            return response()->json(['message' => 'This API account has no active API user. Ask your account manager to add one.'], 403);
        }

        $expires = now()->addMinutes(self::TOKEN_MINUTES);
        $token = $user->createToken('partner-api:'.$credential->client_id, ['partner', 'partner-credential:'.$credential->id], $expires);

        return response()->json([
            'access_token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_in' => self::TOKEN_MINUTES * 60,
            'expires_at' => $expires->toIso8601String(),
        ]);
    }

    public function me(Request $request)
    {
        $credential = $request->attributes->get('partner_credential');
        $user = $request->user();
        $wallet = Wallet::query()->firstOrCreate(['tenant_id' => $user->tenant_id, 'owner_type' => Wallet::OWNER_USER, 'owner_id' => $user->id]);

        return response()->json(['data' => [
            'account' => $user->tenant?->name,
            'client_id' => $credential->client_id,
            'allowed_services' => $credential->allowed_scopes,
            'rate_limit_per_min' => $credential->rate_limit_per_min,
            'wallet' => ['balance' => (string) $wallet->balance, 'currency' => $wallet->currency ?? 'INR'],
        ]]);
    }

    public function search(Request $request, string $service)
    {
        return app()->call([app($this->controllerFor($service)), 'search'], ['request' => $request]);
    }

    public function hold(Request $request, string $service)
    {
        return app()->call([app($this->controllerFor($service)), 'hold'], ['request' => $request]);
    }

    /**
     * Pays a held booking in full from the partner's wallet. Partners are
     * prepaid: there is no gateway step, so a short wallet is a 402.
     */
    public function confirm(Request $request)
    {
        // Route params arrive positionally, so read them by name.
        $service = (string) $request->route('service');
        $booking = $request->route('booking');
        [$module] = self::MODULES[$service] ?? abort(404);
        $model = $this->ownBooking($request, $booking);
        abort_unless($model->module === $module, 404);

        $request->merge(['wallet_amount' => (string) $model->recalculateTotal()]);

        return app()->call([app($this->controllerFor($service)), 'confirm'], ['request' => $request, 'booking' => $model]);
    }

    public function index(Request $request)
    {
        $query = Booking::withoutTenantScope()
            ->where('tenant_id', $request->user()->tenant_id)
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('service'), fn ($q) => $q->where('module', self::MODULES[$request->string('service')->toString()][0] ?? '-'))
            ->when($request->filled('reference'), fn ($q) => $q->where(fn ($w) => $w->where('booking_code', $request->string('reference'))->orWhere('booking_reference', $request->string('reference'))))
            ->orderByDesc('id');

        return BookingResource::collection($query->with(['passengers', 'segments'])->paginate(min(100, $request->integer('per_page', 25))));
    }

    public function show(Request $request, int|string $booking)
    {
        return new BookingResource($this->ownBooking($request, $booking)->load(['passengers', 'segments', 'statusHistory']));
    }

    public function cancellationQuote(Request $request, int|string $booking)
    {
        $this->ownBooking($request, $booking);

        return app()->call([app(MyBookingCancellationController::class), 'quote'], ['request' => $request, 'booking' => $booking]);
    }

    public function cancel(Request $request, int|string $booking)
    {
        $this->ownBooking($request, $booking);

        return app()->call([app(MyBookingCancellationController::class), 'cancel'], ['request' => $request, 'booking' => $booking]);
    }

    public function openapi()
    {
        return response()->json(json_decode(file_get_contents(base_path('docs/partner-api.openapi.json')), true));
    }

    /** Human-readable reference for partners' developers, rendered from openapi.json. */
    public function docs()
    {
        $spec = url('/api/partner/v1/openapi.json');

        return response(<<<HTML
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>FyndMyTrip Partner API</title></head><body style="margin:0">
<redoc spec-url="{$spec}"></redoc>
<script src="https://cdn.jsdelivr.net/npm/redoc@2.1.5/bundles/redoc.standalone.js"></script>
</body></html>
HTML)->header('Content-Type', 'text/html; charset=utf-8');
    }

    protected function controllerFor(string $service): string
    {
        return (self::MODULES[$service] ?? abort(404, 'Unknown service.'))[1];
    }

    protected function ownBooking(Request $request, int|string $id): Booking
    {
        $booking = Booking::withoutTenantScope()->find($id);
        abort_unless($booking && (int) $booking->tenant_id === (int) $request->user()->tenant_id, 404, 'Booking not found.');

        return $booking;
    }
}
