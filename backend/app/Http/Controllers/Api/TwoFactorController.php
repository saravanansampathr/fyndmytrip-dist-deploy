<?php

namespace App\Http\Controllers\Api;

use App\Domains\Auth\Services\TwoFactorService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Two-factor login for the signed-in user: start set-up (QR code), confirm
 * with a first code, turn off, or get new recovery codes.
 */
class TwoFactorController extends Controller
{
    public function __construct(protected TwoFactorService $twoFactor)
    {
    }

    public function show(Request $request)
    {
        $user = $request->user();

        return response()->json(['data' => [
            'enabled' => $this->twoFactor->isEnabled($user),
            'confirmed_at' => $user->two_factor_confirmed_at?->toIso8601String(),
        ]]);
    }

    public function setup(Request $request)
    {
        $user = $request->user();
        abort_if($this->twoFactor->isEnabled($user), 409, 'Two-factor login is already on. Turn it off first to move to a new phone.');

        $secret = $this->twoFactor->generateSecret();
        $user->forceFill(['two_factor_secret' => Crypt::encryptString($secret), 'two_factor_confirmed_at' => null])->save();
        $url = $this->twoFactor->otpauthUrl($user, $secret);

        return response()->json(['data' => [
            'secret' => $secret,
            'otpauth_url' => $url,
            'qr_svg' => $this->twoFactor->qrSvg($url),
        ]]);
    }

    public function confirm(Request $request)
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:10']]);
        $user = $request->user();
        $secret = $this->twoFactor->secretFor($user);
        abort_if(! $secret, 409, 'Start set-up first.');

        if (! $this->twoFactor->verify($secret, $data['code'])) {
            throw ValidationException::withMessages(['code' => ['That code is not valid. Check the time on your phone and try again.']]);
        }

        $user->forceFill(['two_factor_confirmed_at' => now()])->save();

        return response()->json([
            'message' => 'Two-factor login is on.',
            'data' => ['recovery_codes' => $this->twoFactor->newRecoveryCodes($user)],
        ]);
    }

    public function recoveryCodes(Request $request)
    {
        $this->checkPassword($request);
        abort_unless($this->twoFactor->isEnabled($request->user()), 409, 'Two-factor login is not on.');

        return response()->json(['data' => ['recovery_codes' => $this->twoFactor->newRecoveryCodes($request->user())]]);
    }

    public function disable(Request $request)
    {
        $this->checkPassword($request);
        $request->user()->forceFill(['two_factor_secret' => null, 'two_factor_confirmed_at' => null, 'two_factor_recovery_codes' => null])->save();

        return response()->json(['message' => 'Two-factor login is off.']);
    }

    protected function checkPassword(Request $request): void
    {
        $data = $request->validate(['password' => ['required', 'string']]);
        if (! Hash::check($data['password'], (string) $request->user()->password_hash)) {
            throw ValidationException::withMessages(['password' => ['Your password is incorrect.']]);
        }
    }
}
