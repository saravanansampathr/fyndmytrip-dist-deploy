<?php

namespace App\Domains\Payments\Services;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Prices are kept in INR (the platform's pricing currency). A customer can
 * pay in their own currency: INR in India, and the local major currency
 * elsewhere (USD, AED, AUD, SGD, EUR, GBP, ...), picked from their country.
 * The gateway is charged the INR amount converted at the cached rate, and
 * the charge currency, rate and amount are kept on the PaymentTransaction.
 */
class CurrencyService
{
    /** Majorly used currencies for this platform's markets. */
    public const SUPPORTED = [
        'INR', 'USD', 'EUR', 'GBP', 'AED', 'SAR', 'QAR', 'OMR', 'KWD', 'BHD',
        'SGD', 'AUD', 'NZD', 'CAD', 'MYR', 'THB', 'JPY', 'HKD', 'CHF', 'LKR', 'NPR', 'ZAR',
    ];

    public const EUROZONE = ['AT', 'BE', 'CY', 'DE', 'EE', 'ES', 'FI', 'FR', 'GR', 'HR', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PT', 'SI', 'SK'];

    /** Country (ISO 3166 alpha-2) => currency, for countries whose own currency is supported. */
    public const COUNTRY_CURRENCY = [
        'IN' => 'INR', 'US' => 'USD', 'GB' => 'GBP', 'AE' => 'AED', 'SA' => 'SAR', 'QA' => 'QAR',
        'OM' => 'OMR', 'KW' => 'KWD', 'BH' => 'BHD', 'SG' => 'SGD', 'AU' => 'AUD', 'NZ' => 'NZD',
        'CA' => 'CAD', 'MY' => 'MYR', 'TH' => 'THB', 'JP' => 'JPY', 'HK' => 'HKD', 'CH' => 'CHF',
        'LK' => 'LKR', 'NP' => 'NPR', 'ZA' => 'ZAR',
    ];

    public function base(): string
    {
        return (string) config('booking.pricing_currency', 'INR');
    }

    /** @return array<string, float> rates from the base currency, always including the base itself. */
    public function rates(): array
    {
        $base = $this->base();

        // FX_FIXED_RATES="USD=0.012,AED=0.044" pins rates instead of the live feed.
        $fixed = collect(explode(',', (string) config('booking.fx_fixed_rates', '')))
            ->map(fn ($pair) => array_map('trim', explode('=', $pair, 2)))
            ->filter(fn ($p) => count($p) === 2 && in_array(strtoupper($p[0]), self::SUPPORTED, true) && is_numeric($p[1]) && (float) $p[1] > 0)
            ->mapWithKeys(fn ($p) => [strtoupper($p[0]) => (float) $p[1]]);
        if ($fixed->isNotEmpty()) {
            return $fixed->put($base, 1.0)->all();
        }

        return Cache::remember("currency_rates.{$base}", now()->addHours(6), function () use ($base) {
            try {
                $response = Http::timeout(5)->get("https://open.er-api.com/v6/latest/{$base}");
                if (! $response->ok() || $response->json('result') !== 'success') {
                    throw new \RuntimeException('Exchange rate provider returned a non-success response.');
                }
                $all = $response->json('rates', []);

                return collect(self::SUPPORTED)->mapWithKeys(fn ($c) => [$c => $all[$c] ?? null])->filter()->put($base, 1.0)->all();
            } catch (\Throwable $e) {
                Log::warning('Currency rate fetch failed, falling back to base-only.', ['error' => $e->getMessage()]);

                return [$base => 1.0];
            }
        });
    }

    public function currencyForCountry(?string $country): string
    {
        $country = strtoupper(trim((string) $country));
        if ($country === '' || $country === 'XX' || $country === 'T1') {
            return $this->base();
        }
        if (in_array($country, self::EUROZONE, true)) {
            return 'EUR';
        }

        return self::COUNTRY_CURRENCY[$country] ?? 'USD';
    }

    /**
     * Where the visitor is: an explicit ?country=, then the CDN's country
     * header (Cloudflare CF-IPCountry, or X-Country-Code from a proxy),
     * then the browser's time zone sent as ?tz= (Asia/Kolkata => IN,
     * Asia/Dubai => AE). Accept-Language is not used: many browsers in
     * India send en-US.
     */
    public function detectCountry(Request $request): ?string
    {
        foreach ([$request->query('country'), $request->header('CF-IPCountry'), $request->header('X-Country-Code')] as $c) {
            if (is_string($c) && preg_match('/^[A-Za-z]{2}$/', $c) && ! in_array(strtoupper($c), ['XX', 'T1'], true)) {
                return strtoupper($c);
            }
        }
        $tz = $request->query('tz');
        if (is_string($tz) && $tz !== '' && strlen($tz) <= 64) {
            if (in_array($tz, ['Asia/Calcutta', 'Asia/Kolkata'], true)) {
                return 'IN';
            }
            try {
                $code = (new \DateTimeZone($tz))->getLocation()['country_code'] ?? null;
                if (is_string($code) && preg_match('/^[A-Z]{2}$/', $code)) {
                    return $code;
                }
            } catch (\Throwable) {
                // Unknown zone name: no signal.
            }
        }

        return null;
    }

    /**
     * The currency the gateway is charged in and the converted amount.
     * An unsupported currency, or one without a rate right now, is charged
     * in the base currency instead.
     *
     * @return array{currency: string, rate: string, amount: string}
     */
    public function chargeFor(string $baseAmount, ?string $currency): array
    {
        $currency = strtoupper((string) $currency);
        $rates = $this->rates();
        if (! in_array($currency, self::SUPPORTED, true) || ! isset($rates[$currency])) {
            $currency = $this->base();
        }
        $rate = BigDecimal::of((string) ($rates[$currency] ?? 1));
        $decimals = in_array($currency, ['JPY'], true) ? 0 : (in_array($currency, ['KWD', 'BHD', 'OMR'], true) ? 3 : 2);

        return [
            'currency' => $currency,
            'rate' => (string) $rate->toScale(8, RoundingMode::HALF_UP),
            'amount' => (string) BigDecimal::of($baseAmount)->multipliedBy($rate)->toScale($decimals, RoundingMode::HALF_UP),
        ];
    }
}
