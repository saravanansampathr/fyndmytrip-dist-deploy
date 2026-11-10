<?php

namespace App\Http\Controllers\Api;

use App\Domains\Booking\Models\Booking;
use App\Domains\Suppliers\Models\MarkupRule;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * The CRM the feature request asked for: "Where is the option to keep the
 * markup for each Agents and Whitelable API, Either fixed per PNR or PAX
 * or Destination or percentage wise." A tenant (Agent, Sub-Agent,
 * White-Label Partner) manages its own outgoing markup rules — what it
 * charges its own direct customers (applies_to_role=b2c) and, if it
 * onboards its own agents/sub-agents, the ceiling it caps them at.
 * Platform staff can additionally manage any tenant's rules (e.g. Master
 * setting a platform-wide ceiling). MarkupService::applyMarkup() is what
 * actually resolves and applies these against a booking.
 */
class MarkupRuleController extends Controller
{
    protected const MODULES = [
        Booking::MODULE_FLIGHT, Booking::MODULE_HOTEL, Booking::MODULE_BUS, Booking::MODULE_CAB,
        Booking::MODULE_HOLIDAY, Booking::MODULE_INSURANCE, Booking::MODULE_VISA, Booking::MODULE_CRUISE,
        Booking::MODULE_ACTIVITY,
    ];

    public function index(Request $request)
    {
        $user = $request->user();

        $rules = MarkupRule::withoutTenantScope()
            ->when(! $user->isPlatformStaff(), fn ($q) => $q->where('tenant_id', $user->tenant_id))
            ->orderBy('module')
            ->orderBy('applies_to_role')
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['data' => $rules]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        $data = $this->validated($request, $user);

        $rule = MarkupRule::withoutTenantScope()->create($data);

        return response()->json(['data' => $rule], 201);
    }

    public function update(Request $request, MarkupRule $markupRule)
    {
        $user = $request->user();
        $this->authorizeAccess($user, $markupRule);

        $data = $this->validated($request, $user, $markupRule);
        $markupRule->update($data);

        return response()->json(['data' => $markupRule->fresh()]);
    }

    public function destroy(Request $request, MarkupRule $markupRule)
    {
        $user = $request->user();
        $this->authorizeAccess($user, $markupRule);

        $markupRule->delete();

        return response()->json(null, 204);
    }

    protected function authorizeAccess($user, MarkupRule $rule): void
    {
        if ($user->isPlatformStaff()) {
            return;
        }

        abort_if($rule->tenant_id !== $user->tenant_id, 403, 'You cannot manage another tenant\'s markup rule.');
    }

    protected function validated(Request $request, $user, ?MarkupRule $existing = null): array
    {
        $data = $request->validate([
            'tenant_id' => ['nullable', 'integer', 'exists:tenants,id'],
            'name' => ['nullable', 'string', 'max:191'],
            'module' => ['required', 'in:'.implode(',', self::MODULES)],
            'rule_type' => ['required', 'in:'.implode(',', MarkupRule::TYPES)],
            'applies_to_role' => ['required', 'in:'.implode(',', MarkupRule::ROLES)],
            'percentage' => ['required_if:rule_type,percentage', 'nullable', 'numeric', 'min:0', 'max:100'],
            'amount' => ['required_unless:rule_type,percentage', 'nullable', 'numeric', 'min:0'],
            'origin' => ['nullable', 'string', 'max:10'],
            'destination' => ['required_if:rule_type,flat_per_destination', 'nullable', 'string', 'max:10'],
            'booking_scope' => ['nullable', 'in:'.implode(',', MarkupRule::BOOKING_SCOPES)],
            'supplier_code' => ['nullable', 'string', 'max:40'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $config = $data['rule_type'] === MarkupRule::TYPE_PERCENTAGE
            ? ['percentage' => number_format((float) $data['percentage'], 2, '.', '')]
            : ['amount' => number_format((float) $data['amount'], 2, '.', '')];

        $result = [
            'module' => $data['module'],
            'rule_type' => $data['rule_type'],
            'applies_to_role' => $data['applies_to_role'],
            'config' => $config,
            'origin' => $data['rule_type'] === MarkupRule::TYPE_FLAT_PER_DESTINATION
                ? strtoupper($data['origin'] ?? '') ?: null
                : null,
            'destination' => $data['rule_type'] === MarkupRule::TYPE_FLAT_PER_DESTINATION
                ? strtoupper($data['destination']) : null,
            'name' => $data['name'] ?? null,
            'booking_scope' => $data['booking_scope'] ?? MarkupRule::SCOPE_BOTH,
            'supplier_code' => isset($data['supplier_code']) && trim($data['supplier_code']) !== ''
                ? strtoupper(trim($data['supplier_code'])) : null,
            'is_active' => $data['is_active'] ?? true,
        ];

        // Non-platform-staff can only ever manage their own tenant's rules
        // — never another tenant's, and platform staff explicitly pick
        // which tenant a rule belongs to (or leave it as the existing
        // rule's tenant on update).
        if (! $user->isPlatformStaff()) {
            $result['tenant_id'] = $user->tenant_id;
        } else {
            $result['tenant_id'] = $data['tenant_id'] ?? $existing->tenant_id ?? $user->tenant_id;
        }

        return $result;
    }
}
