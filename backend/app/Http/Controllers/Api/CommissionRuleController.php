<?php

namespace App\Http\Controllers\Api;

use App\Domains\Booking\Models\Booking;
use App\Domains\Commission\Models\CommissionRule;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * Platform Roadmap Phase 7 — "Commission tracking per channel... needs to
 * be visible separately [from markup], not blended into one 'margin'
 * number." The CRM for CommissionRule, mirroring MarkupRuleController
 * exactly: a tenant manages its own outgoing commission rules — what it
 * pays the agents/sub-agents/white-label operators/API partners under it
 * for the business they bring in. Platform staff can additionally manage
 * any tenant's rules (e.g. Master setting a platform-wide default).
 * CommissionService::applyCommission() is what actually resolves and
 * credits these against a just-ticketed booking.
 */
class CommissionRuleController extends Controller
{
    protected const MODULES = [
        Booking::MODULE_FLIGHT, Booking::MODULE_HOTEL, Booking::MODULE_BUS, Booking::MODULE_CAB,
        Booking::MODULE_HOLIDAY, Booking::MODULE_INSURANCE, Booking::MODULE_VISA, Booking::MODULE_CRUISE,
        Booking::MODULE_ACTIVITY,
    ];

    public function index(Request $request)
    {
        $user = $request->user();

        $rules = CommissionRule::withoutTenantScope()
            ->when(! $user->isPlatformStaff(), fn ($q) => $q->where('tenant_id', $user->tenant_id))
            ->orderBy('applies_to_type')
            ->orderBy('module')
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['data' => $rules]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        $data = $this->validated($request, $user);

        $rule = CommissionRule::withoutTenantScope()->create($data);

        return response()->json(['data' => $rule], 201);
    }

    public function update(Request $request, CommissionRule $commissionRule)
    {
        $user = $request->user();
        $this->authorizeAccess($user, $commissionRule);

        $data = $this->validated($request, $user, $commissionRule);
        $commissionRule->update($data);

        return response()->json(['data' => $commissionRule->fresh()]);
    }

    public function destroy(Request $request, CommissionRule $commissionRule)
    {
        $user = $request->user();
        $this->authorizeAccess($user, $commissionRule);

        $commissionRule->delete();

        return response()->json(null, 204);
    }

    protected function authorizeAccess($user, CommissionRule $rule): void
    {
        if ($user->isPlatformStaff()) {
            return;
        }

        abort_if($rule->tenant_id !== $user->tenant_id, 403, 'You cannot manage another tenant\'s commission rule.');
    }

    protected function validated(Request $request, $user, ?CommissionRule $existing = null): array
    {
        $data = $request->validate([
            'tenant_id' => ['nullable', 'integer', 'exists:tenants,id'],
            'name' => ['nullable', 'string', 'max:191'],
            'module' => ['nullable', 'in:'.implode(',', self::MODULES)],
            'rule_type' => ['required', 'in:'.implode(',', CommissionRule::TYPES)],
            'applies_to_type' => ['required', 'in:'.implode(',', CommissionRule::APPLIES_TO_TYPES)],
            'percentage' => ['required_if:rule_type,percentage', 'nullable', 'numeric', 'min:0', 'max:100'],
            'amount' => ['required_unless:rule_type,percentage', 'nullable', 'numeric', 'min:0'],
            'max_commission_amount' => ['nullable', 'numeric', 'min:0'],
            'booking_scope' => ['nullable', 'in:'.implode(',', CommissionRule::BOOKING_SCOPES)],
            'is_active' => ['nullable', 'boolean'],
            'valid_from' => ['nullable', 'date'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:valid_from'],
        ]);

        $config = $data['rule_type'] === CommissionRule::TYPE_PERCENTAGE
            ? ['percentage' => number_format((float) $data['percentage'], 2, '.', '')]
            : ['amount' => number_format((float) $data['amount'], 2, '.', '')];

        $result = [
            'module' => $data['module'] ?? null,
            'rule_type' => $data['rule_type'],
            'applies_to_type' => $data['applies_to_type'],
            'config' => $config,
            'max_commission_amount' => isset($data['max_commission_amount'])
                ? number_format((float) $data['max_commission_amount'], 2, '.', '') : null,
            'name' => $data['name'] ?? null,
            'booking_scope' => $data['booking_scope'] ?? CommissionRule::SCOPE_BOTH,
            'is_active' => $data['is_active'] ?? true,
            'valid_from' => $data['valid_from'] ?? null,
            'valid_until' => isset($data['valid_until']) ? (strlen($data['valid_until']) <= 10 ? \Carbon\Carbon::parse($data['valid_until'])->endOfDay() : $data['valid_until']) : null,
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
