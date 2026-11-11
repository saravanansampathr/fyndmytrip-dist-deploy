<?php

namespace App\Http\Controllers\Api;

use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Webhooks\Models\WebhookDelivery;
use App\Domains\Webhooks\Models\WebhookEndpoint;
use App\Domains\Webhooks\Services\WebhookDispatcher;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Partners' own webhook URLs (Developer → Webhooks): which booking events
 * to receive, a signing secret shown once, a test send and a delivery log.
 */
class WebhookController extends Controller
{
    public function index(Request $request)
    {
        $tenant = $this->tenant($request);

        return response()->json(['data' => WebhookEndpoint::query()->where('tenant_id', $tenant->id)->latest('id')->get()->map(fn ($e) => $this->present($e)), 'events' => WebhookDispatcher::EVENTS]);
    }

    public function store(Request $request)
    {
        $tenant = $this->tenant($request);
        $data = $this->validated($request, true);
        $secret = 'whsec_'.Str::random(40);
        $e = WebhookEndpoint::query()->create(['tenant_id' => $tenant->id, 'url' => $data['url'], 'events' => $data['events'], 'secret' => $secret, 'is_active' => true]);

        return response()->json(['data' => $this->present($e) + ['secret' => $secret], 'message' => 'Save the signing secret now; it is shown only once.'], 201);
    }

    public function update(Request $request, WebhookEndpoint $webhook)
    {
        $this->own($request, $webhook);
        $data = $this->validated($request, false);
        $extra = [];
        if (! empty($data['rotate_secret'])) {
            $extra['secret'] = $webhook->secret = 'whsec_'.Str::random(40);
        }
        $webhook->fill(array_intersect_key($data, array_flip(['url', 'events', 'is_active'])));
        if (($data['is_active'] ?? false) === true) {
            $webhook->failures_in_a_row = 0;
        }
        $webhook->save();

        return response()->json(['data' => $this->present($webhook) + $extra]);
    }

    public function destroy(Request $request, WebhookEndpoint $webhook)
    {
        $this->own($request, $webhook);
        $webhook->delete();

        return response()->json(['message' => 'Webhook removed.']);
    }

    /** Sends a sample "booking.confirmed" event now. */
    public function test(Request $request, WebhookEndpoint $webhook, WebhookDispatcher $dispatcher)
    {
        $this->own($request, $webhook);
        $d = WebhookDelivery::query()->create([
            'webhook_endpoint_id' => $webhook->id, 'event' => 'booking.confirmed', 'payload' => [
                'event' => 'booking.confirmed', 'test' => true, 'occurred_at' => now()->toIso8601String(),
                'data' => ['booking_id' => 0, 'booking_code' => 'TEST000000', 'service' => 'flight', 'status' => 'confirmed', 'total_amount' => '0.00', 'currency' => 'INR'],
            ], 'status' => 'pending', 'next_attempt_at' => null,
        ]);
        $ok = $dispatcher->deliver($d);

        return response()->json(['data' => ['delivered' => $ok, 'response_code' => $d->fresh()->response_code, 'error' => $d->fresh()->error]]);
    }

    public function deliveries(Request $request, WebhookEndpoint $webhook)
    {
        $this->own($request, $webhook);

        return response()->json(['data' => $webhook->deliveries()->latest('id')->limit(100)->get(['id', 'event', 'booking_id', 'status', 'attempts', 'response_code', 'error', 'next_attempt_at', 'created_at'])]);
    }

    public function retry(Request $request, WebhookDelivery $delivery, WebhookDispatcher $dispatcher)
    {
        $this->own($request, $delivery->endpoint);
        $ok = $dispatcher->deliver($delivery);

        return response()->json(['data' => ['delivered' => $ok, 'status' => $delivery->fresh()->status]]);
    }

    protected function present(WebhookEndpoint $e): array
    {
        return ['id' => $e->id, 'url' => $e->url, 'events' => $e->events, 'is_active' => $e->is_active, 'failures_in_a_row' => $e->failures_in_a_row,
            'last_success_at' => $e->last_success_at?->toIso8601String(), 'created_at' => $e->created_at?->toIso8601String()];
    }

    protected function validated(Request $request, bool $creating): array
    {
        $data = $request->validate([
            'url' => [$creating ? 'required' : 'sometimes', 'url', 'max:500'],
            'events' => [$creating ? 'required' : 'sometimes', 'array', 'min:1'],
            'events.*' => [Rule::in(array_keys(WebhookDispatcher::EVENTS))],
            'is_active' => ['sometimes', 'boolean'],
            'rotate_secret' => ['sometimes', 'boolean'],
        ]);
        if (isset($data['url'])) {
            self::assertPublicUrl($data['url']);
        }

        return $data;
    }

    /** Only public https addresses (never the server's own network). */
    public static function assertPublicUrl(string $url): void
    {
        $p = parse_url($url);
        $host = $p['host'] ?? '';
        $fail = fn ($m) => throw ValidationException::withMessages(['url' => [$m]]);
        if (($p['scheme'] ?? '') !== 'https' && ! app()->environment('local', 'testing')) {
            $fail('Use an https:// address.');
        }
        if (app()->environment('testing')) {
            return;
        }
        $records = filter_var($host, FILTER_VALIDATE_IP) ? [] : (@dns_get_record($host, DNS_A | DNS_AAAA) ?: []);
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : array_filter(array_merge(array_column($records, 'ip'), array_column($records, 'ipv6')));
        if (! $ips) {
            $fail('That address could not be found.');
        }
        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                $fail('Use a public internet address.');
            }
        }
    }

    protected function tenant(Request $request): Tenant
    {
        $user = $request->user();
        if ($user->isPlatformStaff() && $request->filled('tenant_id')) {
            return Tenant::query()->findOrFail($request->integer('tenant_id'));
        }
        abort_unless($user->isPlatformStaff() || in_array($user->role, [User::ROLE_PARTNER_ADMIN, User::ROLE_AGENT, User::ROLE_API_USER], true), 403, 'Only account admins and API partners can manage webhooks.');

        return $user->tenant;
    }

    protected function own(Request $request, ?WebhookEndpoint $e): void
    {
        abort_unless($e, 404);
        $user = $request->user();
        abort_unless($user->isPlatformStaff() || ($e->tenant_id === $user->tenant_id && in_array($user->role, [User::ROLE_PARTNER_ADMIN, User::ROLE_AGENT, User::ROLE_API_USER], true)), 404);
    }
}
