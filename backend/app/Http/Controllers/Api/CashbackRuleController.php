<?php

namespace App\Http\Controllers\Api;

use App\Domains\Booking\Models\Booking;
use App\Domains\Cashback\Models\CashbackRule;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * The "CRM" for cashback rules the feature request asked for: partner
 * admins manage their own tenant's rules, platform staff can additionally
 * see/create platform-wide rules (tenant_id NULL, same convention as
 * Coupon and PaymentGatewayConfig). CashbackService::bestRuleFor() is what
 * actually applies these against a confirmed booking.
 */
class CashbackRuleController extends Controller
{
    protected const MODULES = [
        Booking::MODULE_FLIGHT, Booking::MODULE_HOTEL, Booking::MODULE_BUS, Booking::MODULE_CAB,
        Booking::MODULE_HOLIDAY, Booking::MODULE_INSURANCE, Booking::MODULE_VISA, Booking::MODULE_CRUISE,
        Booking::MODULE_ACTIVITY,
    ];

    public function index(Request $request)
    {
        $user = $request->user();

        $rules = CashbackRule::query()
            ->when(! $user->isPlatformStaff(), fn ($q) => $q->where('tenant_id', $user->tenant_id))
            ->orderByDesc('priority')
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 25));

        return response()->json($rules);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        $data = $this->validated($request, $user);

        $rule = CashbackRule::query()->create($data);

        return response()->json(['data' => $rule], 201);
    }

    public function update(Request $request, CashbackRule $cashbackRule)
    {
        $user = $request->user();
        $this->authorizeAccess($user, $cashbackRule);

        $data = $this->validated($request, $user, $cashbackRule);
        $cashbackRule->update($data);

        return response()->json(['data' => $cashbackRule->fresh()]);
    }

    public function destroy(Request $request, CashbackRule $cashbackRule)
    {
        $user = $request->user();
        $this->authorizeAccess($user, $cashbackRule);

        $cashbackRule->delete();

        return response()->json(null, 204);
    }

    protected function authorizeAccess($user, CashbackRule $rule): void
    {
        if ($user->isPlatformStaff()) {
            return;
        }

        abort_if($rule->tenant_id !== $user->tenant_id, 403, 'You cannot manage another tenant\'s cashback rule.');
    }

    protected function validated(Request $request, $user, ?CashbackRule $existing = null): array
    {
        $data = $request->validate([
            'tenant_id' => ['nullable', 'integer', 'exists:tenants,id'],
            'name' => ['required', 'string', 'max:191'],
            'applicable_modules' => ['nullable', 'array'],
            'applicable_modules.*' => ['in:'.implode(',', self::MODULES)],
            'airline_code' => ['nullable', 'string', 'max:100'],
            'origin_country' => ['nullable', 'string', 'size:2'],
            'destination_country' => ['nullable', 'string', 'size:2'],
            'booking_scope' => ['nullable', 'in:domestic,international,both'],
            'valid_from' => ['nullable', 'date'],
            'valid_to' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'min_duration_days' => ['nullable', 'integer', 'min:0'],
            'max_duration_days' => ['nullable', 'integer', 'min:0', 'gte:min_duration_days'],
            'calc_type' => ['required', 'in:flat,percentage'],
            'value' => ['required', 'numeric', 'gt:0'],
            'max_cashback_amount' => ['nullable', 'numeric', 'gt:0'],
            'priority' => ['nullable', 'integer', 'min:1', 'max:255'],
            'status' => ['nullable', 'in:draft,active,paused,expired'],
        ]);

        if (isset($data['origin_country'])) {
            $data['origin_country'] = strtoupper($data['origin_country']);
        }

        if (isset($data['destination_country'])) {
            $data['destination_country'] = strtoupper($data['destination_country']);
        }

        // Non-platform-staff can only ever manage their own tenant's rules
        // — never a platform-wide (tenant_id NULL) rule, and never another
        // tenant's.
        if (! $user->isPlatformStaff()) {
            $data['tenant_id'] = $user->tenant_id;
        } elseif (! array_key_exists('tenant_id', $data)) {
            $data['tenant_id'] = $existing->tenant_id ?? null;
        }

        return $data;
    }
}
