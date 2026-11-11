<?php

namespace App\Domains\Suppliers\DTOs;

final class SupplierBalance
{
    public function __construct(
        public readonly string $amount,
        public readonly string $currency,
    ) {}
}
