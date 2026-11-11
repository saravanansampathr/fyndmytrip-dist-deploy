<?php

namespace App\Domains\Auth\Services;

use App\Domains\Booking\Models\Booking;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Wallet\Models\Wallet;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Guest checkout ("book as guest") for flights, hotels, buses and holiday
 * packages.
 *
 * A guest is never signed in to an account. Earlier versions created a
 * real customer account from the guest's email and phone and handed back
 * a full login token, so the visitor ended up "logged in" under that email
 * without ever giving a password or a code, and a phone number that some
 * other account already used blocked the guest from booking at all.
 *
 * Now every guest booking gets its own private guest user:
 *   - no email, phone or password, so it can't be signed in to, can't be
 *     found by "forgot password", and never clashes with a real account;
 *   - the guest's contact email and phone go on the booking itself, which
 *     is where tickets, vouchers and messages are sent;
 *   - the token returned is a short-lived "guest-checkout" token that the
 *     RestrictGuestCheckoutSessions middleware only lets through to this
 *     booking's own pages (view, extras, pay, ticket, invoice).
 *
 * Whether the email or phone belongs to an existing account doesn't matter
 * and isn't revealed: the guest books the same way either way.
 */
class GuestCheckoutService
{
    public const TOKEN_NAME = 'guest-checkout';

    public const TOKEN_ABILITY = 'guest-checkout';

    /** How long a guest checkout session lasts (holds expire well before). */
    public const SESSION_HOURS = 6;

    /**
     * Validate the guest fields, and copy the guest's email/phone into the
     * booking's contact fields when the form didn't send them separately.
     */
    public function validate(Request $request): array
    {
        $guest = $request->validate([
            'guest_name' => ['required', 'string', 'max:191'],
            'guest_email' => ['required', 'email', 'max:191'],
            'guest_phone' => ['nullable', 'string', 'max:20'],
        ]);

        $request->merge(array_filter([
            'contact_email' => $request->input('contact_email') ?: $guest['guest_email'],
            'contact_phone' => $request->input('contact_phone') ?: ($guest['guest_phone'] ?? null),
        ], fn ($v) => $v !== null && $v !== ''));

        return $guest;
    }

    public function createGuest(Tenant $tenant, array $guest): User
    {
        return DB::transaction(function () use ($tenant, $guest) {
            $user = new User([
                'tenant_id' => $tenant->id,
                'name' => $guest['guest_name'],
                'email' => null,
                'phone' => null,
                'password_hash' => null,
                'role' => User::ROLE_CUSTOMER,
                'status' => User::STATUS_ACTIVE,
            ]);
            $user->is_guest = true;
            $user->save();

            // Some checkout screens read the wallet; a guest's is always empty.
            Wallet::create([
                'tenant_id' => $tenant->id,
                'owner_type' => Wallet::OWNER_USER,
                'owner_id' => $user->id,
            ]);

            return $user;
        });
    }

    /** Remove a guest user whose booking never got created. */
    public function discard(User $user): void
    {
        if (! $user->is_guest || Booking::query()->where('created_by_user_id', $user->id)->exists()) {
            return;
        }
        DB::transaction(function () use ($user) {
            Wallet::query()->where('owner_type', Wallet::OWNER_USER)->where('owner_id', $user->id)->delete();
            $user->tokens()->delete();
            $user->forceDelete();
        });
    }

    public function issueToken(User $user): string
    {
        return $user->createToken(self::TOKEN_NAME, [self::TOKEN_ABILITY], now()->addHours(self::SESSION_HOURS))->plainTextToken;
    }

    /** Add the guest session to a successful booking response. */
    public function respond(Request $request, User $user, array $payload): JsonResponse
    {
        $payload['user'] = (new UserResource($user))->resolve($request);
        $payload['token'] = $this->issueToken($user);
        $payload['guest_checkout'] = true;
        $payload['guest_session_expires_at'] = now()->addHours(self::SESSION_HOURS)->toIso8601String();

        return response()->json($payload, 201);
    }
}
