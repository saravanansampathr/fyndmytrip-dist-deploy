<?php

namespace App\Domains\Payments\Services;

use App\Domains\Payments\Exceptions\PaymentNotVerifiedException;
use App\Domains\Payments\Models\PaymentGatewayConfig;
use App\Domains\Payments\Models\PaymentTransaction;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * The real Razorpay and Stripe calls: open an order / checkout session,
 * check a payment really happened, refund it, and verify webhooks. Keys
 * come from Admin → Payment gateways; test keys (rzp_test_, sk_test_)
 * work end to end without moving real money.
 */
class GatewayClient
{
    public const SUPPORTED = ['razorpay', 'stripe'];

    /** Currencies charged in whole units (no paise/cents). */
    protected const ZERO_DECIMAL = ['JPY', 'KRW', 'VND'];

    /** The active config with usable keys for this gateway, tenant's own first. */
    public function configFor(string $gateway, ?Tenant $tenant): ?PaymentGatewayConfig
    {
        if (! in_array($gateway, self::SUPPORTED, true)) {
            return null;
        }

        $rows = PaymentGatewayConfig::query()->where('gateway', $gateway)->where('is_active', true)
            ->where(fn ($q) => $q->where('tenant_id', $tenant?->id ?? 0)->orWhereNull('tenant_id'))
            ->orderByDesc('tenant_id')->orderBy('priority')->get();

        return $rows->first(fn ($c) => $this->hasKeys($c));
    }

    public function hasKeys(PaymentGatewayConfig $config): bool
    {
        $c = $config->credentials_encrypted ?? [];

        return match ($config->gateway) {
            'razorpay' => str_starts_with((string) ($c['key_id'] ?? ''), 'rzp_') && ! empty($c['key_secret']),
            'stripe' => str_starts_with((string) ($c['secret_key'] ?? ''), 'sk_'),
            default => false,
        };
    }

    public function isLive(PaymentGatewayConfig $config): bool
    {
        $c = $config->credentials_encrypted ?? [];

        return str_starts_with((string) ($c['key_id'] ?? $c['secret_key'] ?? ''), $config->gateway === 'stripe' ? 'sk_live_' : 'rzp_live_');
    }

    public static function minorUnits(float $amount, string $currency): int
    {
        return in_array(strtoupper($currency), self::ZERO_DECIMAL, true) ? (int) round($amount) : (int) round($amount * 100);
    }

