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
use Illuminate\Support\Facades\RateLimiter;
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
            // users has a (tenant_id, phone) unique index: say so nicely
            // instead of failing with a database error.
            'phone' => [
                'nullable', 'string', 'max:20',
                Rule::unique('users')->where('tenant_id', $tenant->id),
            ],
            'password' => ['required', 'string', 'min:8'],
            'marketing_consent' => ['nullable', 'boolean'],
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

        // Signing up means accepting the terms shown on the form; offers
        // only with a separate, unticked-by-default opt-in (DPDP Act).
        $user->forceFill(['terms_accepted_at' => now(), 'marketing_consent' => (bool) ($data['marketing_consent'] ?? false)])->save();
        $privacy = app(\App\Domains\Privacy\Services\PrivacyService::class);
        $privacy->log($user, 'terms', true, $request);
        if ($user->marketing_consent) {
            $privacy->setMarketing($user, true, $request);
        }

        $token = $user->issueApiToken();

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
            'two_factor_code' => ['nullable', 'string', 'max:20'],
        ]);

        // Lock the email (from this address) out for a while after too
        // many wrong passwords or codes. Limits are set in admin
        // (Security settings).
        $security = app(\App\Domains\Settings\Services\SettingsService::class)->get('security');
        $maxAttempts = max(1, (int) $security['login_max_attempts']);
        $lockSeconds = max(1, (int) $security['login_lockout_minutes']) * 60;
        $throttleKey = 'login:'.Str::lower($data['email']).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, $maxAttempts)) {
            $minutes = (int) ceil(RateLimiter::availableIn($throttleKey) / 60);

            return response()->json([
                'message' => "Too many sign-in attempts. Please try again in {$minutes} minute(s).",
                'errors' => ['email' => ["Too many sign-in attempts. Please try again in {$minutes} minute(s)."]],
            ], 429);
        }

        $usersQuery = User::query()->where('email', $data['email']);

        if (! empty($data['tenant_uuid'])) {
            $tenant = Tenant::query()->where('uuid', $data['tenant_uuid'])->first();
            abort_if(! $tenant, 422, 'Unknown tenant.');
            $usersQuery->where('tenant_id', $tenant->id);
        }

        $user = $usersQuery->first();

        if (! $user || ! $user->password_hash || ! Hash::check($data['password'], $user->password_hash)) {
            RateLimiter::hit($throttleKey, $lockSeconds);
            throw ValidationException::withMessages([
                'email' => ['These credentials do not match our records.'],
            ]);
        }

        if ($user->status !== User::STATUS_ACTIVE) {
            throw ValidationException::withMessages([
                'email' => ["This account is currently {$user->status} and cannot sign in."],
            ]);
        }

        $twoFactor = app(\App\Domains\Auth\Services\TwoFactorService::class);
        if ($twoFactor->isEnabled($user)) {
            if (empty($data['two_factor_code'])) {
                return response()->json([
                    'message' => 'Enter the 6-digit code from your authenticator app.',
                    'two_factor_required' => true,
                    'errors' => ['two_factor_code' => ['Enter the 6-digit code from your authenticator app.']],
                ], 422);
            }
            if (! $twoFactor->attempt($user, $data['two_factor_code'])) {
                RateLimiter::hit($throttleKey, $lockSeconds);

                return response()->json([
                    'message' => 'That code is not valid. Check the time on your phone and try again.',
                    'two_factor_required' => true,
                    'errors' => ['two_factor_code' => ['That code is not valid.']],
                ], 422);
            }
        }

        RateLimiter::clear($throttleKey);
        $user->forceFill(['last_login_at' => now()])->save();

        $token = $user->issueApiToken();

        return response()->json([
            'user' => new UserResource($user),
            'token' => $token,
            // Admin requires 2FA for this role and it isn't set up yet:
            // the portal sends the user to the security page.
            'two_factor_setup_required' => ! $twoFactor->isEnabled($user)
                && in_array($user->role, (array) $security['require_2fa_roles'], true),
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
            'phone' => [
                'nullable', 'string', 'max:20',
                Rule::unique('users')->where('tenant_id', $user->tenant_id)->ignore($user->id),
            ],
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
        // Sign out every other device that was using the old password.
        $current = $user->currentAccessToken();
        $user->tokens()->when($current && isset($current->id), fn ($q) => $q->where('id', '!=', $current->id))->delete();

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
        $user->tokens()->delete();

        DB::table('password_reset_tokens')->where('tenant_id', $tenant->id)->where('email', $data['email'])->delete();

        return response()->json(['message' => 'Password updated. You can now sign in.']);
    }
}
