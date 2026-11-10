<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The backlog's "multi-currency selection… need to change the currency and
 * use majorly used currency only" — a curated set of majorly-traded
 * currencies (not all ~170 ISO ones) converted from the platform's base
 * currency (INR), backed by a free, no-key exchange-rate feed
 * (open.er-api.com) rather than a paid FX subscription, and cached
 * server-side so every storefront page load doesn't re-hit that feed —
 * "use properly without exceeding the credit limit."
 */
class CurrencyRateController extends Controller
{
    /** Majorly used currencies for this platform's markets — India (home),
     * the Gulf corridor, South-East Asia, and the usual Western majors. */
    public const SUPPORTED = ['INR', 'USD', 'EUR', 'GBP', 'AED', 'SAR', 'SGD', 'AUD', 'CAD', 'MYR', 'THB', 'JPY'];

    public function index(Request $request)
    {
        $base = config('booking.pricing_currency', 'INR');

        $rates = Cache::remember("currency_rates.{$base}", now()->addHours(6), function () use ($base) {
            try {
                $response = Http::timeout(5)->get("https://open.er-api.com/v6/latest/{$base}");

                if (! $response->ok() || ($response->json('result') !== 'success')) {
                    throw new \RuntimeException('Exchange rate provider returned a non-success response.');
                }

                $all = $response->json('rates', []);

                return collect(self::SUPPORTED)
                    ->mapWithKeys(fn ($code) => [$code => $all[$code] ?? null])
                    ->filter()
                    ->all();
            } catch (\Throwable $e) {
                Log::warning('Currency rate fetch failed, falling back to base-only.', ['error' => $e->getMessage()]);

                // Degrade gracefully rather than break the storefront: only
                // the base currency is offered until the feed recovers.
                return [$base => 1.0];
            }
        });

        return response()->json(['data' => [
            'base' => $base,
            'rates' => $rates,
            'fetched_at' => now()->toIso8601String(),
        ]]);
    }
}
