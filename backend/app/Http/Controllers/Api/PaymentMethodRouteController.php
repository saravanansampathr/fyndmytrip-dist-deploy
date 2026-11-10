<?php

namespace App\Http\Controllers\Api;

use App\Domains\Payments\Models\PaymentMethodRoute;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * The "which payment gateway handles Credit card / Debit card / UPI /
 * Netbanking / Wallets / International cards" CRM screen. Mirrors
 * CashbackRuleController's tenant-scoping pattern exactly: partner admins
 * manage only their own tenant's routes, platform staff can additionally
 * manage platform-wide defaults (tenant_id NULL). PaymentGatewayService::
 * resolveGatewayForMethod() is what actually reads these at checkout time.
 */
class PaymentMethodRouteController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $routes = PaymentMethodRoute::query()
            ->when(! $user->isPlatformStaff(), fn ($q) => $q->where('tenant_id', $user->tenant_id))
            ->orderBy('payment_method')
            ->orderBy('priority')
            ->paginate($request->integer('per_page', 25));

        return response()->json($routes);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        $data = $this->validated($request, $user);

        $route = PaymentMethodRoute::query()->create($data);

        return response()->json(['data' => $route], 201);
    }

    public function update(Request $request, PaymentMethodRoute $paymentMethodRoute)
    {
        $user = $request->user();
        $this->authorizeAccess($user, $paymentMethodRoute);

        $data = $this->validated($request, $user, $paymentMethodRoute);
        $paymentMethodRoute->update($data);

        return response()->json(['data' => $paymentMethodRoute->fresh()]);
    }

    public function destroy(Request $request, PaymentMethodRoute $paymentMethodRoute)
    {
        $user = $request->user();
        $this->authorizeAccess($user, $paymentMethodRoute);

        $paymentMethodRoute->delete();

        return response()->json(null, 204);
    }

    protected function authorizeAccess($user, PaymentMethodRoute $route): void
    {
        if ($user->isPlatformStaff()) {
            return;
        }

        abort_if($route->tenant_id !== $user->tenant_id, 403, 'You cannot manage another tenant\'s payment method route.');
    }

    protected function validated(Request $request, $user, ?PaymentMethodRoute $existing = null): array
    {
        $data = $request->validate([
            'tenant_id' => ['nullable', 'integer', 'exists:tenants,id'],
            'payment_method' => ['required', 'in:'.implode(',', PaymentMethodRoute::METHODS)],
            'gateway' => ['required', 'in:'.implode(',', PaymentMethodRoute::GATEWAYS)],
            'is_active' => ['nullable', 'boolean'],
            'priority' => ['nullable', 'integer', 'min:1', 'max:255'],
        ]);

        // Same rule as CashbackRuleController: non-platform-staff can only
        // ever manage their own tenant's routes, never a platform-wide
        // (tenant_id NULL) default and never another tenant's.
        if (! $user->isPlatformStaff()) {
            $data['tenant_id'] = $user->tenant_id;
        } elseif (! array_key_exists('tenant_id', $data)) {
            $data['tenant_id'] = $existing->tenant_id ?? null;
        }

        return $data;
    }
}
