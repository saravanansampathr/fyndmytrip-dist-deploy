<?php

namespace App\Http\Controllers\Api;

use App\Domains\Holiday\Models\HolidayPackage;
use App\Domains\Holiday\Models\HolidayPackageItineraryDay;
use App\Domains\Holiday\Models\HolidayPackageMedia;
use App\Domains\Holiday\Models\HolidayPackagePriceTier;
use App\Http\Controllers\Concerns\FiltersByDateRangePreset;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The Holiday Management (DMC) admin CRM screen: staff/partner admins
 * manage the package catalog (name, itinerary, inclusions/exclusions,
 * pricing). Mirrors CashbackRuleController/PaymentMethodRouteController's
 * tenant-scoping pattern exactly — non-platform-staff only ever manage
 * their own tenant's packages, platform staff can also curate the
 * platform-wide (tenant_id NULL) shared catalog every tenant sells from.
 */
class HolidayPackageController extends Controller
{
    use FiltersByDateRangePreset;

    public function index(Request $request)
    {
        $user = $request->user();

        $dateFilterActive = $request->filled('range') || $request->filled('from') || $request->filled('to');
        [$from, $to] = $dateFilterActive ? $this->resolveDateRangePreset($request) : [null, null];

        $packages = HolidayPackage::query()
            ->when(! $user->isPlatformStaff(), fn ($q) => $q->where('tenant_id', $user->tenant_id))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($dateFilterActive, fn ($q) => $q->whereBetween('created_at', [$from, $to]))
            ->when($request->filled('q'), function ($q) use ($request) {
                $term = '%'.$request->string('q').'%';
                $q->where(function ($sub) use ($term) {
                    $sub->where('name', 'like', $term)
                        ->orWhere('destination_city', 'like', $term)
                        ->orWhere('destination_country', 'like', $term)
                        ->orWhere('route_summary', 'like', $term);
                });
            })
            ->withCount('itineraryDays')
            ->orderByDesc('created_at')
            ->paginate(min($request->integer('per_page', 25), 500));

        return response()->json($packages);
    }

