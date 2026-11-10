<?php

namespace App\Http\Controllers\Api;

use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Wallet\Models\Wallet;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Support\TenantMailBranding;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Register a new B2C customer against the current storefront tenant
     * (resolved by ResolveTenant from the request Host header). Agent/
     * Sub-Agent/Staff accounts are created via TenantController's nested
     * user endpoints, not this public endpoint.
     */
    public function register(Request $request)
    {
        $tenant = $request->attributes->get('resolved_tenant') ?? app(\App\Domains\Tenancy\Services\TenantContext::class)->get();

        if (! $tenant) {
            abort(422, 'Unable to resolve which storefront to register against.');
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'email' => [
                'required', 'email', 'max:191',
                Rule::unique('users')->where('tenant_id', $tenant->id),
            ],
            'phone' => ['nullable', 'string', 'max:20'],
            'password' => ['required', 'string', 'min:8'],
        ]);

        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'password_hash' => Hash::make($data['password']),
            'role' => User::ROLE_CUSTOMER,
            'status' => User::STATUS_ACTIVE,
        ]);

        Wallet::create([
            'tenant_id' => $tenant->id,
            'owner_type' => Wallet::OWNER_USER,
            'owner_id' => $user->id,
        ]);

        $token = $user->createToken('api')->plainTextToken;

        return response()->json([
            'user' => new UserResource($user),
            'token' => $token,
        ], 201);
    }

    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'tenant_uuid' => ['nullable', 'uuid'],
        ]);

        $usersQuery = User::query()->where('email', $data['email']);

        if (! empty($data['tenant_uuid'])) {
            $tenant = Tenant::query()->where('uuid', $data['tenant_uuid'])->first();
            abort_if(! $tenant, 422, 'Unknown tenant.');
            $usersQuery->where('tenant_id', $tenant->id);
        }

        $user = $usersQuery->first();

        if (! $user || ! $user->password_hash || ! Hash::check($data['password'], $user->password_hash)) {
            throw ValidationException::withMessages([
                'email' => ['These credentials do not match our records.'],
            ]);
        }

        if ($user->status !== User::STATUS_ACTIVE) {
            throw ValidationException::withMessages([
                'email' => ["This account is currently {$user->status} and cannot sign in."],
            ]);
        }

        $user->forceFill(['last_login_at' => now()])->save();

        $token = $user->createToken('api')->plainTextToken;

        return response()->json([
            'user' => new UserResource($user),
            'token' => $token,
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out.']);
    }

    public function me(Request $request)
    {
        return new UserResource($request->user());
    }

    /**
     * Self-service profile edit — name/phone/email only. Email uniqueness
     * is scoped to the user's own tenant, same rule as register().
     */
    public function updateProfile(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'phone' => ['nullable', 'string', 'max:20'],
            'email' => [
                'nullable', 'email', 'max:191',
                Rule::unique('users')->where('tenant_id', $user->tenant_id)->ignore($user->id),
            ],
        ]);

        $user->forceFill($data)->save();

        return new UserResource($user->fresh());
    }

    /**
     * Change password while logged in — requires the current password.
     */
    public function changePassword(Request $request)
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = $request->user();

        if (! $user->password_hash || ! Hash::check($data['current_password'], $user->password_hash)) {
            throw ValidationException::withMessages([
                'current_password' => ['Your current password is incorrect.'],
            ]);
        }

        $user->forceFill(['password_hash' => Hash::make($data['password'])])->save();

        return response()->json(['message' => 'Password updated.']);
    }

    /**
     * Request a password reset code. Tenant-scoped (see the
     * password_reset_tokens migration for why) — a plain 8-character code
     * rather than a long opaque token, since the reset page asks the user
     * to type it in rather than following a mailed deep link (multi-tenant
     * custom domains make a single reliable link host awkward to resolve
     * from here). Always returns the same message whether or not the
     * email exists, so this can't be used to enumerate accounts.
     *
     * The actual mail send is wrapped in a try/catch: no notification
     * gateway is configured yet (see NotificationGatewayConfig — schema
     * exists, nothing sends through it today), so right now this
     * typically lands in storage/logs/laravel.log via the default mail
     * driver rather than a real inbox. Once a real mail driver/gateway is
     * wired up, this starts delivering for real with no code change here.
     */
    public function forgotPassword(Request $request)
    {
        $tenant = $request->attributes->get('resolved_tenant') ?? app(\App\Domains\Tenancy\Services\TenantContext::class)->get();
        abort_if(! $tenant, 422, 'Unable to resolve which storefront this reset is for.');

        $data = $request->validate(['email' => ['required', 'email']]);

        $user = User::query()->where('tenant_id', $tenant->id)->where('email', $data['email'])->first();

        if ($user) {
            $code = strtoupper(Str::random(8));

            DB::table('password_reset_tokens')->updateOrInsert(
                ['tenant_id' => $tenant->id, 'email' => $user->email],
                ['token' => Hash::make($code), 'created_at' => now()],
            );

            $branding = TenantMailBranding::resolve($tenant);

            try {
                Mail::raw(
                    "Your {$branding['brand_name']} password reset code is: {$code}\n\nThis code expires in 60 minutes. If you didn't request this, you can ignore this email.",
                    function ($message) use ($user, $branding) {
                        $message->to($user->email)->subject('Your password reset code');

                        if ($branding['from_address']) {
                            $message->from($branding['from_address'], $branding['from_name']);
                        }
                        if ($branding['support_email']) {
                            $message->replyTo($branding['support_email']);
                        }
                    },
                );
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return response()->json(['message' => 'If that email is registered, a password reset code has been sent.']);
    }

    /**
     * Complete a password reset with the code from forgotPassword().
     */
    public function resetPassword(Request $request)
    {
        $tenant = $request->attributes->get('resolved_tenant') ?? app(\App\Domains\Tenancy\Services\TenantContext::class)->get();
        abort_if(! $tenant, 422, 'Unable to resolve which storefront this reset is for.');

        $data = $request->validate([
            'email' => ['required', 'email'],
            'token' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $record = DB::table('password_reset_tokens')
            ->where('tenant_id', $tenant->id)
            ->where('email', $data['email'])
            ->first();

        $codeIsValid = $record
            && Hash::check($data['token'], $record->token)
            && now()->diffInMinutes($record->created_at) <= 60;

        if (! $codeIsValid) {
            throw ValidationException::withMessages([
                'token' => ['This reset code is invalid or has expired.'],
            ]);
        }

        $user = User::query()->where('tenant_id', $tenant->id)->where('email', $data['email'])->firstOrFail();
        $user->forceFill(['password_hash' => Hash::make($data['password'])])->save();

        DB::table('password_reset_tokens')->where('tenant_id', $tenant->id)->where('email', $data['email'])->delete();

        return response()->json(['message' => 'Password updated. You can now sign in.']);
    }
}
