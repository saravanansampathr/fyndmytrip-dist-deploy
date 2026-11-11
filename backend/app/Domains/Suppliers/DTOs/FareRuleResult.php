<?php

namespace App\Domains\Suppliers\DTOs;

/**
 * Result of a supplier's GetFareRules/PriceCheck call — re-confirms price
 * and availability immediately before hold, since supplier prices are only
 * ever cached for a short TTL (3-5 min per the System Architecture Document).
 */
final class FareRuleResult
{
    public function __construct(
        public readonly bool $isStillAvailable,
        public readonly string $confirmedNetAmount,
        public readonly bool $isRefundable,
        public readonly array $rules = [],
        /**
         * The airline's own taxes inside confirmedNetAmount, per fare unit
         * (same unit as confirmedNetAmount), as tax code => amount, e.g.
         * ['K3' => '256.00', 'YQ' => '300.00', 'OT' => '512.00']. Shown to
         * travellers as the airline tax breakup; empty when the supplier
         * doesn't send one.
         *
         * @var array<string, string>
         */
        public readonly array $taxBreakup = [],
    ) {}
}