    public function show(Request $request, HolidayPackage $holidayPackage)
    {
        $this->authorizeAccess($request->user(), $holidayPackage);

        $holidayPackage->load(['itineraryDays', 'priceTiers', 'media']);

        return response()->json(['data' => $holidayPackage]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        $data = $this->validated($request, $user);

        $package = DB::transaction(function () use ($data, $request) {
            $package = HolidayPackage::query()->create($data['package']);
            $this->syncItineraryDays($package, $data['itinerary_days']);
            $this->syncPriceTiers($package, $data['price_tiers']);
            $this->syncMedia($package, $data['media']);

            return $package;
        });

        return response()->json(['data' => $package->load(['itineraryDays', 'priceTiers', 'media'])], 201);
    }

    public function update(Request $request, HolidayPackage $holidayPackage)
    {
        $user = $request->user();
        $this->authorizeAccess($user, $holidayPackage);

        $data = $this->validated($request, $user, $holidayPackage);

        DB::transaction(function () use ($data, $holidayPackage) {
            $holidayPackage->update($data['package']);

            if ($data['itinerary_days'] !== null) {
                $this->syncItineraryDays($holidayPackage, $data['itinerary_days']);
            }

            if ($data['price_tiers'] !== null) {
                $this->syncPriceTiers($holidayPackage, $data['price_tiers']);
            }

            if ($data['media'] !== null) {
                $this->syncMedia($holidayPackage, $data['media']);
            }
        });

        return response()->json(['data' => $holidayPackage->fresh(['itineraryDays', 'priceTiers', 'media'])]);
    }

    public function destroy(Request $request, HolidayPackage $holidayPackage)
    {
        $this->authorizeAccess($request->user(), $holidayPackage);

        $holidayPackage->delete();

        return response()->json(null, 204);
    }

    protected function authorizeAccess($user, HolidayPackage $package): void
    {
        if ($user->isPlatformStaff()) {
            return;
        }

        abort_if($package->tenant_id !== $user->tenant_id, 403, 'You cannot manage another tenant\'s package.');
    }

    protected function syncItineraryDays(HolidayPackage $package, ?array $days): void
    {
        if ($days === null) {
            return;
        }

        $package->itineraryDays()->delete();

        foreach ($days as $day) {
            $package->itineraryDays()->create($day);
        }
    }

    protected function syncPriceTiers(HolidayPackage $package, ?array $tiers): void
    {
        if ($tiers === null) {
            return;
        }

        $package->priceTiers()->delete();

        foreach ($tiers as $tier) {
            if (empty($tier['sell_price']) && ! empty($tier['net_price'])) {
                $tier['sell_price'] = HolidayPackage::priceWithMarkup(
                    $tier['net_price'],
                    $tier['markup_percent'] ?? $package->markup_percent
                );
            }

            $package->priceTiers()->create($tier);
        }
    }

    protected function syncMedia(HolidayPackage $package, ?array $media): void
    {
        if ($media === null) {
            return;
        }

        $package->media()->delete();

        foreach ($media as $index => $item) {
            $package->media()->create([
                'media_type' => $item['media_type'] ?? HolidayPackageMedia::TYPE_IMAGE,
                'url' => $item['url'],
                'caption' => $item['caption'] ?? null,
                'sort_order' => $item['sort_order'] ?? $index,
            ]);
        }
    }

    protected function validated(Request $request, $user, ?HolidayPackage $existing = null): array
    {
        $data = $request->validate([
            'tenant_id' => ['nullable', 'integer', 'exists:tenants,id'],
            'share_on_platform_site' => ['nullable', 'boolean'],
            'share_with_network' => ['nullable', 'boolean'],
            'supplier_code' => ['nullable', 'in:'.implode(',', [
                HolidayPackage::SUPPLIER_AKBAR, HolidayPackage::SUPPLIER_TRIPJACK, HolidayPackage::SUPPLIER_MANUAL,
            ])],
            'supplier_package_ref' => ['nullable', 'string', 'max:191'],
            'slug' => ['nullable', 'string', 'max:191', 'unique:holiday_packages,slug'.($existing ? ','.$existing->id : '')],
            'name' => ['required', 'string', 'max:191'],
            'package_type' => ['required', 'in:'.HolidayPackage::TYPE_TOUR.','.HolidayPackage::TYPE_ACTIVITY],
            'booking_scope' => ['required', 'in:'.HolidayPackage::SCOPE_DOMESTIC.','.HolidayPackage::SCOPE_INTERNATIONAL],
            'category' => ['nullable', 'string', 'max:100'],
            'destination_country' => ['nullable', 'string', 'size:2'],
            'destination_city' => ['nullable', 'string', 'max:191'],
            'route_summary' => ['nullable', 'string', 'max:255'],
            'duration_nights' => ['required', 'integer', 'min:0'],
            'duration_days' => ['required', 'integer', 'min:1'],
            'max_pax_label' => ['nullable', 'string', 'max:100'],
            'hotel_category' => ['nullable', 'string', 'max:100'],
            'is_fixed_departure' => ['nullable', 'boolean'],
            'valid_from' => ['nullable', 'date'],
            'valid_to' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'cover_image_url' => ['nullable', 'string', 'max:500'],
            'net_price' => ['required', 'numeric', 'gt:0'],
            'markup_percent' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'inclusions' => ['nullable', 'array'],
            'exclusions' => ['nullable', 'array'],
            'terms' => ['nullable', 'array'],
            'status' => ['nullable', 'in:'.implode(',', [
                HolidayPackage::STATUS_DRAFT, HolidayPackage::STATUS_ACTIVE,
                HolidayPackage::STATUS_PAUSED, HolidayPackage::STATUS_EXPIRED,
            ])],
            'itinerary_days' => ['nullable', 'array'],
            'itinerary_days.*.day_number' => ['required_with:itinerary_days', 'integer', 'min:1'],
            'itinerary_days.*.title' => ['required_with:itinerary_days', 'string', 'max:255'],
            'itinerary_days.*.description' => ['nullable', 'string'],
            'price_tiers' => ['nullable', 'array'],
            'price_tiers.*.occupancy_type' => ['required_with:price_tiers', 'in:adult_sharing,adult_single,child_with_bed,child_without_bed,infant'],
            'price_tiers.*.age_label' => ['nullable', 'string', 'max:100'],
            'price_tiers.*.net_price' => ['required_with:price_tiers', 'numeric', 'gt:0'],
            'price_tiers.*.markup_percent' => ['nullable', 'numeric', 'min:0'],
            'price_tiers.*.currency' => ['nullable', 'string', 'size:3'],
            'media' => ['nullable', 'array'],
            'media.*.media_type' => ['required_with:media', 'in:'.HolidayPackageMedia::TYPE_IMAGE.','.HolidayPackageMedia::TYPE_VIDEO],
            'media.*.url' => ['required_with:media', 'string', 'max:500'],
            'media.*.caption' => ['nullable', 'string', 'max:255'],
            'media.*.sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        if (! $user->isPlatformStaff()) {
            $data['tenant_id'] = $user->tenant_id;
        } elseif (! array_key_exists('tenant_id', $data)) {
            $data['tenant_id'] = $existing->tenant_id ?? null;
        }

        if (! empty($data['net_price']) && empty($data['sell_price'] ?? null)) {
            $data['sell_price'] = HolidayPackage::priceWithMarkup(
                $data['net_price'],
                $data['markup_percent'] ?? ($existing->markup_percent ?? HolidayPackage::DEFAULT_MARKUP_PERCENT)
            );
        }

        $itineraryDays = $data['itinerary_days'] ?? null;
        $priceTiers = $data['price_tiers'] ?? null;
        $media = $data['media'] ?? null;
        unset($data['itinerary_days'], $data['price_tiers'], $data['media']);

        return [
            'package' => $data,
            'itinerary_days' => $itineraryDays,
            'price_tiers' => $priceTiers,
            'media' => $media,
        ];
    }
}