    /**
     * @return array<string, mixed> what the browser needs to show the gateway's own payment page
     */
    public function openCheckout(PaymentGatewayConfig $config, PaymentTransaction $txn, array $context): array
    {
        $c = $config->credentials_encrypted;
        $amount = (float) ($txn->charge_amount ?? $txn->amount);
        $currency = strtoupper($txn->charge_currency ?? $txn->currency);

        if ($config->gateway === 'razorpay') {
            $res = Http::withBasicAuth($c['key_id'], $c['key_secret'])->acceptJson()->post('https://api.razorpay.com/v1/orders', [
                'amount' => self::minorUnits($amount, $currency),
                'currency' => $currency,
                'receipt' => substr((string) $context['reference'], 0, 40),
                'notes' => ['booking_id' => (string) $txn->booking_id, 'transaction_id' => (string) $txn->id],
            ]);
            $this->ensureOk($res, 'Razorpay');

            return [
                'mode' => 'razorpay',
                'order_id' => $res->json('id'),
                'key_id' => $c['key_id'],
                'amount' => self::minorUnits($amount, $currency),
                'currency' => $currency,
                'name' => $context['brand_name'],
                'description' => $context['description'],
                'prefill' => ['email' => $context['email'] ?? '', 'contact' => $context['phone'] ?? ''],
            ];
        }

        $res = Http::withToken($c['secret_key'])->asForm()->post('https://api.stripe.com/v1/checkout/sessions', [
            'mode' => 'payment',
            'success_url' => $context['return_url'].'?status=success&session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $context['return_url'].'?status=cancelled',
            'customer_email' => $context['email'] ?: null,
            'client_reference_id' => (string) $txn->id,
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => strtolower($currency),
                    'unit_amount' => self::minorUnits($amount, $currency),
                    'product_data' => ['name' => $context['description']],
                ],
            ]],
            'metadata' => ['booking_id' => (string) $txn->booking_id, 'transaction_id' => (string) $txn->id],
            'payment_intent_data' => ['metadata' => ['booking_id' => (string) $txn->booking_id, 'transaction_id' => (string) $txn->id]],
        ]);
        $this->ensureOk($res, 'Stripe');

        return [
            'mode' => 'stripe',
            'order_id' => $res->json('id'),
            'checkout_url' => $res->json('url'),
            'publishable_key' => $c['publishable_key'] ?? null,
            'amount' => self::minorUnits($amount, $currency),
            'currency' => $currency,
        ];
    }

    /**
     * Confirms with the gateway that this transaction's order was paid in
     * full. Returns the gateway's payment id.
     *
     * @throws PaymentNotVerifiedException
     */
    public function verify(PaymentGatewayConfig $config, PaymentTransaction $txn, array $input): string
    {
        $c = $config->credentials_encrypted;
        $expected = self::minorUnits((float) ($txn->charge_amount ?? $txn->amount), $txn->charge_currency ?? $txn->currency);
        $webhook = $txn->raw_gateway_response['webhook'] ?? null;

        if ($config->gateway === 'razorpay') {
            $paymentId = (string) ($input['razorpay_payment_id'] ?? '');
            $signature = (string) ($input['razorpay_signature'] ?? '');

            if ($paymentId && $signature) {
                $valid = hash_equals(hash_hmac('sha256', $txn->gateway_order_id.'|'.$paymentId, $c['key_secret']), $signature);
                if (! $valid) {
                    throw new PaymentNotVerifiedException('The payment signature did not match. No booking was made; if money was taken it will be refunded.');
                }
            } elseif (! empty($webhook['payment_id'])) {
                $paymentId = $webhook['payment_id'];
            } else {
                throw new PaymentNotVerifiedException('The payment has not been completed yet.');
            }

            $res = Http::withBasicAuth($c['key_id'], $c['key_secret'])->acceptJson()->get("https://api.razorpay.com/v1/payments/{$paymentId}");
            $this->ensureOk($res, 'Razorpay');
            if ($res->json('order_id') !== $txn->gateway_order_id || (int) $res->json('amount') < $expected
                || ! in_array($res->json('status'), ['captured', 'authorized'], true)) {
                throw new PaymentNotVerifiedException('Razorpay has not confirmed this payment.');
            }
            if ($res->json('status') === 'authorized') {
                // Accounts without auto-capture: capture now so it settles.
                $cap = Http::withBasicAuth($c['key_id'], $c['key_secret'])->acceptJson()->post("https://api.razorpay.com/v1/payments/{$paymentId}/capture", [
                    'amount' => (int) $res->json('amount'), 'currency' => $res->json('currency'),
                ]);
                $this->ensureOk($cap, 'Razorpay');
            }

            return $paymentId;
        }

        $res = Http::withToken($c['secret_key'])->acceptJson()->get("https://api.stripe.com/v1/checkout/sessions/{$txn->gateway_order_id}");
        $this->ensureOk($res, 'Stripe');
        if ($res->json('payment_status') !== 'paid' || (int) $res->json('amount_total') < $expected) {
            throw new PaymentNotVerifiedException('Stripe has not confirmed this payment yet.');
        }

        return (string) ($res->json('payment_intent') ?: $txn->gateway_order_id);
    }

    /** Status of a Stripe checkout session (for the popup to poll). */
    public function sessionPaid(PaymentGatewayConfig $config, PaymentTransaction $txn): bool
    {
        if ($config->gateway !== 'stripe') {
            return ! empty($txn->raw_gateway_response['webhook']['payment_id']);
        }
        $res = Http::withToken($config->credentials_encrypted['secret_key'])->acceptJson()->get("https://api.stripe.com/v1/checkout/sessions/{$txn->gateway_order_id}");

        return $res->successful() && $res->json('payment_status') === 'paid';
    }

    /**
     * Refunds part or all of a payment, in the currency it was charged in.
     * Returns the gateway's refund id.
     */
    public function refund(PaymentGatewayConfig $config, PaymentTransaction $txn, float $amountInBookingCurrency): string
    {
        $c = $config->credentials_encrypted;
        $rate = (float) ($txn->fx_rate ?: 1);
        $currency = $txn->charge_currency ?? $txn->currency;
        $minor = min(
            self::minorUnits($amountInBookingCurrency * $rate, $currency),
            self::minorUnits((float) ($txn->charge_amount ?? $txn->amount), $currency),
        );

        if ($config->gateway === 'razorpay') {
            $res = Http::withBasicAuth($c['key_id'], $c['key_secret'])->acceptJson()
                ->post("https://api.razorpay.com/v1/payments/{$txn->gateway_payment_id}/refund", ['amount' => $minor, 'speed' => 'normal']);
            $this->ensureOk($res, 'Razorpay');

            return (string) $res->json('id');
        }

        $res = Http::withToken($c['secret_key'])->asForm()->post('https://api.stripe.com/v1/refunds', [
            'payment_intent' => $txn->gateway_payment_id,
            'amount' => $minor,
        ]);
        $this->ensureOk($res, 'Stripe');

        return (string) $res->json('id');
    }

    /** Checks a webhook really came from the gateway (signature with the webhook secret). */
    public function verifyWebhook(string $gateway, string $payload, ?string $signatureHeader, string $secret): bool
    {
        if (! $signatureHeader || $secret === '') {
            return false;
        }

        if ($gateway === 'razorpay') {
            return hash_equals(hash_hmac('sha256', $payload, $secret), $signatureHeader);
        }

        // Stripe: "t=timestamp,v1=signature", signed over "timestamp.payload".
        $parts = [];
        foreach (explode(',', $signatureHeader) as $kv) {
            [$k, $v] = array_pad(explode('=', trim($kv), 2), 2, '');
            $parts[$k][] = $v;
        }
        $t = $parts['t'][0] ?? null;
        if (! $t || abs(time() - (int) $t) > 600) {
            return false;
        }
        $expected = hash_hmac('sha256', $t.'.'.$payload, $secret);
        foreach ($parts['v1'] ?? [] as $sig) {
            if (hash_equals($expected, $sig)) {
                return true;
            }
        }

        return false;
    }

    protected function ensureOk($response, string $gateway): void
    {
        if ($response->failed()) {
            $msg = $response->json('error.description') ?? $response->json('error.message') ?? mb_substr($response->body(), 0, 300);
            throw new RuntimeException("{$gateway}: {$msg}");
        }
    }
}
