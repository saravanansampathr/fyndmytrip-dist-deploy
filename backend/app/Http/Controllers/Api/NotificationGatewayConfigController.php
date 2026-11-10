<?php

namespace App\Http\Controllers\Api;

use App\Domains\Notifications\Models\NotificationGatewayConfig;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * The SMS / WhatsApp / Email gateway admin screen (Database Design
 * Document §9.1) — same "Bring Your Own Gateway" shape as
 * PaymentGatewayConfigController: a tenant may plug in its own MSG91/
 * Gupshup/Twilio/SES/etc keys per channel, falling back to the platform
 * default (tenant_id NULL, managed only by platform staff) when it
 * hasn't. One row per (tenant_id, channel) — a tenant sets exactly one
 * active provider per channel, matching how NotificationDispatcher (once
 * built) will resolve which provider to send through.
 */
class NotificationGatewayConfigController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $this->authorizeConfigure($user);

        $own = NotificationGatewayConfig::query()
            ->where('tenant_id', $user->tenant_id)
            ->get()
            ->keyBy('channel');

        $platform = NotificationGatewayConfig::query()
            ->whereNull('tenant_id')
            ->get()
            ->keyBy('channel');

        $providers = config('notification_gateways.providers', []);
        $credentialFields = config('notification_gateways.credential_fields', []);

        $data = collect(NotificationGatewayConfig::CHANNELS)->map(function (string $channel) use ($own, $platform, $providers, $credentialFields, $user) {
            $ownConfig = $own->get($channel);
            $platformConfig = $platform->get($channel);

            return [
                'channel' => $channel,
                'available_providers' => $providers[$channel] ?? [],
                'credential_fields' => $credentialFields,
                'own' => $ownConfig ? $this->masked($ownConfig) : null,
                'platform_configured' => (bool) $platformConfig,
                'platform_default' => $user->isPlatformStaff() && $platformConfig ? $this->masked($platformConfig) : null,
            ];
        });

        return response()->json(['data' => $data]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        $this->authorizeConfigure($user);

        $data = $request->validate([
            'channel' => ['required', 'in:'.implode(',', NotificationGatewayConfig::CHANNELS)],
            'provider' => ['required', 'string', 'max:50'],
            'target' => ['nullable', 'in:own,platform'],
            'credentials' => ['required', 'array', 'min:1'],
            'credentials.*' => ['nullable', 'string', 'max:2000'],
            'sender_id' => ['nullable', 'string', 'max:50'],
            'dlt_template_ids' => ['nullable', 'array'],
            'dlt_template_ids.*' => ['string', 'max:100'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $tenantId = $this->resolveTenantId($user, $data['target'] ?? 'own');

        $config = NotificationGatewayConfig::query()->updateOrCreate(
            ['tenant_id' => $tenantId, 'channel' => $data['channel']],
            [
                'provider' => $data['provider'],
                'credentials_encrypted' => array_filter($data['credentials'], fn ($v) => $v !== null && $v !== ''),
                'sender_id' => $data['sender_id'] ?? null,
                'dlt_template_ids' => $data['dlt_template_ids'] ?? null,
                'is_active' => $data['is_active'] ?? true,
            ],
        );

        return response()->json(['data' => $this->masked($config->fresh())], 201);
    }

    public function update(Request $request, NotificationGatewayConfig $notificationGatewayConfig)
    {
        $user = $request->user();
        $this->authorizeConfigure($user);
        $this->authorizeAccess($user, $notificationGatewayConfig);

        $data = $request->validate([
            'provider' => ['nullable', 'string', 'max:50'],
            'credentials' => ['nullable', 'array'],
            'credentials.*' => ['nullable', 'string', 'max:2000'],
            'sender_id' => ['nullable', 'string', 'max:50'],
            'dlt_template_ids' => ['nullable', 'array'],
            'dlt_template_ids.*' => ['string', 'max:100'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        if (array_key_exists('credentials', $data) && $data['credentials'] !== null) {
            // Merge rather than replace — see PaymentGatewayConfigController
            // for why (masked/blank secret fields must never wipe saved ones).
            $existing = $notificationGatewayConfig->credentials_encrypted ?? [];
            $incoming = array_filter($data['credentials'], fn ($v) => $v !== null && $v !== '');
            $data['credentials_encrypted'] = array_merge($existing, $incoming);
            unset($data['credentials']);
        }

        $notificationGatewayConfig->update($data);

        return response()->json(['data' => $this->masked($notificationGatewayConfig->fresh())]);
    }

    public function destroy(Request $request, NotificationGatewayConfig $notificationGatewayConfig)
    {
        $user = $request->user();
        $this->authorizeConfigure($user);
        $this->authorizeAccess($user, $notificationGatewayConfig);

        $notificationGatewayConfig->delete();

        return response()->json(null, 204);
    }

    protected function authorizeConfigure($user): void
    {
        if (! $user->hasPermission('notification_gateway.configure')) {
            abort(403, 'Missing required permission: notification_gateway.configure');
        }
    }

    protected function authorizeAccess($user, NotificationGatewayConfig $config): void
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

    protected function masked(NotificationGatewayConfig $config): array
    {
        return [
            'id' => $config->id,
            'channel' => $config->channel,
            'provider' => $config->provider,
            'tenant_id' => $config->tenant_id,
            'is_platform_default' => $config->tenant_id === null,
            'sender_id' => $config->sender_id,
            'dlt_template_ids' => $config->dlt_template_ids,
            'is_active' => $config->is_active,
            // Never echo credential values back — only which keys are on file.
            'configured_keys' => array_keys($config->credentials_encrypted ?? []),
            'updated_at' => $config->updated_at,
        ];
    }
}
