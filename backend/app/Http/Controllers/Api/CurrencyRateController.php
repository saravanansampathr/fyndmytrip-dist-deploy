<?php

namespace App\Http\Controllers\Api;

use App\Domains\Payments\Services\CurrencyService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * The majorly used currencies, converted from the platform's base currency
 * (INR) via a free, no-key feed (open.er-api.com) cached for 6 hours, plus
 * the currency suggested for this visitor from their country.
 */
class CurrencyRateController extends Controller
{
    public const SUPPORTED = CurrencyService::SUPPORTED;

    public function index(Request $request, CurrencyService $currencies)
    {
        $country = $currencies->detectCountry($request);
        $rates = $currencies->rates();
        $suggested = $currencies->currencyForCountry($country);

        return response()->json(['data' => [
            'base' => $currencies->base(),
            'rates' => $rates,
            'country' => $country,
            'suggested' => isset($rates[$suggested]) ? $suggested : $currencies->base(),
            'fetched_at' => now()->toIso8601String(),
        ]]);
    }
}
