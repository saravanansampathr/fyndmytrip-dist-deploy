<?php

namespace App\Http\Controllers\Api;

use App\Domains\Visa\Models\VisaProduct;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * Customer-facing (in-portal) visa browsing — grouped by destination
 * country, matching the "select a country, see visa types/price/
 * processing time" pattern on reference visa sites.
 *
 * Guest-accessible — same "browse without login" convention as
 * HolidayPackageSearchController (see its scopeVisibleTo() docblock) and
 * Flights/Hotels/Buses/Cabs/Insurance/Cruises/Activities search: $user is
 * null for an anonymous visitor, who sees the platform-wide (no-tenant)
 * catalog only, same as a logged-in direct/no-tenant customer would.
 */
class VisaProductSearchController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $products = VisaProduct::query()
            ->where('status', VisaProduct::STATUS_ACTIVE)
            ->where(fn ($q) => $user
                ? $q->whereNull('tenant_id')->orWhere('tenant_id', $user->tenant_id)
                : $q->whereNull('tenant_id'))
            ->when($request->filled('destination_country'), fn ($q) => $q->where('destination_country', strtoupper($request->string('destination_country'))))
            ->when($request->filled('q'), fn ($q) => $q->where('destination_country_name', 'like', '%'.$request->string('q').'%'))
            ->orderBy('destination_country_name')
            ->paginate($request->integer('per_page', 25));

        return response()->json($products);
    }

    public function show(Request $request, VisaProduct $visaProduct)
    {
        $user = $request->user();

        abort_unless(
            $visaProduct->status === VisaProduct::STATUS_ACTIVE
                && ($visaProduct->tenant_id === null || $visaProduct->tenant_id === $user?->tenant_id),
            404
        );

        return response()->json(['data' => $visaProduct]);
    }
}
