<?php

namespace App\Domains\Auth\Services;

use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Time-based one-time codes (RFC 6238, the same codes Google
 * Authenticator, Microsoft Authenticator and Authy show), plus one-use
 * recovery codes for a lost phone.
 */
class TwoFactorService
{
    protected const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function generateSecret(): string
    {
        $bytes = random_bytes(20);
        $bits = '';
        foreach (str_split($bytes) as $b) {
            $bits .= str_pad(decbin(ord($b)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }

        return $out;
    }

    public function code(string $secret, ?int $timestamp = null): string
    {
        $key = $this->base32Decode($secret);
        $counter = intdiv($timestamp ?? time(), 30);
        $hash = hash_hmac('sha1', pack('N*', 0, $counter), $key, true);
        $offset = ord($hash[19]) & 0x0F;
        $value = ((ord($hash[$offset]) & 0x7F) << 24) | (ord($hash[$offset + 1]) << 16) | (ord($hash[$offset + 2]) << 8) | ord($hash[$offset + 3]);

        return str_pad((string) ($value % 1000000), 6, '0', STR_PAD_LEFT);
    }

    /** Accepts the current code and one step either side (phone clock drift). */
    public function verify(string $secret, string $code): bool
    {
        $code = preg_replace('/\D/', '', $code);
        if (strlen($code) !== 6) {
            return false;
        }
        foreach ([-1, 0, 1] as $step) {
            if (hash_equals($this->code($secret, time() + $step * 30), $code)) {
                return true;
            }
        }

        return false;
    }

    public function otpauthUrl(User $user, string $secret): string
    {
        $issuer = rawurlencode(config('app.name', 'FyndMyTrip'));
        $label = rawurlencode(config('app.name', 'FyndMyTrip').':'.($user->email ?? $user->phone ?? $user->uuid));

        return "otpauth://totp/{$label}?secret={$secret}&issuer={$issuer}&digits=6&period=30";
    }

    public function qrSvg(string $text): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle(220, 1), new SvgImageBackEnd()));

        return $writer->writeString($text);
    }

    public function secretFor(User $user): ?string
    {
        return $user->two_factor_secret ? Crypt::decryptString($user->two_factor_secret) : null;
    }

    public function isEnabled(User $user): bool
    {
        return $user->two_factor_confirmed_at !== null && $user->two_factor_secret !== null;
    }

    /** @return string[] plain codes, shown to the user once */
    public function newRecoveryCodes(User $user): array
    {
        $codes = collect(range(1, 8))->map(fn () => Str::upper(Str::random(5).'-'.Str::random(5)))->all();
        $user->forceFill(['two_factor_recovery_codes' => Crypt::encryptString(json_encode(array_map(fn ($c) => Hash::make($c), $codes)))])->save();

        return $codes;
    }

    /** Checks a TOTP code, or uses up one recovery code. */
    public function attempt(User $user, string $input): bool
    {
        $secret = $this->secretFor($user);
        if ($secret && $this->verify($secret, $input)) {
            return true;
        }

        $hashes = $user->two_factor_recovery_codes ? json_decode(Crypt::decryptString($user->two_factor_recovery_codes), true) : [];
        foreach ($hashes as $i => $hash) {
            if (Hash::check(strtoupper(trim($input)), $hash)) {
                unset($hashes[$i]);
                $user->forceFill(['two_factor_recovery_codes' => Crypt::encryptString(json_encode(array_values($hashes)))])->save();

                return true;
            }
        }

        return false;
    }

    protected function base32Decode(string $secret): string
    {
        $secret = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $secret));
        $bits = '';
        foreach (str_split($secret) as $c) {
            $bits .= str_pad(decbin(strpos(self::ALPHABET, $c)), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }

        return $out;
    }
}
