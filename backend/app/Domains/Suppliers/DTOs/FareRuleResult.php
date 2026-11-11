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
    ) {}
}
