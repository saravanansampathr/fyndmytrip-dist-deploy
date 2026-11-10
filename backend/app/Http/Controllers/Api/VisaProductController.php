<?php

namespace App\Http\Controllers\Api;

use App\Domains\Holiday\Models\HolidayPackage;
use App\Domains\Visa\Models\VisaProduct;
use App\Http\Controllers\Concerns\FiltersByDateRangePreset;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * Admin CRM for the visa product catalog — same tenant-scoping pattern as
 * HolidayPackageController/CashbackRuleController.
 */
class VisaProductController extends Controller
{
    use FiltersByDateRangePreset;

    public function index(Request $request)
    {
        $user = $request->user();

        $dateFilterActive = $request->filled('range') || $request->filled('from') || $request->filled('to');
        [$from, $to] = $dateFilterActive ? $this->resolveDateRangePreset($request) : [null, null];

        $products = VisaProduct::query()
            ->when(! $user->isPlatformStaff(), fn ($q) => $q->where('tenant_id', $user->tenant_id))
            ->when($request->filled('destination_country'), fn ($q) => $q->where('destination_country', strtoupper($request->string('destination_country'))))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($dateFilterActive, fn ($q) => $q->whereBetween('created_at', [$from, $to]))
            ->when($request->filled('q'), function ($q) use ($request) {
                $term = '%'.$request->string('q').'%';
                $q->where(function ($sub) use ($term) {
                    $sub->where('destination_country_name', 'like', $term)
                        ->orWhere('visa_type', 'like', $term)
                        ->orWhere('destination_country', 'like', $term);
                });
            })
            ->orderBy('destination_country_name')
            ->paginate(min($request->integer('per_page', 25), 500));

        return response()->json($products);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        $data = $this->validated($request, $user);

        $product = VisaProduct::query()->create($data);

        return response()->json(['data' => $product], 201);
    }

    public function update(Request $request, VisaProduct $visaProduct)
    {
        $user = $request->user();
        $this->authorizeAccess($user, $visaProduct);

        $data = $this->validated($request, $user, $visaProduct);
        $visaProduct->update($data);

        return response()->json(['data' => $visaProduct->fresh()]);
    }

    public function destroy(Request $request, VisaProduct $visaProduct)
    {
        $this->authorizeAccess($request->user(), $visaProduct);

        $visaProduct->delete();

        return response()->json(null, 204);
    }

    protected function authorizeAccess($user, VisaProduct $product): void
    {
        if ($user->isPlatformStaff()) {
            return;
        }

        abort_if($product->tenant_id !== $user->tenant_id, 403, 'You cannot manage another tenant\'s visa product.');
    }

    protected function validated(Request $request, $user, ?VisaProduct $existing = null): array
    {
        $data = $request->validate([
            'tenant_id' => ['nullable', 'integer', 'exists:tenants,id'],
            'supplier_code' => ['nullable', 'in:'.implode(',', [
                VisaProduct::SUPPLIER_AKBAR, VisaProduct::SUPPLIER_TRIPJACK, VisaProduct::SUPPLIER_MANUAL,
            ])],
            'supplier_product_ref' => ['nullable', 'string', 'max:191'],
            'destination_country' => ['required', 'string', 'size:2'],
            'destination_country_name' => ['required', 'string', 'max:191'],
            'visa_type' => ['required', 'string', 'max:191'],
            'processing_time_label' => ['nullable', 'string', 'max:100'],
            'entry_type' => ['nullable', 'in:single,multiple'],
            'validity_days' => ['nullable', 'integer', 'min:1'],
            'stay_days' => ['nullable', 'integer', 'min:1'],
            'net_price' => ['required', 'numeric', 'gt:0'],
            'markup_percent' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'documents_required' => ['nullable', 'array'],
            'documents_required.*' => ['string', 'max:191'],
            'included_services' => ['nullable', 'array'],
            'included_services.*' => ['string', 'max:191'],
            'excluded_services' => ['nullable', 'array'],
            'excluded_services.*' => ['string', 'max:191'],
            'notes' => ['nullable', 'string'],
            'status' => ['nullable', 'in:'.implode(',', [
                VisaProduct::STATUS_DRAFT, VisaProduct::STATUS_ACTIVE,
                VisaProduct::STATUS_PAUSED, VisaProduct::STATUS_EXPIRED,
            ])],
        ]);

        if (! $user->isPlatformStaff()) {
            $data['tenant_id'] = $user->tenant_id;
        } elseif (! array_key_exists('tenant_id', $data)) {
            $data['tenant_id'] = $existing->tenant_id ?? null;
        }

        if (! empty($data['net_price'])) {
            $data['sell_price'] = HolidayPackage::priceWithMarkup(
                $data['net_price'],
                $data['markup_percent'] ?? ($existing->markup_percent ?? HolidayPackage::DEFAULT_MARKUP_PERCENT)
            );
        }

        return $data;
    }
}
