<?php

namespace App\Http\Controllers\Api;

use App\Domains\Booking\Models\Booking;
use App\Domains\Booking\Models\CancellationPolicy;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * The "CRM screen" the roadmap calls for — "Staff define these... rather
 * than in code" — for Phase 2's cancellation/refund bands. Deliberately
 * gated behind policy.manage rather than the more permissive pattern
 * CashbackRuleController/MarkupRuleController use (tenant self-service),
 * since a wrong band here directly changes how much money goes back to a
 * customer; policy.manage is only granted to staff/partner_admin (see
 * RolePermissionSeeder), not agent/sub_agent. Otherwise mirrors
 * CashbackRuleController's exact CRUD/authorization shape.
 */
class CancellationPolicyController extends Controller
{
    protected const MODULES = [
        Booking::MODULE_FLIGHT, Booking::MODULE_HOTEL, Booking::MODULE_BUS, Booking::MODULE_CAB,
        Booking::MODULE_HOLIDAY, Booking::MODULE_INSURANCE, Booking::MODULE_VISA, Booking::MODULE_CRUISE,
        Booking::MODULE_ACTIVITY,
    ];

    public function index(Request $request)
    {
        $user = $request->user();
        $this->authorize($user);

        $policies = CancellationPolicy::query()
            ->when(! $user->isPlatformStaff(), fn ($q) => $q->where('tenant_id', $user->tenant_id))
            ->orderByDesc('priority')
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 25));

        return response()->json($policies);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        $this->authorize($user);

        $data = $this->validated($request, $user);
        $policy = CancellationPolicy::query()->create($data);

        return response()->json(['data' => $policy], 201);
    }

    public function update(Request $request, CancellationPolicy $cancellationPolicy)
    {
        $user = $request->user();
        $this->authorize($user);
        $this->authorizeAccess($user, $cancellationPolicy);

        $data = $this->validated($request, $user, $cancellationPolicy);
        $cancellationPolicy->update($data);

        return response()->json(['data' => $cancellationPolicy->fresh()]);
    }

    public function destroy(Request $request, CancellationPolicy $cancellationPolicy)
    {
        $user = $request->user();
        $this->authorize($user);
        $this->authorizeAccess($user, $cancellationPolicy);

        $cancellationPolicy->delete();

        return response()->json(null, 204);
    }

    protected function authorize($user): void
    {
        abort_unless($user->hasPermission('policy.manage'), 403, 'Missing required permission: policy.manage');
    }

    protected function authorizeAccess($user, CancellationPolicy $policy): void
    {
        if ($user->isPlatformStaff()) {
            return;
        }

        abort_if($policy->tenant_id !== $user->tenant_id, 403, 'You cannot manage another tenant\'s cancellation policy.');
    }

    protected function validated(Request $request, $user, ?CancellationPolicy $existing = null): array
    {
        $data = $request->validate([
            'tenant_id' => ['nullable', 'integer', 'exists:tenants,id'],
            'name' => ['required', 'string', 'max:191'],
            'module' => ['nullable', 'in:'.implode(',', self::MODULES)],
            'booking_scope' => ['nullable', 'in:'.implode(',', CancellationPolicy::BOOKING_SCOPES)],
            'supplier_code' => ['nullable', 'string', 'max:40'],
            'origin' => ['nullable', 'string', 'max:10'],
            'destination' => ['nullable', 'string', 'max:10'],
            'bands' => ['required', 'array', 'min:1'],
            'bands.*.hours_before_min' => ['required', 'integer', 'min:0'],
            'bands.*.refund_percentage' => ['required', 'numeric', 'min:0', 'max:100'],
            'bands.*.flat_fee' => ['nullable', 'numeric', 'min:0'],
            'bands.*.label' => ['required', 'string', 'max:100'],
            'priority' => ['nullable', 'integer', 'min:1', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        foreach (['supplier_code', 'origin', 'destination'] as $field) {
            if (! empty($data[$field])) {
                $data[$field] = strtoupper(trim($data[$field]));
            }
        }

        if (isset($data['bands'])) {
            $data['bands'] = array_map(
                fn (array $band) => [
                    'hours_before_min' => (int) $band['hours_before_min'],
                    'refund_percentage' => number_format((float) $band['refund_percentage'], 2, '.', ''),
                    'flat_fee' => number_format((float) ($band['flat_fee'] ?? 0), 2, '.', ''),
                    'label' => $band['label'],
                ],
                $data['bands'],
            );
        }

        // Non-platform-staff can only ever manage their own tenant's
        // policies — never a platform-wide (tenant_id NULL) policy, and
        // never another tenant's.
        if (! $user->isPlatformStaff()) {
            $data['tenant_id'] = $user->tenant_id;
        } elseif (! array_key_exists('tenant_id', $data)) {
            $data['tenant_id'] = $existing->tenant_id ?? null;
        }

        return $data;
    }
}
