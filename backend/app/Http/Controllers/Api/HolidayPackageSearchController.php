<?php

namespace App\Http\Controllers\Api;

use App\Domains\Holiday\Models\HolidayPackage;
use App\Domains\Tenancy\Services\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

/**
 * The customer-facing (in-portal) package browsing/search endpoints —
 * separate from HolidayPackageController (the admin CRM) because the
 * filters, sort options, and visibility rules are different: any
 * authenticated user browses the shared *active* catalog (their own
 * tenant's packages plus the platform-wide ones), matching the filter set
 * on reference DMC sites (destination, package type, category, name
 * search, sort by price/name).
 *
 * Visibility follows the tenant_id-NULL-is-platform-wide convention plus
 * two opt-in distribution flags a tenant sets on their own packages
 * (HolidayPackageController): share_on_platform_site widens a tenant's
 * package to FyndMyTrip's own direct (no-tenant) storefront, and
 * share_with_network widens it to every tenant — which, since every
 * partner/agent/white-label/API/B2B consumer reads this same endpoint
 * under their own tenant context, is equivalent to "list it everywhere".
 */
class HolidayPackageSearchController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $packages = HolidayPackage::query()
            ->where('status', HolidayPackage::STATUS_ACTIVE)
            ->where(fn ($q) => $this->scopeVisibleTo($q, $user))
            ->when($request->filled('destination_country'), fn ($q) => $q->where('destination_country', strtoupper($request->string('destination_country'))))
            ->when($request->filled('destination_city'), fn ($q) => $q->where('destination_city', $request->string('destination_city')))
            ->when($request->filled('package_type'), fn ($q) => $q->where('package_type', $request->string('package_type')))
            ->when($request->filled('booking_scope'), fn ($q) => $q->where('booking_scope', $request->string('booking_scope')))
            ->when($request->filled('category'), fn ($q) => $q->where('category', $request->string('category')))
            ->when($request->filled('q'), fn ($q) => $q->where('name', 'like', '%'.$request->string('q').'%'))
            ->when($request->filled('fixed_departure'), fn ($q) => $q->where('is_fixed_departure', $request->boolean('fixed_departure')))
            ->when($request->string('sort') === 'price_low', fn ($q) => $q->orderBy('sell_price'))
            ->when($request->string('sort') === 'price_high', fn ($q) => $q->orderByDesc('sell_price'))
            ->when($request->string('sort') === 'name', fn ($q) => $q->orderBy('name'))
            ->when(! $request->filled('sort'), fn ($q) => $q->orderByDesc('created_at'))
            ->paginate($request->integer('per_page', 12));

        return response()->json($packages);
    }

    public function show(Request $request, string $slug)
    {
        $user = $request->user();

        $package = HolidayPackage::query()
            ->where('slug', $slug)
            ->where('status', HolidayPackage::STATUS_ACTIVE)
            ->where(fn ($q) => $this->scopeVisibleTo($q, $user))
            ->with(['itineraryDays', 'priceTiers', 'media'])
            ->firstOrFail();

        return response()->json(['data' => $package]);
    }

    /**
     * Filter facets for the browse page's dropdowns — "destinations need
     * to list in dropdown like country and location based. If they choose
     * international then show filter on international then country to
     * select... if domestic select then show the locations". Built from
     * whatever's actually in the visible catalog rather than a hardcoded
     * list, so it only ever offers choices that return real results, and
     * a newly-added package's country/city/category shows up here with no
     * code change.
     */
    public function filters(Request $request)
    {
        $user = $request->user();

        $base = HolidayPackage::query()
            ->where('status', HolidayPackage::STATUS_ACTIVE)
            ->where(fn ($q) => $this->scopeVisibleTo($q, $user));

        $domesticCities = (clone $base)
            ->where('booking_scope', HolidayPackage::SCOPE_DOMESTIC)
            ->whereNotNull('destination_city')
            ->distinct()
            ->orderBy('destination_city')
            ->pluck('destination_city');

        $internationalCountries = (clone $base)
            ->where('booking_scope', HolidayPackage::SCOPE_INTERNATIONAL)
            ->whereNotNull('destination_country')
            ->distinct()
            ->orderBy('destination_country')
            ->pluck('destination_country');

        $categories = (clone $base)
            ->whereNotNull('category')
            ->distinct()
            ->orderBy('category')
            ->pluck('category');

        return response()->json([
            'domestic_cities' => $domesticCities,
            'international_countries' => $internationalCountries,
            'categories' => $categories,
        ]);
    }

    /**
     * "Once customers enquired email need to go with details ... they
     * need to get complete package with contact details and all
     * information via email" — a public "email me this itinerary" action,
     * separate from booking (no travel date/travelers required). Sends
     * the full brochure — route, duration, category, inclusions/
     * exclusions, per-occupancy pricing, terms — plus the operating
     * tenant's own contact details, straight to whatever email the
     * customer gave. Like forgotPassword()/HolidayPackagePdfController,
     * this goes through Laravel's configured mail driver — currently
     * that means storage/logs/laravel.log until a real mail
     * gateway/notification config is wired up, at which point this starts
     * delivering for real with no code change here.
     */
    public function enquire(Request $request, string $slug)
    {
        $user = $request->user();

        $package = HolidayPackage::query()
            ->where('slug', $slug)
            ->where('status', HolidayPackage::STATUS_ACTIVE)
            ->where(fn ($q) => $this->scopeVisibleTo($q, $user))
            ->with(['itineraryDays', 'priceTiers'])
            ->firstOrFail();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'email' => ['required', 'email', 'max:191'],
            'phone' => ['nullable', 'string', 'max:20'],
            'message' => ['nullable', 'string', 'max:1000'],
        ]);

        $tenant = $package->tenant ?? (app(TenantContext::class)->get());
        $supportEmail = $tenant?->support_email ?? 'care@fyndmytrip.com';
        $supportPhone = $tenant?->support_phone ?? '9841811880';
        $brandName = $tenant?->legal_name ?? $tenant?->name ?? 'FyndMyTrip';

        $lines = [];
        $lines[] = "Hi {$data['name']},";
        $lines[] = '';
        $lines[] = "Here are the full details of {$package->name}:";
        $lines[] = $package->route_summary ?? $package->destination_city ?? '';
        $lines[] = "{$package->duration_nights} nights / {$package->duration_days} days · ".ucfirst($package->package_type).
            ($package->category ? " · {$package->category}" : '').
            ($package->hotel_category ? " · {$package->hotel_category} hotels" : '');
        $lines[] = "Starts from {$package->currency} ".number_format((float) $package->sell_price, 2).' per person (GST excluded)';
        $lines[] = '';

        if ($package->itineraryDays->count() > 0) {
            $lines[] = 'Day-wise itinerary:';
            foreach ($package->itineraryDays as $day) {
                $lines[] = "  Day {$day->day_number}: {$day->title}".($day->description ? " — {$day->description}" : '');
            }
            $lines[] = '';
        }

        if ($package->inclusions) {
            $lines[] = 'Inclusions:';
            foreach ($package->inclusions as $item) {
                $lines[] = "  + {$item}";
            }
            $lines[] = '';
        }

        if ($package->exclusions) {
            $lines[] = 'Exclusions:';
            foreach ($package->exclusions as $item) {
                $lines[] = "  - {$item}";
            }
            $lines[] = '';
        }

        if ($package->priceTiers->count() > 0) {
            $lines[] = 'Pricing by occupancy:';
            foreach ($package->priceTiers as $tier) {
                $lines[] = "  {$tier->occupancy_type}: {$tier->currency} ".number_format((float) $tier->sell_price, 2).' per person';
            }
            $lines[] = '';
        }

        if ($package->terms) {
            $lines[] = 'Terms & conditions:';
            foreach ($package->terms as $term) {
                $lines[] = "  - {$term}";
            }
            $lines[] = '';
        }

        $lines[] = "Ready to book, or have questions? Call us on {$supportPhone} or reply to {$supportEmail}.";
        $lines[] = '';
        $lines[] = "— {$brandName}";

        try {
            Mail::raw(implode("\n", $lines), function ($message) use ($data, $package, $supportEmail, $brandName) {
                $message->to($data['email'])
                    ->replyTo($supportEmail)
                    ->subject("Your requested details: {$package->name}")
                    ->from(config('mail.from.address'), $brandName);
            });
        } catch (\Throwable $e) {
            report($e);
        }

        return response()->json(['message' => 'Package details sent to '.$data['email'].'.']);
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<HolidayPackage>  $query
     */
    protected function scopeVisibleTo($query, $user)
    {
        // Guest (no logged-in user, no tenant context) browsing the public
        // storefront — same visibility a logged-in direct/no-tenant B2C
        // customer would get: the shared platform catalog plus anything a
        // tenant explicitly opted to show on the direct site or network-wide.
        if (! $user) {
            return $query->whereNull('tenant_id')
                ->orWhere('share_on_platform_site', true)
                ->orWhere('share_with_network', true);
        }

        return $query->whereNull('tenant_id')
            ->orWhere('tenant_id', $user->tenant_id)
            ->orWhere('share_with_network', true)
            ->when(is_null($user->tenant_id), fn ($q) => $q->orWhere('share_on_platform_site', true));
    }
}
