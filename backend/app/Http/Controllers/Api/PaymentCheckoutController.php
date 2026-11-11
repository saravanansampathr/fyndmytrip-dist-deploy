<?php

namespace App\Http\Controllers\Api;

use App\Domains\Booking\Models\Booking;
use App\Domains\Payments\Models\PaymentMethodRoute;
use App\Domains\Payments\Models\PaymentTransaction;
use App\Domains\Payments\Services\GatewayClient;
use App\Domains\Payments\Services\PaymentGatewayService;
use App\Domains\Tenancy\Models\Tenant;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\TenantMailBranding;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Real card/UPI payments. Before a booking's "pay" call, the website asks
 * here to open the gateway's payment page (Razorpay popup or Stripe
 * checkout). After the customer pays, the normal pay call goes through and
 * the payment is checked with the gateway before anything is confirmed.
 * Webhooks record payments even if the customer closes the browser, so
 * staff can see "paid but not booked" cases.
 */
class PaymentCheckoutController extends Controller
{
    public function __construct(protected PaymentGatewayService $payments, protected GatewayClient $client)
    {
    }

    public function create(Request $request)
    {
        $data = $request->validate([
            'booking_id' => ['required', 'integer'],
            'method' => ['required', 'string', 'in:'.implode(',', PaymentMethodRoute::METHODS)],
            'currency' => ['nullable', 'string', 'size:3'],
        ]);
        $user = $request->user();
        $booking = $this->bookingFor($user, (int) $data['booking_id']);

        abort_unless($booking->status === Booking::STATUS_HELD, 409, "Booking must be 'held' to pay; it is currently '{$booking->status}'.");

        $txn = PaymentTransaction::withoutTenantScope()->where('booking_id', $booking->id)
            ->where('status', PaymentTransaction::STATUS_INITIATED)->latest('id')->first();
        abort_if(! $txn, 409, 'No pending payment was found for this booking. Call confirm first.');

        $brand = TenantMailBranding::resolve($booking->tenant);
        $result = $this->payments->checkout($booking, $txn, $data['method'], $data['currency'] ?? null, [
            'reference' => $booking->booking_code ?? $booking->booking_reference,
            'brand_name' => $brand['brand_name'],
            'description' => ucfirst($booking->module).' booking '.($booking->booking_code ?? $booking->booking_reference),
            'email' => $booking->contact_email ?? $user->email,
            'phone' => $booking->contact_phone ?? $user->phone,
            'return_url' => $this->returnUrl($request),
        ]);

        return response()->json(['data' => $result + ['transaction_id' => $txn->id]]);
    }

    /** For the Stripe popup: has the customer finished paying? */
    public function status(Request $request, int $transaction)
    {
        $txn = PaymentTransaction::withoutTenantScope()->findOrFail($transaction);
        $this->bookingFor($request->user(), (int) $txn->booking_id);
        $config = $txn->gateway ? $this->client->configFor($txn->gateway, Tenant::find($txn->tenant_id)) : null;

        return response()->json(['data' => [
            'paid' => $txn->status === PaymentTransaction::STATUS_SUCCESS || ($config && $txn->gateway_order_id && $this->client->sessionPaid($config, $txn)),
        ]]);
    }

    public function webhook(Request $request, string $gateway)
    {
        abort_unless(in_array($gateway, GatewayClient::SUPPORTED, true), 404);
        $payload = $request->getContent();
        $event = json_decode($payload, true) ?: [];

        if ($gateway === 'razorpay') {
            $entity = $event['payload']['payment']['entity'] ?? [];
            $orderId = $entity['order_id'] ?? ($event['payload']['order']['entity']['id'] ?? null);
            $paymentId = $entity['id'] ?? null;
            $paid = in_array($event['event'] ?? '', ['payment.captured', 'order.paid'], true);
            $signature = $request->header('X-Razorpay-Signature');
        } else {
            $object = $event['data']['object'] ?? [];
            $orderId = $object['id'] ?? null;
            $paymentId = $object['payment_intent'] ?? null;
            $paid = ($event['type'] ?? '') === 'checkout.session.completed' && ($object['payment_status'] ?? '') === 'paid';
            $signature = $request->header('Stripe-Signature');
        }

        $txn = $orderId ? PaymentTransaction::withoutTenantScope()->where('gateway_order_id', $orderId)->first() : null;
        if (! $txn) {
            return response()->json(['received' => true]); // not ours, or a test ping
        }

        $config = $this->client->configFor($gateway, Tenant::find($txn->tenant_id));
        $secret = (string) ($config?->credentials_encrypted['webhook_secret'] ?? '');
        if (! $this->client->verifyWebhook($gateway, $payload, $signature, $secret)) {
            Log::warning("Rejected {$gateway} webhook for order {$orderId}: bad or missing signature.");

            return response()->json(['message' => 'Invalid signature.'], 400);
        }

        if ($paid && $paymentId) {
            $raw = $txn->raw_gateway_response ?? [];
            $raw['webhook'] = ['payment_id' => $paymentId, 'event' => $event['event'] ?? $event['type'] ?? null, 'at' => now()->toIso8601String()];
            $txn->update(['raw_gateway_response' => $raw, 'gateway_payment_id' => $txn->gateway_payment_id ?: $paymentId]);
        }

        return response()->json(['received' => true]);
    }

    protected function bookingFor(User $user, int $bookingId): Booking
    {
        $booking = Booking::query()->with('tenant')->findOrFail($bookingId);
        if ($user->role === User::ROLE_CUSTOMER) {
            abort_unless($booking->created_by_user_id === $user->id, 403, 'That booking is not under your account.');
        }

        return $booking;
    }

    /** Where Stripe sends the popup back to: the site the customer is on, if it's one of ours. */
    protected function returnUrl(Request $request): string
    {
        $origin = rtrim((string) $request->headers->get('Origin'), '/');
        $host = parse_url($origin, PHP_URL_HOST);
        $allowed = $host && (
            in_array($origin, (array) config('cors.allowed_origins', []), true)
            || $host === parse_url((string) config('app.frontend_url'), PHP_URL_HOST)
            || Tenant::query()->where('custom_domain', $host)->exists()
        );

        return ($allowed ? $origin : rtrim((string) config('app.frontend_url'), '/')).'/payment-return.html';
    }
}
