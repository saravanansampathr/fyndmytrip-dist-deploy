<?php

namespace App\Http\Controllers\Api;

use App\Domains\Payments\Models\PaymentGatewayConfig;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * "Bring Your Own Gateway" admin screen (Database Design Document §6.1):
 * lets a tenant admin plug in their own Razorpay/PayU/Cashfree/CCAvenue/
 * Stripe keys — falling back to the platform default (tenant_id NULL) when
 * they haven't — and lets platform staff manage that platform default
 * itself. Mirrors SupplierConnectionController's masking/merge conventions
 * (never echo secrets back, merge credentials on update rather than
 * replace) adapted for a fixed gateway catalog instead of a suppliers
 * table, and for the extra NULL-tenant row only platform staff may touch.
 */
class PaymentGatewayConfigController extends Controller
{
    /**
     * One row per gateway in the catalog, annotated with the acting
     * tenant's own config (if any) and, for platform staff only, the
     * platform-default config too. Everyone else just sees whether a
     * platform default exists, never its credentials.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $this->authorizeConfigure($user);

        $own = PaymentGatewayConfig::query()
            ->where('tenant_id', $user->tenant_id)
            ->get()
            ->keyBy('gateway');

        $platform = PaymentGatewayConfig::query()
            ->whereNull('tenant_id')
            ->get()
            ->keyBy('gateway');

        $labels = config('payment_gateways.labels', []);
        $credentialFields = config('payment_gateways.credential_fields', []);

        $data = collect(PaymentGatewayConfig::GATEWAYS)->map(function (string $gateway) use ($own, $platform, $labels, $credentialFields, $user) {
            $ownConfig = $own->get($gateway);
            $platformConfig = $platform->get($gateway);

            return [
                'gateway' => $gateway,
                'label' => $labels[$gateway] ?? ucfirst($gateway),
                'credential_fields' => $credentialFields[$gateway] ?? [],
                'own' => $ownConfig ? $this->masked($ownConfig) : null,
                'platform_configured' => (bool) $platformConfig,
                'platform_default' => $user->isPlatformStaff() && $platformConfig ? $this->masked($platformConfig) : null,
            ];
        });

        return response()->json(['data' => $data]);
    }

    /**
     * Connect (or reconnect) a gateway. 'target' picks which row: 'own'
     * (default) is the acting tenant's override; 'platform' is the
     * NULL-tenant platform default and is only permitted for platform
     * staff (App\Models\User::isPlatformStaff()).
     */
    public function store(Request $request)
    {
        $user = $request->user();
        $this->authorizeConfigure($user);

        $data = $request->validate([
            'gateway' => ['required', 'in:'.implode(',', PaymentGatewayConfig::GATEWAYS)],
            'target' => ['nullable', 'in:own,platform'],
            'credentials' => ['required', 'array', 'min:1'],
            'credentials.*' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['nullable', 'boolean'],
            'priority' => ['nullable', 'integer', 'min:1', 'max:255'],
        ]);

        $tenantId = $this->resolveTenantId($user, $data['target'] ?? 'own');

        $config = PaymentGatewayConfig::query()->updateOrCreate(
            ['tenant_id' => $tenantId, 'gateway' => $data['gateway']],
            [
                'credentials_encrypted' => array_filter($data['credentials'], fn ($v) => $v !== null && $v !== ''),
                'is_active' => $data['is_active'] ?? true,
                'priority' => $data['priority'] ?? 1,
            ],
        );

        return response()->json(['data' => $this->masked($config->fresh())], 201);
    }

    public function update(Request $request, PaymentGatewayConfig $paymentGatewayConfig)
    {
        $user = $request->user();
        $this->authorizeConfigure($user);
        $this->authorizeAccess($user, $paymentGatewayConfig);

        $data = $request->validate([
            'credentials' => ['nullable', 'array'],
            'credentials.*' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['nullable', 'boolean'],
            'priority' => ['nullable', 'integer', 'min:1', 'max:255'],
        ]);

        if (array_key_exists('credentials', $data) && $data['credentials'] !== null) {
            // Merge rather than replace so re-saving "Active"/"Priority"
            // alone (with masked/blank secret fields left untouched by the
            // frontend) never wipes previously-saved secrets.
            $existing = $paymentGatewayConfig->credentials_encrypted ?? [];
            $incoming = array_filter($data['credentials'], fn ($v) => $v !== null && $v !== '');
            $data['credentials_encrypted'] = array_merge($existing, $incoming);
            unset($data['credentials']);
        }

        $paymentGatewayConfig->update($data);

        return response()->json(['data' => $this->masked($paymentGatewayConfig->fresh())]);
    }

    public function destroy(Request $request, PaymentGatewayConfig $paymentGatewayConfig)
    {
        $user = $request->user();
        $this->authorizeConfigure($user);
        $this->authorizeAccess($user, $paymentGatewayConfig);

        $paymentGatewayConfig->delete();

        return response()->json(null, 204);
    }

    protected function authorizeConfigure($user): void
    {
        if (! $user->hasPermission('payment_gateway.configure')) {
            abort(403, 'Missing required permission: payment_gateway.configure');
        }
    }

    protected function authorizeAccess($user, PaymentGatewayConfig $config): void
    {
        if ($config->tenant_id === null) {
            abort_unless($user->isPlatformStaff(), 403, 'Only platform staff can manage the platform default gateway.');

            return;
        }

        abort_if($config->tenant_id !== $user->tenant_id, 403, 'You cannot manage another tenant\'s gateway config.');
    }

    protected function resolveTenantId($user, string $target): ?int
    {
        if ($target === 'platform') {
            abort_unless($user->isPlatformStaff(), 403, 'Only platform staff can manage the platform default gateway.');

            return null;
        }

        return $user->tenant_id;
    }

    protected function masked(PaymentGatewayConfig $config): array
    {
        return [
            'id' => $config->id,
            'gateway' => $config->gateway,
            'tenant_id' => $config->tenant_id,
            'is_platform_default' => $config->tenant_id === null,
            'is_active' => $config->is_active,
            'priority' => $config->priority,
            // Never echo credential values back — only which keys are
            // already on file, so the form can show "•••• saved" per field.
            'configured_keys' => array_keys($config->credentials_encrypted ?? []),
            'updated_at' => $config->updated_at,
        ];
    }
}
