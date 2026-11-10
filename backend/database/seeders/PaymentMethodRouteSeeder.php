<?php

namespace Database\Seeders;

use App\Domains\Payments\Models\PaymentMethodRoute;
use Illuminate\Database\Seeder;

/**
 * Sensible platform-wide (tenant_id NULL) defaults for "which gateway
 * handles Credit card / Debit card / UPI / Netbanking / Wallets /
 * International cards" — so PaymentMethodRoutesPage has something real to
 * show and PaymentGatewayService::resolveGatewayForMethod() has a route to
 * resolve before any tenant touches the CRM screen. Any tenant (or platform
 * staff) can override a method with their own row via
 * PaymentMethodRouteController — see resolveGatewayForMethod()'s
 * tenant-first ordering.
 */
class PaymentMethodRouteSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            PaymentMethodRoute::METHOD_CREDIT_CARD => 'razorpay',
            PaymentMethodRoute::METHOD_DEBIT_CARD => 'razorpay',
            PaymentMethodRoute::METHOD_UPI => 'razorpay',
            PaymentMethodRoute::METHOD_NETBANKING => 'payu',
            PaymentMethodRoute::METHOD_WALLET => 'cashfree',
            PaymentMethodRoute::METHOD_INTERNATIONAL_CARD => 'stripe',
        ];

        foreach ($defaults as $method => $gateway) {
            PaymentMethodRoute::query()->firstOrCreate(
                ['tenant_id' => null, 'payment_method' => $method],
                ['gateway' => $gateway, 'is_active' => true, 'priority' => 1]
            );
        }

        $this->command?->info('Seeded default payment method -> gateway routes (credit/debit/upi -> Razorpay, netbanking -> PayU, wallet -> Cashfree, international card -> Stripe).');
    }
}
