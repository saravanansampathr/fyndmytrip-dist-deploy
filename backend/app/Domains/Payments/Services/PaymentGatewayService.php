<?php

namespace App\Domains\Payments\Services;

use App\Domains\Booking\Models\Booking;
use App\Domains\Payments\Models\PaymentMethodRoute;
use App\Domains\Payments\Models\PaymentTransaction;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Wallet\Models\Wallet;
use Illuminate\Support\Str;

/**
 * International bookings must never be paid by a direct wallet debit —
 * they route through here instead (see FlightBookingController::confirm()/
 * payGateway()). No real Razorpay/PayU/Cashfree/CCAvenue/Stripe
 * integration exists yet (same "assume success" placeholder pattern
 * WalletController::topup() already uses for the demo wallet top-up), so
 * this simulates the initiate -> pick a method -> webhook-confirms
 * lifecycle a real gateway would drive, against the real
 * PaymentTransaction / PaymentGatewayConfig / PaymentMethodRoute models so
 * swapping in a live gateway later only means replacing settle()'s
 * "assume success" body with a real webhook handler.
 *
 * Which PSP actually processes a payment is decided in two steps: at
 * confirm() time we only know the amount, not yet how the customer will
 * pay, so initiate() opens the transaction with gateway/method still NULL;
 * once the customer picks a method on the gateway step (credit card, UPI,
 * ...), settle() resolves the configured gateway for that specific method
 * — see PaymentMethodRoute / resolveGatewayForMethod() — and pins it on
 * the transaction. This is what "which gateway handles Credit card / UPI /
 * Netbanking / Wallets / International cards" (configured per tenant in
 * PaymentMethodRouteController) actually drives.
 */
class PaymentGatewayService
{
    /**
     * Reuses an existing pending transaction for this booking rather than
     * creating a duplicate if the customer's checkout step calls
     * confirm() more than once (e.g. a re-submitted click) before paying.
     *
     * $amount is what the gateway itself will settle — the *remainder*
     * after any wallet amount the customer chose to apply (see
     * FlightBookingController::confirm()'s wallet_amount handling). When
     * $walletAmount is greater than zero the wallet leg isn't debited here:
     * it's recorded on the transaction and only actually debited once
     * settle() succeeds, inside the same DB transaction — see
     * FlightBookingController::payGateway() — so a customer who never
     * completes the gateway step never has their wallet touched for a
     * booking that didn't go through.
     */
    public function initiate(Booking $booking, string $amount, string $walletAmount = '0.00', ?Wallet $wallet = null): PaymentTransaction
    {
        $existing = PaymentTransaction::query()
            ->where('booking_id', $booking->id)
            ->where('status', PaymentTransaction::STATUS_INITIATED)
            ->first();

        if ($existing) {
            $existing->update([
                'amount' => $amount,
                'wallet_amount' => $walletAmount,
                'wallet_id' => $wallet?->id,
            ]);

            return $existing;
        }

        return PaymentTransaction::query()->create([
            'booking_id' => $booking->id,
            'wallet_id' => $wallet?->id,
            'wallet_amount' => $walletAmount,
            'tenant_id' => $booking->tenant_id,
            'gateway' => null,
            'gateway_txn_id' => 'DEMO-'.strtoupper(Str::random(12)),
            'amount' => $amount,
            'currency' => $booking->currency,
            'status' => PaymentTransaction::STATUS_INITIATED,
            'method' => null,
        ]);
    }

    /**
     * Marks the pending transaction successful once a payment method is
     * chosen — the "webhook fired" moment a real integration would drive
     * from the gateway's server-to-server callback. See
     * FlightBookingController::payGateway().
     */
    public function settle(PaymentTransaction $transaction, Tenant $tenant, string $method): PaymentTransaction
    {
        $transaction->update([
            'status' => PaymentTransaction::STATUS_SUCCESS,
            'method' => $method,
            'gateway' => $this->resolveGatewayForMethod($tenant, $method),
        ]);

        return $transaction;
    }

    /**
     * One {method: label, gateway} row per known payment method, for the
     * frontend's method picker to show which gateway each option will
     * actually route to (falls back to the platform default gateway for
     * any method nobody has configured a route for yet, so the demo works
     * before anyone has touched the CRM).
     *
     * @return array<int, array{method: string, gateway: string}>
     */
    public function availableMethods(Tenant $tenant): array
    {
        return array_map(
            fn (string $method) => ['method' => $method, 'gateway' => $this->resolveGatewayForMethod($tenant, $method)],
            PaymentMethodRoute::METHODS,
        );
    }

    public function resolveGatewayForMethod(Tenant $tenant, string $method): string
    {
        $route = PaymentMethodRoute::query()
            ->where('payment_method', $method)
            ->where('is_active', true)
            ->where(fn ($q) => $q->where('tenant_id', $tenant->id)->orWhereNull('tenant_id'))
            ->orderByDesc('tenant_id') // tenant's own routing choice wins over the platform-wide default
            ->orderBy('priority')
            ->first();

        return $route->gateway ?? $this->resolveDefaultGateway($tenant);
    }

    protected function resolveDefaultGateway(Tenant $tenant): string
    {
        $config = \App\Domains\Payments\Models\PaymentGatewayConfig::query()
            ->where(fn ($q) => $q->where('tenant_id', $tenant->id)->orWhereNull('tenant_id'))
            ->where('is_active', true)
            ->orderByDesc('tenant_id')
            ->orderBy('priority')
            ->first();

        return $config->gateway ?? config('services.payment_default.gateway', 'razorpay');
    }
}
